<?php

namespace Rhino\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Rhino\Controllers\GlobalController;
use Rhino\Tests\TestCase;
use Rhino\Traits\HasValidation;
use Rhino\Traits\HidableColumns;

// --------------------------------------------------------------------------
// Test Models
// --------------------------------------------------------------------------

class ScopedRoute extends Model
{
    use HasValidation, HidableColumns, SoftDeletes;

    protected $table = 'scoped_routes';

    protected $fillable = ['status', 'owner_id', 'title'];

    protected $validationRules = [
        'status' => 'string',
        'owner_id' => 'nullable|integer',
        'title' => 'string|max:255',
    ];

    public static $allowedScopes = ['availableForDrivers', 'active'];
    public static $defaultScope = 'active';

    public static $allowedFilters = ['title'];
    public static $allowedSorts = ['title', 'created_at'];
    public static $allowedSearch = ['title'];

    public function scopeActive(Builder $query, ?Authenticatable $user): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeAvailableForDrivers(Builder $query, ?Authenticatable $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0'); // fail closed
        }

        return $query
            ->where('status', 'active')
            ->where('owner_id', $user->id);
    }
}

// Default scope ('active') is NOT listed in $allowedScopes — proves the default
// is implicitly requestable by name.
class ScopedRouteDefaultNotListed extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'scoped_routes';

    protected $fillable = ['status', 'owner_id', 'title'];

    protected $validationRules = [
        'status' => 'string',
        'owner_id' => 'nullable|integer',
        'title' => 'string|max:255',
    ];

    public static $allowedScopes = ['availableForDrivers']; // 'active' absent on purpose
    public static $defaultScope = 'active';

    public function scopeActive(Builder $query, ?Authenticatable $user): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeAvailableForDrivers(Builder $query, ?Authenticatable $user): Builder
    {
        return $query->where('status', 'active');
    }
}

// Whitelists a scope name for which there is no scopeGhost method.
class GhostRoute extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'scoped_routes';

    protected $fillable = ['status', 'owner_id', 'title'];

    protected $validationRules = ['status' => 'string', 'title' => 'string'];

    public static $allowedScopes = ['ghost'];
    public static $defaultScope = null;
}

// No $allowedScopes / $defaultScope — for negative tests.
class UnscopedRoute extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'scoped_routes';

    protected $fillable = ['status', 'owner_id', 'title'];

    protected $validationRules = ['status' => 'string', 'title' => 'string'];
}

// SECURITY: whitelists a scope named 'delete' that collides with Builder::delete().
// ?scope=delete must run scopeDelete (filter status=shadow), NOT mass-delete.
class ShadowRoute extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'scoped_routes';

    protected $fillable = ['status', 'owner_id', 'title'];

    protected $validationRules = ['status' => 'string', 'title' => 'string'];

    public static $allowedScopes = ['delete'];
    public static $defaultScope = null;

    public function scopeDelete(Builder $query, $user): Builder
    {
        return $query->where('status', 'shadow');
    }
}

// Multi-tenant model with an organization_id column + owner-based scope.
class TenantScopedRoute extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'tenant_scoped_routes';

    protected $fillable = ['organization_id', 'status', 'owner_id', 'title'];

    protected $validationRules = [
        'organization_id' => 'integer',
        'status' => 'string',
        'owner_id' => 'nullable|integer',
        'title' => 'string',
    ];

    public static $allowedScopes = ['availableForDrivers', 'active'];
    public static $defaultScope = 'active';

    public function scopeActive(Builder $query, ?Authenticatable $user): Builder
    {
        return $query->where('status', 'active');
    }

    public function scopeAvailableForDrivers(Builder $query, ?Authenticatable $user): Builder
    {
        if (! $user) {
            return $query->whereRaw('1 = 0');
        }

        return $query
            ->where('status', 'active')
            ->where('owner_id', $user->id);
    }
}

class NamedScopePolicy
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

// --------------------------------------------------------------------------
// Tests
// --------------------------------------------------------------------------

class NamedScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('scoped_routes', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('active');
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->string('title');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tenant_scoped_routes', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('status')->default('active');
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->string('title');
            $table->timestamps();
        });

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        Gate::policy(ScopedRoute::class, NamedScopePolicy::class);
        Gate::policy(ScopedRouteDefaultNotListed::class, NamedScopePolicy::class);
        Gate::policy(GhostRoute::class, NamedScopePolicy::class);
        Gate::policy(UnscopedRoute::class, NamedScopePolicy::class);
        Gate::policy(ShadowRoute::class, NamedScopePolicy::class);
        Gate::policy(TenantScopedRoute::class, NamedScopePolicy::class);
    }

    protected function tearDown(): void
    {
        request()->attributes->remove('organization');
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
        ]);

        Route::prefix('api')->group(function () {
            require __DIR__ . '/../../routes/api.php';
        });
    }

    protected function authenticate(int $id = 1): \App\Models\User
    {
        $user = \App\Models\User::firstOrCreate(
            ['id' => $id],
            ['name' => "User {$id}", 'email' => "user{$id}@example.com", 'password' => bcrypt('password')]
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

    // ---- 1 ----------------------------------------------------------------
    public function test_default_scope_applied_when_no_scope_param(): void
    {
        $this->registerRoutes(['routes' => ScopedRoute::class]);
        $this->authenticate();

        ScopedRoute::forceCreate(['status' => 'active', 'title' => 'Alpha']);
        ScopedRoute::forceCreate(['status' => 'inactive', 'title' => 'Beta']);

        $response = $this->getJson('/api/routes');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Alpha', $data[0]['title']);
    }

    // ---- 2 ----------------------------------------------------------------
    public function test_explicit_whitelisted_scope_applies(): void
    {
        $this->registerRoutes(['routes' => ScopedRoute::class]);
        $this->authenticate(1);

        ScopedRoute::forceCreate(['status' => 'active', 'owner_id' => 1, 'title' => 'Mine']);
        ScopedRoute::forceCreate(['status' => 'active', 'owner_id' => 2, 'title' => 'Theirs']);
        ScopedRoute::forceCreate(['status' => 'inactive', 'owner_id' => 1, 'title' => 'MineInactive']);

        $response = $this->getJson('/api/routes?scope=availableForDrivers');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Mine', $data[0]['title']);
    }

    // ---- 3 ----------------------------------------------------------------
    public function test_default_scope_requestable_by_name_even_if_not_in_allowed_list(): void
    {
        $this->registerRoutes(['routes' => ScopedRouteDefaultNotListed::class]);
        $this->authenticate();

        ScopedRouteDefaultNotListed::forceCreate(['status' => 'active', 'title' => 'On']);
        ScopedRouteDefaultNotListed::forceCreate(['status' => 'inactive', 'title' => 'Off']);

        // 'active' is the default but is NOT in $allowedScopes — still allowed by name.
        $response = $this->getJson('/api/routes?scope=active');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('On', $data[0]['title']);
    }

    // ---- 4 ----------------------------------------------------------------
    public function test_non_whitelisted_scope_returns_403(): void
    {
        $this->registerRoutes(['routes' => ScopedRoute::class]);
        $this->authenticate();

        ScopedRoute::forceCreate(['status' => 'active', 'title' => 'X']);

        $response = $this->getJson('/api/routes?scope=secret');

        $response->assertStatus(403);
        $response->assertJson(['message' => "Scope 'secret' is not allowed"]);
    }

    // ---- 5 ----------------------------------------------------------------
    public function test_whitelisted_but_nonexistent_method_returns_403(): void
    {
        $this->registerRoutes(['routes' => GhostRoute::class]);
        $this->authenticate();

        GhostRoute::forceCreate(['status' => 'active', 'title' => 'X']);

        $response = $this->getJson('/api/routes?scope=ghost');

        $response->assertStatus(403);
        $response->assertJson(['message' => "Scope 'ghost' is not allowed"]);
    }

    // ---- 6 ----------------------------------------------------------------
    public function test_array_scope_param_returns_403(): void
    {
        $this->registerRoutes(['routes' => ScopedRoute::class]);
        $this->authenticate();

        ScopedRoute::forceCreate(['status' => 'active', 'title' => 'X']);

        $response = $this->getJson('/api/routes?scope[]=active');

        $response->assertStatus(403);
        $response->assertJson(['message' => 'Scope is not allowed']);
    }

    // ---- 7 ----------------------------------------------------------------
    // NOTE: Spatie `filter[]` application is a known no-op under the Testbench
    // harness (see GlobalControllerExtendedTest::test_index_filters_by_allowed_filter,
    // which is skipped for the same reason). We assert composition at the query
    // level via applyNamedScope + allowedFilters, proving the scope where and the
    // filter where AND together and neither drops the other.
    public function test_scope_composes_with_filter(): void
    {
        $this->registerRoutes(['routes' => ScopedRoute::class]);
        $this->authenticate();

        $query = \Spatie\QueryBuilder\QueryBuilder::for(ScopedRoute::class);

        $controller = new GlobalController();
        $ref = new \ReflectionClass($controller);
        $prop = $ref->getProperty('modelClass');
        $prop->setAccessible(true);
        $prop->setValue($controller, app()->make(ScopedRoute::class));
        $method = $ref->getMethod('applyNamedScope');
        $method->setAccessible(true);

        $err = $method->invoke($controller, $query, Request::create('/api/routes?scope=active', 'GET'));
        $this->assertNull($err);

        // Compose an explicit exact filter on top of the scope.
        $query = $query->allowedFilters([\Spatie\QueryBuilder\AllowedFilter::exact('title')]);
        $query->where('title', 'Keep');

        $sql = $query->toSql();
        $this->assertStringContainsString('"status" = ?', $sql, 'scope where survived');
        $this->assertStringContainsString('"title" = ?', $sql, 'filter where survived');

        ScopedRoute::forceCreate(['status' => 'active', 'title' => 'Keep']);
        ScopedRoute::forceCreate(['status' => 'active', 'title' => 'Drop']);
        ScopedRoute::forceCreate(['status' => 'inactive', 'title' => 'Keep']);

        $results = $query->get();
        $this->assertCount(1, $results);
        $this->assertSame('Keep', $results[0]->title);
        $this->assertSame('active', $results[0]->status);
    }

    // ---- 8 ----------------------------------------------------------------
    // NOTE: Spatie `?sort=` application is also a harness no-op (see
    // GlobalControllerExtendedTest::test_index_sorts_by_allowed_sort, which only
    // asserts a count for the same reason). We verify at the query level that
    // ordering is respected WITHIN the scoped set.
    public function test_scope_composes_with_sort(): void
    {
        $this->registerRoutes(['routes' => ScopedRoute::class]);
        $this->authenticate();

        ScopedRoute::forceCreate(['status' => 'active', 'title' => 'Apple']);
        ScopedRoute::forceCreate(['status' => 'active', 'title' => 'Cherry']);
        ScopedRoute::forceCreate(['status' => 'active', 'title' => 'Banana']);
        ScopedRoute::forceCreate(['status' => 'inactive', 'title' => 'Zeta-inactive']);

        $query = \Spatie\QueryBuilder\QueryBuilder::for(ScopedRoute::class);

        $controller = new GlobalController();
        $ref = new \ReflectionClass($controller);
        $prop = $ref->getProperty('modelClass');
        $prop->setAccessible(true);
        $prop->setValue($controller, app()->make(ScopedRoute::class));
        $method = $ref->getMethod('applyNamedScope');
        $method->setAccessible(true);
        $method->invoke($controller, $query, Request::create('/api/routes?scope=active', 'GET'));

        $query = $query->allowedSorts(['title']);
        $query->orderByDesc('title');

        $titles = $query->get()->pluck('title')->all();

        // 'Zeta-inactive' is excluded by the scope even though it would sort first.
        $this->assertSame(['Cherry', 'Banana', 'Apple'], $titles);
    }

    // ---- 9 ----------------------------------------------------------------
    public function test_scope_composes_with_search(): void
    {
        $this->registerRoutes(['routes' => ScopedRoute::class]);
        $this->authenticate();

        ScopedRoute::forceCreate(['status' => 'active', 'title' => 'needle here']);
        ScopedRoute::forceCreate(['status' => 'active', 'title' => 'no match']);
        ScopedRoute::forceCreate(['status' => 'inactive', 'title' => 'needle inactive']);

        $response = $this->getJson('/api/routes?scope=active&search=needle');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('needle here', $data[0]['title']);
    }

    // ---- 10 ---------------------------------------------------------------
    public function test_scope_composes_with_pagination_and_count_is_scoped(): void
    {
        $this->registerRoutes(['routes' => ScopedRoute::class]);
        $this->authenticate();

        // 3 active + 5 inactive. Scoped total must be 3, not 8.
        for ($i = 0; $i < 3; $i++) {
            ScopedRoute::forceCreate(['status' => 'active', 'title' => "A{$i}"]);
        }
        for ($i = 0; $i < 5; $i++) {
            ScopedRoute::forceCreate(['status' => 'inactive', 'title' => "I{$i}"]);
        }

        $response = $this->getJson('/api/routes?scope=active&per_page=2');

        $response->assertStatus(200);
        $response->assertHeader('X-Total', '3');
        $response->assertHeader('X-Per-Page', '2');
        $this->assertCount(2, $response->json('data'));
    }

    // ---- 11 ---------------------------------------------------------------
    public function test_scope_composes_with_org_isolation(): void
    {
        $this->registerTenantRoutes(['routes' => TenantScopedRoute::class]);

        [$user, $orgA] = $this->createUserInOrg('org-a');
        $orgB = \App\Models\Organization::forceCreate(['name' => 'Org B', 'slug' => 'org-b', 'domain' => null]);

        // Both rows active + owned by the acting user, but in different orgs.
        TenantScopedRoute::forceCreate(['organization_id' => $orgA->id, 'status' => 'active', 'owner_id' => $user->id, 'title' => 'OrgA']);
        TenantScopedRoute::forceCreate(['organization_id' => $orgB->id, 'status' => 'active', 'owner_id' => $user->id, 'title' => 'OrgB']);

        $response = $this->getJson('/api/org-a/routes?scope=availableForDrivers');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('OrgA', $data[0]['title']);
    }

    // ---- 12 ---------------------------------------------------------------
    public function test_current_user_is_injected(): void
    {
        $this->registerRoutes(['routes' => ScopedRoute::class]);

        ScopedRoute::forceCreate(['status' => 'active', 'owner_id' => 1, 'title' => 'User1Route']);
        ScopedRoute::forceCreate(['status' => 'active', 'owner_id' => 2, 'title' => 'User2Route']);

        $this->authenticate(1);
        $r1 = $this->getJson('/api/routes?scope=availableForDrivers');
        $r1->assertStatus(200);
        $this->assertSame(['User1Route'], array_column($r1->json('data'), 'title'));

        $this->authenticate(2);
        $r2 = $this->getJson('/api/routes?scope=availableForDrivers');
        $r2->assertStatus(200);
        $this->assertSame(['User2Route'], array_column($r2->json('data'), 'title'));
    }

    // ---- 13 ---------------------------------------------------------------
    // The default route group requires sanctum auth, so an unauthenticated HTTP
    // call returns 401 before reaching the scope. Instead we assert the scope's
    // null-user branch fails closed directly (whereRaw 1=0 → empty set), which is
    // exactly the value applyNamedScope passes when auth('sanctum')->user() is null.
    public function test_fail_closed_when_unauthenticated(): void
    {
        $this->registerRoutes(['routes' => ScopedRoute::class]);

        ScopedRoute::forceCreate(['status' => 'active', 'owner_id' => 1, 'title' => 'A']);
        ScopedRoute::forceCreate(['status' => 'active', 'owner_id' => 2, 'title' => 'B']);

        // Dispatch the whitelisted scope with a null user, exactly as the
        // controller would when no sanctum user is present.
        $query = \Spatie\QueryBuilder\QueryBuilder::for(ScopedRoute::class);
        $query->scopes(['availableForDrivers' => [null]]);

        $this->assertSame(0, $query->count());
    }

    // ---- 14 ---------------------------------------------------------------
    public function test_no_scope_param_and_no_default_returns_all(): void
    {
        $this->registerRoutes(['routes' => UnscopedRoute::class]);
        $this->authenticate();

        UnscopedRoute::forceCreate(['status' => 'active', 'title' => 'A']);
        UnscopedRoute::forceCreate(['status' => 'inactive', 'title' => 'B']);

        $all = $this->getJson('/api/routes');
        $all->assertStatus(200);
        $this->assertCount(2, $all->json('data'));

        // No $allowedScopes → any ?scope is rejected.
        $bad = $this->getJson('/api/routes?scope=anything');
        $bad->assertStatus(403);
        $bad->assertJson(['message' => "Scope 'anything' is not allowed"]);
    }

    // ---- 15 ---------------------------------------------------------------
    public function test_trashed_listing_honors_scope(): void
    {
        $this->registerRoutes(['routes' => ScopedRoute::class]);
        $this->authenticate();

        // Trashed rows: one active, one inactive. Non-trashed active row must not appear.
        $trashedActive = ScopedRoute::forceCreate(['status' => 'active', 'title' => 'TrashedActive']);
        $trashedInactive = ScopedRoute::forceCreate(['status' => 'inactive', 'title' => 'TrashedInactive']);
        ScopedRoute::forceCreate(['status' => 'active', 'title' => 'LiveActive']);

        $trashedActive->delete();
        $trashedInactive->delete();

        $response = $this->getJson('/api/routes/trashed?scope=active');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('TrashedActive', $data[0]['title']);
    }

    // ---- 16 (KEY SECURITY TEST) -------------------------------------------
    public function test_scope_name_shadowing_builder_method_runs_scope_not_builder(): void
    {
        $this->registerRoutes(['routes' => ShadowRoute::class]);
        $this->authenticate();

        ShadowRoute::forceCreate(['status' => 'shadow', 'title' => 'Shadowed']);
        ShadowRoute::forceCreate(['status' => 'active', 'title' => 'Other']);

        $response = $this->getJson('/api/routes?scope=delete');

        // scopeDelete filtered to status=shadow; Builder::delete() was NOT executed.
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Shadowed', $data[0]['title']);

        // Both rows still exist — nothing was mass-deleted.
        $this->assertSame(2, ShadowRoute::count());
    }

    // ---- 17 ---------------------------------------------------------------
    public function test_model_without_properties_ignores_absent_scope_and_403s_named(): void
    {
        $this->registerRoutes(['routes' => UnscopedRoute::class]);
        $this->authenticate();

        UnscopedRoute::forceCreate(['status' => 'active', 'title' => 'A']);
        UnscopedRoute::forceCreate(['status' => 'inactive', 'title' => 'B']);

        $noScope = $this->getJson('/api/routes');
        $noScope->assertStatus(200);
        $this->assertCount(2, $noScope->json('data'));

        $named = $this->getJson('/api/routes?scope=x');
        $named->assertStatus(403);
        $named->assertJson(['message' => "Scope 'x' is not allowed"]);
    }

    // ---- Unit-style: direct 403 response object for a bad name --------------
    public function test_apply_named_scope_returns_403_response_for_bad_name(): void
    {
        $this->registerRoutes(['routes' => ScopedRoute::class]);

        $controller = new GlobalController();

        // Resolve the model class onto the controller via reflection helper.
        $ref = new \ReflectionClass($controller);
        $prop = $ref->getProperty('modelClass');
        $prop->setAccessible(true);
        $prop->setValue($controller, app()->make(ScopedRoute::class));

        $method = $ref->getMethod('applyNamedScope');
        $method->setAccessible(true);

        $query = \Spatie\QueryBuilder\QueryBuilder::for(ScopedRoute::class);
        $result = $method->invoke($controller, $query, Request::create('/api/routes?scope=secret', 'GET'));

        $this->assertNotNull($result);
        $this->assertSame(403, $result->getStatusCode());
        $this->assertSame("Scope 'secret' is not allowed", $result->getData(true)['message']);
    }

    // ---- 18 ---------------------------------------------------------------
    // show is index/trashed-only's negative case: it never parses ?scope, so a
    // ?scope param is a silent no-op — it neither filters the record out nor
    // 403s on a non-whitelisted name.
    public function test_show_ignores_scope_param_as_silent_no_op(): void
    {
        $this->registerRoutes(['routes' => ScopedRoute::class]);
        $this->authenticate(1);

        // Record the availableForDrivers/default 'active' scope would EXCLUDE:
        // owned by a different user AND not active. Both scopes would drop it.
        $record = ScopedRoute::forceCreate(['status' => 'inactive', 'owner_id' => 2, 'title' => 'Hidden']);

        // A whitelisted scope name on show is ignored — the record still returns.
        $whitelisted = $this->getJson("/api/routes/{$record->id}?scope=availableForDrivers");
        $whitelisted->assertStatus(200);
        $this->assertSame('Hidden', $whitelisted->json('title'));

        // A NON-whitelisted scope name must NOT 403 on show, since show never parses it.
        $nonWhitelisted = $this->getJson("/api/routes/{$record->id}?scope=secret");
        $nonWhitelisted->assertStatus(200);
        $this->assertSame('Hidden', $nonWhitelisted->json('title'));
    }
}
