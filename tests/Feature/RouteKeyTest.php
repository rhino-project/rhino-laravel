<?php

namespace Rhino\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Rhino\Contracts\HasPermittedAttributes;
use Rhino\Tests\TestCase;
use Rhino\Traits\BelongsToOrganization;
use Rhino\Traits\HasValidation;
use Rhino\Traits\HidableColumns;

// --------------------------------------------------------------------------
// Test Models
// --------------------------------------------------------------------------

/** Model with a per-model static $routeKey. */
class RkHashJob extends Model
{
    use SoftDeletes, HasValidation, HidableColumns;

    protected $table = 'rk_hash_jobs';
    protected $fillable = ['title', 'hash_id', 'alt_ref'];

    public static string $routeKey = 'hash_id';

    protected $validationRules = [
        'title' => 'required|string|max:255',
    ];
    protected $validationRulesStore = ['title'];
    protected $validationRulesUpdate = ['title'];
}

/** Same table, static $routeKey, used for whitelist serialization tests. */
class RkWhitelistJob extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'rk_hash_jobs';
    protected $fillable = ['title', 'hash_id', 'alt_ref'];

    public static string $routeKey = 'hash_id';
}

/** Model without $routeKey — follows config('rhino.route_key') / default. */
class RkPlainJob extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'rk_plain_jobs';
    protected $fillable = ['title', 'hash_id'];

    protected $validationRules = [
        'title' => 'required|string|max:255',
    ];
    protected $validationRulesStore = ['title'];
    protected $validationRulesUpdate = ['title'];
}

/** Second plain model to prove the global config applies across models. */
class RkPlainTask extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'rk_plain_tasks';
    protected $fillable = ['title', 'hash_id'];
}

/** Org-owned model with static $routeKey for cross-tenant isolation tests. */
class RkTenantJob extends Model
{
    use HasValidation, HidableColumns, BelongsToOrganization;

    protected $table = 'rk_tenant_jobs';
    protected $fillable = ['title', 'hash_id', 'organization_id'];

    public static string $routeKey = 'hash_id';

    protected $validationRules = [
        'title' => 'required|string|max:255',
    ];
    protected $validationRulesStore = ['title'];
    protected $validationRulesUpdate = ['title'];
}

// --------------------------------------------------------------------------
// Test Policies
// --------------------------------------------------------------------------

class RkPermissivePolicy
{
    public function viewAny(?Authenticatable $user): bool { return true; }
    public function view(?Authenticatable $user, $model): bool { return true; }
    public function create(?Authenticatable $user): bool { return true; }
    public function update(?Authenticatable $user, $model): bool { return true; }
    public function delete(?Authenticatable $user, $model): bool { return true; }
    public function viewTrashed(?Authenticatable $user): bool { return true; }
    public function restore(?Authenticatable $user, $model): bool { return true; }
    public function forceDelete(?Authenticatable $user, $model): bool { return true; }
}

/** Whitelist policy: only `title` is permitted on show. */
class RkWhitelistPolicy implements HasPermittedAttributes
{
    public function viewAny(?Authenticatable $user): bool { return true; }
    public function view(?Authenticatable $user, $model): bool { return true; }
    public function create(?Authenticatable $user): bool { return true; }
    public function update(?Authenticatable $user, $model): bool { return true; }
    public function delete(?Authenticatable $user, $model): bool { return true; }

    public function permittedAttributesForShow(?Authenticatable $user): array
    {
        return ['title'];
    }

    public function hiddenAttributesForShow(?Authenticatable $user): array
    {
        return [];
    }

    public function permittedAttributesForCreate(?Authenticatable $user): array
    {
        return ['title'];
    }

    public function permittedAttributesForUpdate(?Authenticatable $user): array
    {
        return ['title'];
    }
}

class RkOrganizationPolicy
{
    public function viewAny(?Authenticatable $user): bool { return true; }
    public function view(?Authenticatable $user, $model): bool { return true; }
    public function create(?Authenticatable $user): bool { return true; }
    public function update(?Authenticatable $user, $model): bool { return true; }
    public function delete(?Authenticatable $user, $model): bool { return true; }
}

// --------------------------------------------------------------------------
// Tests
// --------------------------------------------------------------------------

class RouteKeyTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        Schema::create('rk_hash_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('hash_id')->unique();
            $table->string('alt_ref')->nullable()->unique();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('rk_plain_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('hash_id')->unique();
            $table->timestamps();
        });

        Schema::create('rk_plain_tasks', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('hash_id')->unique();
            $table->timestamps();
        });

        Schema::create('rk_tenant_jobs', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('hash_id'); // unique per org, not globally, on purpose
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->timestamps();
        });

        Gate::policy(RkHashJob::class, RkPermissivePolicy::class);
        Gate::policy(RkWhitelistJob::class, RkWhitelistPolicy::class);
        Gate::policy(RkPlainJob::class, RkPermissivePolicy::class);
        Gate::policy(RkPlainTask::class, RkPermissivePolicy::class);
        Gate::policy(RkTenantJob::class, RkPermissivePolicy::class);
    }

    protected function tearDown(): void
    {
        RkHashJob::clearHiddenColumnsCache();
        RkWhitelistJob::clearHiddenColumnsCache();
        RkPlainJob::clearHiddenColumnsCache();
        RkPlainTask::clearHiddenColumnsCache();
        RkTenantJob::clearHiddenColumnsCache();

        parent::tearDown();
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('auth.guards.sanctum', [
            'driver' => 'session',
            'provider' => 'users',
        ]);

        $app['config']->set('auth.providers.users', [
            'driver' => 'eloquent',
            'model' => \App\Models\User::class,
        ]);
    }

    protected function registerRoutes(array $models): void
    {
        config([
            'rhino.models' => $models,
            'rhino.route_groups' => [
                'default' => ['prefix' => '', 'middleware' => [], 'models' => '*'],
            ],
            'rhino.multi_tenant' => [
                'organization_identifier_column' => 'id',
            ],
            'rhino.nested' => [
                'path' => 'nested',
                'max_operations' => 50,
                'allowed_models' => null,
            ],
        ]);

        Route::prefix('api')->group(function () {
            require __DIR__ . '/../../routes/api.php';
        });
    }

    protected function registerTenantRoutes(array $models): void
    {
        config([
            'rhino.models' => $models,
            'rhino.route_groups' => [
                'tenant' => [
                    'prefix' => '{organization}',
                    'middleware' => [\Rhino\Http\Middleware\ResolveOrganizationFromRoute::class],
                    'models' => '*',
                ],
            ],
            'rhino.multi_tenant' => [
                'organization_identifier_column' => 'slug',
            ],
            'rhino.nested' => [
                'path' => 'nested',
                'max_operations' => 50,
                'allowed_models' => null,
            ],
        ]);

        Route::prefix('api')->group(function () {
            require __DIR__ . '/../../routes/api.php';
        });
    }

    protected function authenticate(): \App\Models\User
    {
        $user = \App\Models\User::firstOrCreate(
            ['id' => 1],
            ['name' => 'Test User', 'email' => 'test@example.com', 'password' => bcrypt('password')]
        );
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    protected function createUserInOrg(string $orgSlug, array $permissions = ['*']): array
    {
        $user = \App\Models\User::forceCreate([
            'name' => 'Test User',
            'email' => "user-{$orgSlug}@example.com",
            'password' => bcrypt('password'),
        ]);

        $org = \App\Models\Organization::firstOrCreate(
            ['slug' => $orgSlug],
            ['name' => ucfirst($orgSlug), 'domain' => null]
        );

        $role = \App\Models\Role::firstOrCreate(
            ['slug' => 'admin'],
            ['name' => 'Admin']
        );

        \App\Models\UserRole::forceCreate([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'permissions' => $permissions,
        ]);

        $this->actingAs($user, 'sanctum');

        return [$user, $org];
    }

    // ==================================================================
    // Member endpoints resolve by the model's static $routeKey
    // ==================================================================

    public function test_show_resolves_record_by_hash_id(): void
    {
        $this->registerRoutes(['jobs' => RkHashJob::class]);
        $this->authenticate();

        $job = RkHashJob::forceCreate(['title' => 'Deploy', 'hash_id' => 'abc123']);

        $response = $this->getJson('/api/jobs/abc123');

        $response->assertStatus(200);
        $response->assertJsonPath('title', 'Deploy');
        $response->assertJsonPath('id', $job->id);
    }

    public function test_update_resolves_record_by_hash_id(): void
    {
        $this->registerRoutes(['jobs' => RkHashJob::class]);
        $this->authenticate();

        $job = RkHashJob::forceCreate(['title' => 'Old Title', 'hash_id' => 'abc123']);

        $response = $this->putJson('/api/jobs/abc123', ['title' => 'New Title']);

        $response->assertStatus(200);
        $response->assertJsonPath('title', 'New Title');
        $this->assertSame('New Title', $job->fresh()->title);
    }

    public function test_destroy_resolves_record_by_hash_id(): void
    {
        $this->registerRoutes(['jobs' => RkHashJob::class]);
        $this->authenticate();

        $job = RkHashJob::forceCreate(['title' => 'Doomed', 'hash_id' => 'abc123']);

        $response = $this->deleteJson('/api/jobs/abc123');

        $response->assertStatus(204);
        $this->assertTrue($job->fresh()->trashed());
    }

    public function test_restore_resolves_trashed_record_by_hash_id(): void
    {
        $this->registerRoutes(['jobs' => RkHashJob::class]);
        $this->authenticate();

        $job = RkHashJob::forceCreate(['title' => 'Trashed', 'hash_id' => 'abc123']);
        $job->delete();

        $response = $this->postJson('/api/jobs/abc123/restore');

        $response->assertStatus(200);
        $this->assertFalse($job->fresh()->trashed());
    }

    public function test_force_delete_resolves_trashed_record_by_hash_id(): void
    {
        $this->registerRoutes(['jobs' => RkHashJob::class]);
        $this->authenticate();

        $job = RkHashJob::forceCreate(['title' => 'Gone', 'hash_id' => 'abc123']);
        $job->delete();

        $response = $this->deleteJson('/api/jobs/abc123/force-delete');

        $response->assertStatus(204);
        $this->assertNull(RkHashJob::withTrashed()->find($job->id));
    }

    // ==================================================================
    // The primary key must NOT match when a route key is configured
    // ==================================================================

    public function test_show_by_primary_key_returns_404_when_route_key_is_set(): void
    {
        $this->registerRoutes(['jobs' => RkHashJob::class]);
        $this->authenticate();

        $job = RkHashJob::forceCreate(['title' => 'Hidden', 'hash_id' => 'abc123']);

        $this->getJson("/api/jobs/{$job->id}")->assertStatus(404);
    }

    public function test_update_and_destroy_by_primary_key_return_404_when_route_key_is_set(): void
    {
        $this->registerRoutes(['jobs' => RkHashJob::class]);
        $this->authenticate();

        $job = RkHashJob::forceCreate(['title' => 'Hidden', 'hash_id' => 'abc123']);

        $this->putJson("/api/jobs/{$job->id}", ['title' => 'X'])->assertStatus(404);
        $this->deleteJson("/api/jobs/{$job->id}")->assertStatus(404);
        $this->assertSame('Hidden', $job->fresh()->title);
        $this->assertFalse($job->fresh()->trashed());
    }

    public function test_restore_and_force_delete_by_primary_key_return_404_when_route_key_is_set(): void
    {
        $this->registerRoutes(['jobs' => RkHashJob::class]);
        $this->authenticate();

        $job = RkHashJob::forceCreate(['title' => 'Hidden', 'hash_id' => 'abc123']);
        $job->delete();

        $this->postJson("/api/jobs/{$job->id}/restore")->assertStatus(404);
        $this->deleteJson("/api/jobs/{$job->id}/force-delete")->assertStatus(404);
        $this->assertNotNull(RkHashJob::withTrashed()->find($job->id));
    }

    public function test_unknown_hash_returns_404(): void
    {
        $this->registerRoutes(['jobs' => RkHashJob::class]);
        $this->authenticate();

        RkHashJob::forceCreate(['title' => 'Existing', 'hash_id' => 'abc123']);

        $this->getJson('/api/jobs/does-not-exist')->assertStatus(404);
    }

    // ==================================================================
    // Cross-tenant isolation: route-key lookup happens INSIDE the
    // org-scoped query
    // ==================================================================

    public function test_record_in_another_organization_returns_404_even_with_correct_hash(): void
    {
        $this->registerTenantRoutes(['tjobs' => RkTenantJob::class]);

        [$user, $orgA] = $this->createUserInOrg('org-a');
        $orgB = \App\Models\Organization::forceCreate(['name' => 'Org B', 'slug' => 'org-b', 'domain' => null]);

        RkTenantJob::forceCreate(['title' => 'Foreign', 'hash_id' => 'foreign-hash', 'organization_id' => $orgB->id]);

        $this->getJson('/api/org-a/tjobs/foreign-hash')->assertStatus(404);
        $this->putJson('/api/org-a/tjobs/foreign-hash', ['title' => 'X'])->assertStatus(404);
        $this->deleteJson('/api/org-a/tjobs/foreign-hash')->assertStatus(404);
    }

    public function test_record_in_own_organization_resolves_by_hash(): void
    {
        $this->registerTenantRoutes(['tjobs' => RkTenantJob::class]);

        [$user, $orgA] = $this->createUserInOrg('org-a');

        $job = RkTenantJob::forceCreate(['title' => 'Mine', 'hash_id' => 'my-hash', 'organization_id' => $orgA->id]);

        $response = $this->getJson('/api/org-a/tjobs/my-hash');

        $response->assertStatus(200);
        $response->assertJsonPath('id', $job->id);
        $response->assertJsonPath('title', 'Mine');
    }

    // ==================================================================
    // Serialization: the route-key column survives policy whitelists
    // ==================================================================

    public function test_hash_id_survives_policy_whitelist_in_show_response(): void
    {
        $this->registerRoutes(['wjobs' => RkWhitelistJob::class]);
        $this->authenticate();

        RkWhitelistJob::forceCreate(['title' => 'Visible', 'hash_id' => 'wl-hash', 'alt_ref' => 'ALT-9']);

        $response = $this->getJson('/api/wjobs/wl-hash');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertSame('Visible', $data['title'] ?? null);
        $this->assertSame('wl-hash', $data['hash_id'] ?? null, 'route-key column must survive the whitelist');
        $this->assertArrayHasKey('id', $data);
        $this->assertArrayNotHasKey('alt_ref', $data, 'non-permitted columns must still be stripped');
    }

    // ==================================================================
    // Precedence: model static beats config; config applies when the
    // model has no static; default stays primary-key based
    // ==================================================================

    public function test_model_static_route_key_beats_global_config(): void
    {
        $this->registerRoutes(['jobs' => RkHashJob::class]);
        config(['rhino.route_key' => 'alt_ref']);
        $this->authenticate();

        RkHashJob::forceCreate(['title' => 'Prec', 'hash_id' => 'the-hash', 'alt_ref' => 'the-alt']);

        $this->getJson('/api/jobs/the-hash')->assertStatus(200);
        $this->getJson('/api/jobs/the-alt')->assertStatus(404);
    }

    public function test_global_config_route_key_applies_to_models_without_static(): void
    {
        $this->registerRoutes(['pjobs' => RkPlainJob::class, 'ptasks' => RkPlainTask::class]);
        config(['rhino.route_key' => 'hash_id']);
        $this->authenticate();

        $jobA = RkPlainJob::forceCreate(['title' => 'Job A', 'hash_id' => 'job-hash']);
        $taskA = RkPlainTask::forceCreate(['title' => 'Task A', 'hash_id' => 'task-hash']);

        $this->getJson('/api/pjobs/job-hash')->assertStatus(200);
        $this->getJson('/api/ptasks/task-hash')->assertStatus(200);

        // Primary key no longer matches under the global route key
        $this->getJson("/api/pjobs/{$jobA->id}")->assertStatus(404);
        $this->getJson("/api/ptasks/{$taskA->id}")->assertStatus(404);
    }

    public function test_global_config_route_key_applies_to_update_and_destroy(): void
    {
        $this->registerRoutes(['pjobs' => RkPlainJob::class]);
        config(['rhino.route_key' => 'hash_id']);
        $this->authenticate();

        $job = RkPlainJob::forceCreate(['title' => 'Original', 'hash_id' => 'job-hash']);

        $this->putJson('/api/pjobs/job-hash', ['title' => 'Changed'])->assertStatus(200);
        $this->assertSame('Changed', $job->fresh()->title);

        $this->deleteJson('/api/pjobs/job-hash')->assertStatus(204);
        $this->assertNull(RkPlainJob::find($job->id));
    }

    public function test_default_behavior_without_static_or_config_uses_primary_key(): void
    {
        $this->registerRoutes(['pjobs' => RkPlainJob::class]);
        $this->authenticate();

        $this->assertNull(config('rhino.route_key'));

        $job = RkPlainJob::forceCreate(['title' => 'Classic', 'hash_id' => 'unused-hash']);

        $this->getJson("/api/pjobs/{$job->id}")->assertStatus(200);
        $this->getJson('/api/pjobs/unused-hash')->assertStatus(404);
    }

    public function test_config_route_key_id_behaves_like_default(): void
    {
        $this->registerRoutes(['pjobs' => RkPlainJob::class]);
        config(['rhino.route_key' => 'id']);
        $this->authenticate();

        $job = RkPlainJob::forceCreate(['title' => 'Classic', 'hash_id' => 'unused-hash']);

        $this->getJson("/api/pjobs/{$job->id}")->assertStatus(200);
        $this->getJson('/api/pjobs/unused-hash')->assertStatus(404);
    }

    // ==================================================================
    // Nested operations stay primary-key based (contract)
    // ==================================================================

    public function test_nested_update_resolves_by_primary_key_even_when_model_has_route_key(): void
    {
        $this->registerRoutes(['jobs' => RkHashJob::class]);
        $this->authenticate();

        $job = RkHashJob::forceCreate(['title' => 'Nested Original', 'hash_id' => 'abc123']);

        $response = $this->postJson('/api/nested', [
            'operations' => [
                ['model' => 'jobs', 'action' => 'update', 'id' => $job->id, 'data' => ['title' => 'Nested Updated']],
            ],
        ]);

        $response->assertStatus(200);
        $this->assertSame('Nested Updated', $job->fresh()->title);
    }

    public function test_nested_update_by_hash_returns_404_because_ids_are_primary_keys(): void
    {
        $this->registerRoutes(['jobs' => RkHashJob::class]);
        $this->authenticate();

        $job = RkHashJob::forceCreate(['title' => 'Nested Original', 'hash_id' => 'abc123']);

        $response = $this->postJson('/api/nested', [
            'operations' => [
                ['model' => 'jobs', 'action' => 'update', 'id' => 'abc123', 'data' => ['title' => 'Should Not Apply']],
            ],
        ]);

        $response->assertStatus(404);
        $this->assertSame('Nested Original', $job->fresh()->title);
    }

    // ==================================================================
    // Organization-resource identity check
    // ==================================================================

    public function test_organization_identity_check_still_works_with_default_route_key(): void
    {
        Gate::policy(\App\Models\Organization::class, RkOrganizationPolicy::class);
        $this->registerTenantRoutes(['organizations' => \App\Models\Organization::class]);

        [$user, $org] = $this->createUserInOrg('org-one');
        \App\Models\Organization::forceCreate(['name' => 'Org Two', 'slug' => 'org-two', 'domain' => null]);

        // Route id matching the current org's primary key resolves it
        $this->getJson("/api/org-one/organizations/{$org->id}")->assertStatus(200);

        // Any other id is a mismatch → 404
        $this->getJson('/api/org-one/organizations/999')->assertStatus(404);
    }

    public function test_organization_identity_check_uses_configured_route_key(): void
    {
        Gate::policy(\App\Models\Organization::class, RkOrganizationPolicy::class);
        $this->registerTenantRoutes(['organizations' => \App\Models\Organization::class]);
        config(['rhino.route_key' => 'slug']);

        [$user, $org] = $this->createUserInOrg('org-one');

        // The identity check now compares against the org's slug
        $response = $this->getJson('/api/org-one/organizations/org-one');
        $response->assertStatus(200);
        $response->assertJsonPath('slug', 'org-one');

        // The primary key no longer matches
        $this->getJson("/api/org-one/organizations/{$org->id}")->assertStatus(404);
    }
}
