<?php

declare(strict_types=1);

namespace Stancl\Tenancy\Tests;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Events\CallQueuedListener;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Str;
use Stancl\JobPipeline\JobPipeline;
use Stancl\Tenancy\Bootstrappers\DatabaseTenancyBootstrapper;
use Stancl\Tenancy\Contracts\Syncable;
use Stancl\Tenancy\Contracts\SyncMaster;
use Stancl\Tenancy\Database\Concerns\CentralConnection;
use Stancl\Tenancy\Database\Concerns\ResourceSyncing;
use Stancl\Tenancy\Database\Models\TenantPivot;
use Stancl\Tenancy\DatabaseConfig;
use Stancl\Tenancy\Events\SyncedResourceChangedInForeignDatabase;
use Stancl\Tenancy\Events\SyncedResourceSaved;
use Stancl\Tenancy\Events\TenancyEnded;
use Stancl\Tenancy\Events\TenancyInitialized;
use Stancl\Tenancy\Events\TenantCreated;
use Stancl\Tenancy\Exceptions\ModelNotSyncMasterException;
use Stancl\Tenancy\Jobs\CreateDatabase;
use Stancl\Tenancy\Listeners\BootstrapTenancy;
use Stancl\Tenancy\Listeners\RevertToCentralContext;
use Stancl\Tenancy\Listeners\UpdateSyncedResource;
use Stancl\Tenancy\Tests\Etc\Tenant;

class ResourceSyncingTest extends TestCase
{
    public function setUp(): void
    {
        parent::setUp();

        config(['tenancy.bootstrappers' => [
            DatabaseTenancyBootstrapper::class,
        ]]);

        Event::listen(TenantCreated::class, JobPipeline::make([CreateDatabase::class])->send(function (TenantCreated $event) {
            return $event->tenant;
        })->toListener());

        DatabaseConfig::generateDatabaseNamesUsing(function () {
            return 'db' . Str::random(16);
        });

        Event::listen(TenancyInitialized::class, BootstrapTenancy::class);
        Event::listen(TenancyEnded::class, RevertToCentralContext::class);

        UpdateSyncedResource::$shouldQueue = false; // global state cleanup
        Event::listen(SyncedResourceSaved::class, UpdateSyncedResource::class);

        $this->artisan('migrate', [
            '--path' => [
                __DIR__ . '/Etc/synced_resource_migrations',
                __DIR__ . '/Etc/synced_resource_migrations/users',
            ],
            '--realpath' => true,
        ])->assertExitCode(0);
    }

    protected function migrateTenants()
    {
        $this->artisan('tenants:migrate', [
            '--path' => __DIR__ . '/Etc/synced_resource_migrations/users',
            '--realpath' => true,
        ])->assertExitCode(0);
    }

    /** @test */
    public function an_event_is_triggered_when_a_synced_resource_is_changed()
    {
        Event::fake([SyncedResourceSaved::class]);

        $user = ResourceUser::create([
            'name' => 'Foo',
            'email' => 'foo@email.com',
            'password' => 'secret',
            'global_id' => 'foo',
            'role' => 'foo',
        ]);

        Event::assertDispatched(SyncedResourceSaved::class, function (SyncedResourceSaved $event) use ($user) {
            return $event->model === $user;
        });
    }

    /** @test */
    public function only_the_synced_columns_are_updated_in_the_central_db()
    {
        // Create user in central DB
        $user = CentralUser::create([
            'global_id' => 'acme',
            'name' => 'John Doe',
            'email' => 'john@localhost',
            'password' => 'secret',
            'role' => 'superadmin', // unsynced
        ]);

        $tenant = ResourceTenant::create();
        $this->migrateTenants();

        tenancy()->initialize($tenant);

        // Create the same user in tenant DB
        $user = ResourceUser::create([
            'global_id' => 'acme',
            'name' => 'John Doe',
            'email' => 'john@localhost',
            'password' => 'secret',
            'role' => 'commenter', // unsynced
        ]);

        // Update user in tenant DB
        $user->update([
            'name' => 'John Foo', // synced
            'email' => 'john@foreignhost', // synced
            'role' => 'admin', // unsynced
        ]);

        // Assert new values
        $this->assertEquals([
            'id' => 1,
            'global_id' => 'acme',
            'name' => 'John Foo',
            'email' => 'john@foreignhost',
            'password' => 'secret',
            'role' => 'admin',
        ], $user->getAttributes());

        tenancy()->end();

        // Assert changes bubbled up
        $this->assertEquals([
            'id' => 1,
            'global_id' => 'acme',
            'name' => 'John Foo', // synced
            'email' => 'john@foreignhost', // synced
            'password' => 'secret', // no changes
            'role' => 'superadmin', // unsynced
        ], ResourceUser::first()->getAttributes());
    }

    // This tests attribute list on the central side, and default values on the tenant side
// Those two don't depend on each other, we're just testing having each option on each side
// using tests that combine the two, to avoid having an excessively long and complex test suite
test('sync resource creation works when central model provides attributes and resource model provides default values', function () {
    [$tenant1, $tenant2] = createTenantsAndRunMigrations();

    addExtraColumnToCentralDB();

    $centralUser = CentralUserProvidingAttributeNames::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter',
        'foo' => 'bar', // foo does not exist in resource model
    ]);

    $tenant1->run(function () {
        expect(ResourceUserProvidingDefaultValues::all())->toHaveCount(0);
    });

    // When central model provides the list of attributes, resource model will be created from the provided list of attributes' values
    $centralUser->tenants()->attach('t1');

    $tenant1->run(function () {
        $resourceUser = ResourceUserProvidingDefaultValues::all();
        expect($resourceUser)->toHaveCount(1);
        expect($resourceUser->first()->global_id)->toBe('acme');
        expect($resourceUser->first()->email)->toBe('john@localhost');
        // 'foo' attribute is not provided by central model
        expect($resourceUser->first()->foo)->toBeNull();
    });

    tenancy()->initialize($tenant2);

    // When resource model provides the list of default values, central model will be created from the provided list of default values
    ResourceUserProvidingDefaultValues::create([
        'global_id' => 'asdf',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter',
    ]);

    tenancy()->end();

    // Assert central user was created using the list of default values
    $centralUser = CentralUserProvidingAttributeNames::whereGlobalId('asdf')->first();
    expect($centralUser)->not()->toBeNull();
    expect($centralUser->name)->toBe('Default Name');
    expect($centralUser->email)->toBe('default@localhost');
    expect($centralUser->password)->toBe('password');
    expect($centralUser->role)->toBe('admin');
    expect($centralUser->foo)->toBe('bar');
});

// This tests default values on the central side, and attribute list on the tenant side
// Those two don't depend on each other, we're just testing having each option on each side
// using tests that combine the two, to avoid having an excessively long and complex test suite
test('sync resource creation works when central model provides default values and resource model provides attributes', function () {
    [$tenant1, $tenant2] = createTenantsAndRunMigrations();

    addExtraColumnToCentralDB();

    $centralUser = CentralUserProvidingDefaultValues::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter',
        'foo' => 'bar', // foo does not exist in resource model
    ]);

    $tenant1->run(function () {
        expect(ResourceUserProvidingDefaultValues::all())->toHaveCount(0);
    });

    // When central model provides the list of default values, resource model will be created from the provided list of default values
    $centralUser->tenants()->attach('t1');

    $tenant1->run(function () {
        // Assert resource user was created using the list of default values
        $resourceUser = ResourceUserProvidingDefaultValues::first();
        expect($resourceUser)->not()->toBeNull();
        expect($resourceUser->global_id)->toBe('acme');
        expect($resourceUser->email)->toBe('default@localhost');
        expect($resourceUser->password)->toBe('password');
        expect($resourceUser->role)->toBe('admin');
    });

    tenancy()->initialize($tenant2);

    // When resource model provides the list of attributes, central model will be created from the provided list of attributes' values
    ResourceUserProvidingAttributeNames::create([
        'global_id' => 'asdf',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'secret',
        'role' => 'commenter',
    ]);

    tenancy()->end();

    // Assert central user was created using the list of provided attributes
    $centralUser = CentralUserProvidingAttributeNames::whereGlobalId('asdf')->first();
    expect($centralUser)->not()->toBeNull();
    expect($centralUser->email)->toBe('john@localhost');
    expect($centralUser->password)->toBe('secret');
    expect($centralUser->role)->toBe('commenter');
});

// This tests mixed attribute list/defaults on the central side, and no specified attributes on the tenant side
// Those two don't depend on each other, we're just testing having each option on each side
// using tests that combine the two, to avoid having an excessively long and complex test suite
test('sync resource creation works when central model provides mixture and resource model provides nothing', function () {
    [$tenant1, $tenant2] = createTenantsAndRunMigrations();

    $centralUser = CentralUserProvidingMixture::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'password',
        'role' => 'commentator'
    ]);

    $tenant1->run(function () {
        expect(ResourceUser::all())->toHaveCount(0);
    });

    // When central model provides the list of a mixture (attributes and default values), resource model will be created from the provided list of mixture (attributes and default values)
    $centralUser->tenants()->attach('t1');

    $tenant1->run(function () {
        $resourceUser = ResourceUser::first();

        // Assert resource user was created using the provided attributes and default values
        expect($resourceUser->global_id)->toBe('acme');
        expect($resourceUser->name)->toBe('John Doe');
        expect($resourceUser->email)->toBe('john@localhost');
        // default values
        expect($resourceUser->role)->toBe('admin');
        expect($resourceUser->password)->toBe('secret');
    });

    tenancy()->initialize($tenant2);

    // When resource model provides nothing/null, the central model will be created as a 1:1 copy of resource model
    $resourceUser = ResourceUser::create([
        'global_id' => 'acmey',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'password',
        'role' => 'commentator'
    ]);

    tenancy()->end();

    $centralUser = CentralUserProvidingMixture::whereGlobalId('acmey')->first();
    expect($resourceUser->getSyncedCreationAttributes())->toBeNull();

    $centralUser = $centralUser->toArray();
    $resourceUser = $resourceUser->toArray();
    unset($centralUser['id']);
    unset($resourceUser['id']);

    // Assert central user created as 1:1 copy of resource model except "id"
    expect($centralUser)->toBe($resourceUser);
});

// This tests no specified attributes on the central side, and mixed attribute list/defaults on the tenant side
// Those two don't depend on each other, we're just testing having each option on each side
// using tests that combine the two, to avoid having an excessively long and complex test suite
test('sync resource creation works when central model provides nothing and resource model provides mixture', function () {
    [$tenant1, $tenant2] = createTenantsAndRunMigrations();

    $centralUser = CentralUser::create([
        'global_id' => 'acme',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'password',
        'role' => 'commenter',
    ]);

    $tenant1->run(function () {
        expect(ResourceUserProvidingMixture::all())->toHaveCount(0);
    });

    // When central model provides nothing/null, the resource model will be created as a 1:1 copy of central model
    $centralUser->tenants()->attach('t1');

    expect($centralUser->getSyncedCreationAttributes())->toBeNull();
    $tenant1->run(function () use ($centralUser) {
        $resourceUser = ResourceUserProvidingMixture::first();
        expect($resourceUser)->not()->toBeNull();
        $resourceUser = $resourceUser->toArray();
        $centralUser = $centralUser->withoutRelations()->toArray();
        unset($resourceUser['id']);
        unset($centralUser['id']);

        expect($resourceUser)->toBe($centralUser);
    });

    tenancy()->initialize($tenant2);

    // When resource model provides the list of a mixture (attributes and default values), central model will be created from the provided list of mixture (attributes and default values)
    ResourceUserProvidingMixture::create([
        'global_id' => 'absd',
        'name' => 'John Doe',
        'email' => 'john@localhost',
        'password' => 'password',
        'role' => 'commenter',
    ]);

    tenancy()->end();

    $centralUser = CentralUser::whereGlobalId('absd')->first();

    // Assert central user was created using the provided list of attributes and default values
    expect($centralUser->name)->toBe('John Doe');
    expect($centralUser->email)->toBe('john@localhost');
    // default values
    expect($centralUser->role)->toBe('admin');
    expect($centralUser->password)->toBe('secret');
});

    /** @test */
    public function creating_the_resource_in_tenant_database_creates_it_in_central_database_and_creates_the_mapping()
    {
        // Assert no user in central DB
        $this->assertCount(0, ResourceUser::all());

        $tenant = ResourceTenant::create();
        $this->migrateTenants();

        tenancy()->initialize($tenant);

        // Create the same user in tenant DB
        ResourceUser::create([
            'global_id' => 'acme',
            'name' => 'John Doe',
            'email' => 'john@localhost',
            'password' => 'secret',
            'role' => 'commenter', // unsynced
        ]);

        tenancy()->end();

        // Asset user was created
        $this->assertSame('acme', CentralUser::first()->global_id);
        $this->assertSame('commenter', CentralUser::first()->role);

        // Assert mapping was created
        $this->assertCount(1, CentralUser::first()->tenants);

        // Assert role change doesn't cascade
        CentralUser::first()->update(['role' => 'central superadmin']);
        tenancy()->initialize($tenant);
        $this->assertSame('commenter', ResourceUser::first()->role);
    }

    /** @test */
    public function trying_to_update_synced_resources_from_central_context_using_tenant_models_results_in_an_exception()
    {
        $this->creating_the_resource_in_tenant_database_creates_it_in_central_database_and_creates_the_mapping();

        tenancy()->end();
        $this->assertFalse(tenancy()->initialized);

        $this->expectException(ModelNotSyncMasterException::class);
        ResourceUser::first()->update(['role' => 'foobar']);
    }

    /** @test */
    public function attaching_a_tenant_to_the_central_resource_triggers_a_pull_from_the_tenant_db()
    {
        $centralUser = CentralUser::create([
            'global_id' => 'acme',
            'name' => 'John Doe',
            'email' => 'john@localhost',
            'password' => 'secret',
            'role' => 'commenter', // unsynced
        ]);

        $tenant = ResourceTenant::create([
            'id' => 't1',
        ]);
        $this->migrateTenants();

        $tenant->run(function () {
            $this->assertCount(0, ResourceUser::all());
        });

        $centralUser->tenants()->attach('t1');

        $tenant->run(function () {
            $this->assertCount(1, ResourceUser::all());
        });
    }

    /** @test */
    public function attaching_users_to_tenants_DOES_NOT_DO_ANYTHING()
    {
        $centralUser = CentralUser::create([
            'global_id' => 'acme',
            'name' => 'John Doe',
            'email' => 'john@localhost',
            'password' => 'secret',
            'role' => 'commenter', // unsynced
        ]);

        $tenant = ResourceTenant::create([
            'id' => 't1',
        ]);
        $this->migrateTenants();

        $tenant->run(function () {
            $this->assertCount(0, ResourceUser::all());
        });

        // The child model is inaccessible in the Pivot Model, so we can't fire any events.
        $tenant->users()->attach($centralUser);

        $tenant->run(function () {
            // Still zero
            $this->assertCount(0, ResourceUser::all());
        });
    }

    /** @test */
    public function resources_are_synced_only_to_workspaces_that_have_the_resource()
    {
        $centralUser = CentralUser::create([
            'global_id' => 'acme',
            'name' => 'John Doe',
            'email' => 'john@localhost',
            'password' => 'secret',
            'role' => 'commenter', // unsynced
        ]);

        $t1 = ResourceTenant::create([
            'id' => 't1',
        ]);

        $t2 = ResourceTenant::create([
            'id' => 't2',
        ]);

        $t3 = ResourceTenant::create([
            'id' => 't3',
        ]);
        $this->migrateTenants();

        $centralUser->tenants()->attach('t1');
        $centralUser->tenants()->attach('t2');
        // t3 is not attached

        $t1->run(function () {
            // assert user exists
            $this->assertCount(1, ResourceUser::all());
        });

        $t2->run(function () {
            // assert user exists
            $this->assertCount(1, ResourceUser::all());
        });

        $t3->run(function () {
            // assert user does NOT exist
            $this->assertCount(0, ResourceUser::all());
        });
    }

    /** @test */
    public function when_a_resource_exists_in_other_tenant_dbs_but_is_CREATED_in_a_tenant_db_the_synced_columns_are_updated_in_the_other_dbs()
    {
        // create shared resource
        $centralUser = CentralUser::create([
            'global_id' => 'acme',
            'name' => 'John Doe',
            'email' => 'john@localhost',
            'password' => 'secret',
            'role' => 'commenter', // unsynced
        ]);

        $t1 = ResourceTenant::create([
            'id' => 't1',
        ]);
        $t2 = ResourceTenant::create([
            'id' => 't2',
        ]);
        $this->migrateTenants();

        // Copy (cascade) user to t1 DB
        $centralUser->tenants()->attach('t1');

        $t2->run(function () {
            // Create user with the same global ID in t2 database
            ResourceUser::create([
                'global_id' => 'acme',
                'name' => 'John Foo', // changed
                'email' => 'john@foo', // changed
                'password' => 'secret',
                'role' => 'superadmin', // unsynced
            ]);
        });

        $centralUser = CentralUser::first();
        $this->assertSame('John Foo', $centralUser->name); // name changed
        $this->assertSame('john@foo', $centralUser->email); // email changed
        $this->assertSame('commenter', $centralUser->role); // role didn't change

        $t1->run(function () {
            $user = ResourceUser::first();
            $this->assertSame('John Foo', $user->name); // name changed
            $this->assertSame('john@foo', $user->email); // email changed
            $this->assertSame('commenter', $user->role); // role didn't change, i.e. is the same as from the original copy from central
        });
    }

    /** @test */
    public function the_synced_columns_are_updated_in_other_tenant_dbs_where_the_resource_exists()
    {
        // create shared resource
        $centralUser = CentralUser::create([
            'global_id' => 'acme',
            'name' => 'John Doe',
            'email' => 'john@localhost',
            'password' => 'secret',
            'role' => 'commenter', // unsynced
        ]);

        $t1 = ResourceTenant::create([
            'id' => 't1',
        ]);
        $t2 = ResourceTenant::create([
            'id' => 't2',
        ]);
        $t3 = ResourceTenant::create([
            'id' => 't3',
        ]);
        $this->migrateTenants();

        // Copy (cascade) user to t1 DB
        $centralUser->tenants()->attach('t1');
        $centralUser->tenants()->attach('t2');
        $centralUser->tenants()->attach('t3');

        $t3->run(function () {
            ResourceUser::first()->update([
                'name' => 'John 3',
                'role' => 'employee', // unsynced
            ]);

            $this->assertSame('employee', ResourceUser::first()->role);
        });

        // Check that change was cascaded to other tenants
        $t1->run($check = function () {
            $user = ResourceUser::first();

            $this->assertSame('John 3', $user->name); // synced
            $this->assertSame('commenter', $user->role); // unsynced
        });
        $t2->run($check);

        // Check that change bubbled up to central DB
        $this->assertSame(1, CentralUser::count());
        $centralUser = CentralUser::first();
        $this->assertSame('John 3', $centralUser->name); // synced
        $this->assertSame('commenter', $centralUser->role); // unsynced
    }

    /** @test */
    public function global_id_is_generated_using_id_generator_when_its_not_supplied()
    {
        $user = CentralUser::create([
            'name' => 'John Doe',
            'email' => 'john@doe',
            'password' => 'secret',
            'role' => 'employee',
        ]);

        $this->assertNotNull($user->global_id);
    }

    /** @test */
    public function when_the_resource_doesnt_exist_in_the_tenant_db_non_synced_columns_will_cascade_too()
    {
        $centralUser = CentralUser::create([
            'name' => 'John Doe',
            'email' => 'john@doe',
            'password' => 'secret',
            'role' => 'employee',
        ]);

        $t1 = ResourceTenant::create([
            'id' => 't1',
        ]);

        $this->migrateTenants();

        $centralUser->tenants()->attach('t1');

        $t1->run(function () {
            $this->assertSame('employee', ResourceUser::first()->role);
        });
    }

    /** @test */
    public function when_the_resource_doesnt_exist_in_the_central_db_non_synced_columns_will_bubble_up_too()
    {
        $t1 = ResourceTenant::create([
            'id' => 't1',
        ]);

        $this->migrateTenants();

        $t1->run(function () {
            ResourceUser::create([
                'name' => 'John Doe',
                'email' => 'john@doe',
                'password' => 'secret',
                'role' => 'employee',
            ]);
        });

        $this->assertSame('employee', CentralUser::first()->role);
    }

    /** @test */
    public function the_listener_can_be_queued()
    {
        Queue::fake();
        UpdateSyncedResource::$shouldQueue = true;

        $t1 = ResourceTenant::create([
            'id' => 't1',
        ]);

        $this->migrateTenants();

        Queue::assertNothingPushed();

        $t1->run(function () {
            ResourceUser::create([
                'name' => 'John Doe',
                'email' => 'john@doe',
                'password' => 'secret',
                'role' => 'employee',
            ]);
        });

        Queue::assertPushed(CallQueuedListener::class, function (CallQueuedListener $job) {
            return $job->class === UpdateSyncedResource::class;
        });
    }

    /** @test */
    public function an_event_is_fired_for_all_touched_resources()
    {
        Event::fake([SyncedResourceChangedInForeignDatabase::class]);

        // create shared resource
        $centralUser = CentralUser::create([
            'global_id' => 'acme',
            'name' => 'John Doe',
            'email' => 'john@localhost',
            'password' => 'secret',
            'role' => 'commenter', // unsynced
        ]);

        $t1 = ResourceTenant::create([
            'id' => 't1',
        ]);
        $t2 = ResourceTenant::create([
            'id' => 't2',
        ]);
        $t3 = ResourceTenant::create([
            'id' => 't3',
        ]);
        $this->migrateTenants();

        // Copy (cascade) user to t1 DB
        $centralUser->tenants()->attach('t1');
        Event::assertDispatched(SyncedResourceChangedInForeignDatabase::class, function (SyncedResourceChangedInForeignDatabase $event) {
            return $event->tenant->getTenantKey() === 't1';
        });

        $centralUser->tenants()->attach('t2');
        Event::assertDispatched(SyncedResourceChangedInForeignDatabase::class, function (SyncedResourceChangedInForeignDatabase $event) {
            return $event->tenant->getTenantKey() === 't2';
        });

        $centralUser->tenants()->attach('t3');
        Event::assertDispatched(SyncedResourceChangedInForeignDatabase::class, function (SyncedResourceChangedInForeignDatabase $event) {
            return $event->tenant->getTenantKey() === 't3';
        });

        // Assert no event for central
        Event::assertNotDispatched(SyncedResourceChangedInForeignDatabase::class, function (SyncedResourceChangedInForeignDatabase $event) {
            return $event->tenant === null;
        });

        // Flush
        Event::fake([SyncedResourceChangedInForeignDatabase::class]);

        $t3->run(function () {
            ResourceUser::first()->update([
                'name' => 'John 3',
                'role' => 'employee', // unsynced
            ]);

            $this->assertSame('employee', ResourceUser::first()->role);
        });

        Event::assertDispatched(SyncedResourceChangedInForeignDatabase::class, function (SyncedResourceChangedInForeignDatabase $event) {
            return optional($event->tenant)->getTenantKey() === 't1';
        });
        Event::assertDispatched(SyncedResourceChangedInForeignDatabase::class, function (SyncedResourceChangedInForeignDatabase $event) {
            return optional($event->tenant)->getTenantKey() === 't2';
        });

        // Assert NOT dispatched in t3
        Event::assertNotDispatched(SyncedResourceChangedInForeignDatabase::class, function (SyncedResourceChangedInForeignDatabase $event) {
            return optional($event->tenant)->getTenantKey() === 't3';
        });

        // Assert dispatched in central
        Event::assertDispatched(SyncedResourceChangedInForeignDatabase::class, function (SyncedResourceChangedInForeignDatabase $event) {
            return $event->tenant === null;
        });

        // Flush
        Event::fake([SyncedResourceChangedInForeignDatabase::class]);

        $centralUser->update([
            'name' => 'John Central',
        ]);

        Event::assertDispatched(SyncedResourceChangedInForeignDatabase::class, function (SyncedResourceChangedInForeignDatabase $event) {
            return optional($event->tenant)->getTenantKey() === 't1';
        });
        Event::assertDispatched(SyncedResourceChangedInForeignDatabase::class, function (SyncedResourceChangedInForeignDatabase $event) {
            return optional($event->tenant)->getTenantKey() === 't2';
        });
        Event::assertDispatched(SyncedResourceChangedInForeignDatabase::class, function (SyncedResourceChangedInForeignDatabase $event) {
            return optional($event->tenant)->getTenantKey() === 't3';
        });
        // Assert NOT dispatched in central
        Event::assertNotDispatched(SyncedResourceChangedInForeignDatabase::class, function (SyncedResourceChangedInForeignDatabase $event) {
            return $event->tenant === null;
        });
    }
}

class ResourceTenant extends Tenant
{
    public function users()
    {
        return $this->belongsToMany(CentralUser::class, 'tenant_users', 'tenant_id', 'global_user_id', 'id', 'global_id')
            ->using(TenantPivot::class);
    }
}

class CentralUser extends Model implements SyncMaster
{
    use ResourceSyncing, CentralConnection;

    protected $guarded = [];
    public $timestamps = false;
    public $table = 'users';

    public function tenants(): BelongsToMany
    {
        return $this->belongsToMany(ResourceTenant::class, 'tenant_users', 'global_user_id', 'tenant_id', 'global_id')
            ->using(TenantPivot::class);
    }

    public function getTenantModelName(): string
    {
        return ResourceUser::class;
    }

    public function getGlobalIdentifierKey()
    {
        return $this->getAttribute($this->getGlobalIdentifierKeyName());
    }

    public function getGlobalIdentifierKeyName(): string
    {
        return 'global_id';
    }

    public function getCentralModelName(): string
    {
        return static::class;
    }

    public function getSyncedAttributeNames(): array
    {
        return [
            'name',
            'password',
            'email',
        ];
    }
}

class ResourceUser extends Model implements Syncable
{
    use ResourceSyncing;

    protected $table = 'users';
    protected $guarded = [];
    public $timestamps = false;

    public function getGlobalIdentifierKey()
    {
        return $this->getAttribute($this->getGlobalIdentifierKeyName());
    }

    public function getGlobalIdentifierKeyName(): string
    {
        return 'global_id';
    }

    public function getCentralModelName(): string
    {
        return CentralUser::class;
    }

    public function getSyncedAttributeNames(): array
    {
        return [
            'name',
            'password',
            'email',
        ];
    }
}
