<?php

namespace Rhino\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Rhino\Tests\TestCase;
use Rhino\Traits\BelongsToOrganization;
use Rhino\Traits\HasValidation;
use Rhino\Traits\HidableColumns;

// --------------------------------------------------------------------------
// Test Models
// --------------------------------------------------------------------------

class RouteGroupPost extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'rg_posts';
    protected $fillable = ['title', 'organization_id'];
}

class RouteGroupCategory extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'rg_categories';
    protected $fillable = ['name'];
}

// --------------------------------------------------------------------------
// Tests
// --------------------------------------------------------------------------

class RouteGroupsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('rg_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->timestamps();
        });

        Schema::create('rg_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
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

    protected function registerModelsAndLoadRoutes(array $models, array $routeGroups, array $multiTenant = []): void
    {
        $defaultMultiTenant = ['organization_identifier_column' => 'id'];

        config([
            'rhino.models' => $models,
            'rhino.route_groups' => $routeGroups,
            'rhino.multi_tenant' => array_merge($defaultMultiTenant, $multiTenant),
        ]);

        Route::prefix('api')->group(function () {
            require __DIR__ . '/../../routes/api.php';
        });
    }

    protected function getRouteNames(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter()
            ->values()
            ->toArray();
    }

    protected function getRouteByName(string $name): ?\Illuminate\Routing\Route
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->getName() === $name) {
                return $route;
            }
        }

        return null;
    }

    protected function createAuthenticatedUser(array $permissions = ['*'], ?string $orgSlug = null): \App\Models\User
    {
        $user = \App\Models\User::forceCreate([
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'permissions' => $permissions,
        ]);

        if ($orgSlug) {
            $org = \App\Models\Organization::firstOrCreate(
                ['slug' => $orgSlug],
                ['name' => ucfirst($orgSlug), 'domain' => null]
            );

            $role = \App\Models\Role::firstOrCreate(
                ['id' => 1],
                ['name' => 'Test Role', 'slug' => 'test-role']
            );

            \App\Models\UserRole::forceCreate([
                'user_id' => $user->id,
                'role_id' => $role->id,
                'organization_id' => $org->id,
                'permissions' => $permissions,
            ]);
        }

        $this->actingAs($user, 'sanctum');

        return $user;
    }

    // ==================================================================
    // Route Group Registration
    // ==================================================================

    public function test_multiple_groups_generate_routes_with_group_prefixed_names(): void
    {
        $this->registerModelsAndLoadRoutes(
            ['posts' => RouteGroupPost::class, 'categories' => RouteGroupCategory::class],
            [
                'tenant' => [
                    'prefix' => '{organization}',
                    'middleware' => [],
                    'models' => '*',
                ],
                'driver' => [
                    'prefix' => 'driver',
                    'middleware' => [],
                    'models' => ['posts'],
                ],
            ]
        );

        $names = $this->getRouteNames();

        // Tenant group gets all models
        $this->assertContains('tenant.posts.index', $names);
        $this->assertContains('tenant.posts.store', $names);
        $this->assertContains('tenant.categories.index', $names);
        $this->assertContains('tenant.categories.store', $names);

        // Driver group only gets posts
        $this->assertContains('driver.posts.index', $names);
        $this->assertContains('driver.posts.store', $names);
        $this->assertNotContains('driver.categories.index', $names);
        $this->assertNotContains('driver.categories.store', $names);
    }

    public function test_route_groups_generate_correct_uri_prefixes(): void
    {
        $this->registerModelsAndLoadRoutes(
            ['posts' => RouteGroupPost::class],
            [
                'tenant' => [
                    'prefix' => '{organization}',
                    'middleware' => [],
                    'models' => '*',
                ],
                'driver' => [
                    'prefix' => 'driver',
                    'middleware' => [],
                    'models' => '*',
                ],
            ]
        );

        $this->assertEquals('api/{organization}/posts', $this->getRouteByName('tenant.posts.index')->uri());
        $this->assertEquals('api/{organization}/posts/{id}', $this->getRouteByName('tenant.posts.show')->uri());

        $this->assertEquals('api/driver/posts', $this->getRouteByName('driver.posts.index')->uri());
        $this->assertEquals('api/driver/posts/{id}', $this->getRouteByName('driver.posts.show')->uri());
    }

    public function test_wildcard_models_registers_all_models(): void
    {
        $this->registerModelsAndLoadRoutes(
            ['posts' => RouteGroupPost::class, 'categories' => RouteGroupCategory::class],
            [
                'default' => [
                    'prefix' => '',
                    'middleware' => [],
                    'models' => '*',
                ],
            ]
        );

        $names = $this->getRouteNames();

        $this->assertContains('default.posts.index', $names);
        $this->assertContains('default.categories.index', $names);
    }

    public function test_model_subset_only_registers_specified_models(): void
    {
        $this->registerModelsAndLoadRoutes(
            ['posts' => RouteGroupPost::class, 'categories' => RouteGroupCategory::class],
            [
                'limited' => [
                    'prefix' => '',
                    'middleware' => [],
                    'models' => ['categories'],
                ],
            ]
        );

        $names = $this->getRouteNames();

        $this->assertContains('limited.categories.index', $names);
        $this->assertNotContains('limited.posts.index', $names);
    }

    public function test_route_defaults_include_route_group_key(): void
    {
        $this->registerModelsAndLoadRoutes(
            ['posts' => RouteGroupPost::class],
            [
                'tenant' => [
                    'prefix' => '{organization}',
                    'middleware' => [],
                    'models' => '*',
                ],
            ]
        );

        $route = $this->getRouteByName('tenant.posts.index');
        $this->assertEquals('posts', $route->defaults['model']);
        $this->assertEquals('tenant', $route->defaults['route_group']);
    }

    public function test_empty_route_groups_registers_no_model_routes(): void
    {
        $this->registerModelsAndLoadRoutes(
            ['posts' => RouteGroupPost::class],
            []
        );

        $names = $this->getRouteNames();

        $this->assertNotContains('default.posts.index', $names);
        $this->assertNotContains('posts.index', $names);
    }

    // ==================================================================
    // Public Group (skips auth:sanctum)
    // ==================================================================

    public function test_public_group_skips_auth_sanctum(): void
    {
        $this->registerModelsAndLoadRoutes(
            ['categories' => RouteGroupCategory::class],
            [
                'public' => [
                    'prefix' => '',
                    'middleware' => [],
                    'models' => ['categories'],
                ],
            ]
        );

        $middleware = $this->getRouteByName('public.categories.index')->middleware();
        $this->assertNotContains('auth:sanctum', $middleware);
    }

    public function test_non_public_groups_include_auth_sanctum(): void
    {
        $this->registerModelsAndLoadRoutes(
            ['posts' => RouteGroupPost::class],
            [
                'default' => [
                    'prefix' => '',
                    'middleware' => [],
                    'models' => '*',
                ],
            ]
        );

        $middleware = $this->getRouteByName('default.posts.index')->middleware();
        $this->assertContains('auth:sanctum', $middleware);
    }

    public function test_public_group_accessible_without_auth(): void
    {
        Gate::policy(RouteGroupCategory::class, RouteGroupAllowAllPolicy::class);

        $this->registerModelsAndLoadRoutes(
            ['categories' => RouteGroupCategory::class],
            [
                'public' => [
                    'prefix' => '',
                    'middleware' => [],
                    'models' => ['categories'],
                ],
            ]
        );

        RouteGroupCategory::forceCreate(['name' => 'Test Category']);

        $response = $this->getJson('/api/categories');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
    }

    public function test_non_public_group_returns_401_without_auth(): void
    {
        $this->registerModelsAndLoadRoutes(
            ['posts' => RouteGroupPost::class],
            [
                'default' => [
                    'prefix' => '',
                    'middleware' => [],
                    'models' => '*',
                ],
            ]
        );

        $response = $this->getJson('/api/posts');
        $response->assertStatus(401);
    }

    // ==================================================================
    // Tenant Group (organization scoping)
    // ==================================================================

    public function test_tenant_group_applies_organization_scoping(): void
    {
        Gate::policy(RouteGroupPost::class, RouteGroupAllowAllPolicy::class);

        $this->registerModelsAndLoadRoutes(
            ['posts' => RouteGroupPost::class],
            [
                'tenant' => [
                    'prefix' => '{organization}',
                    'middleware' => [\Rhino\Http\Middleware\ResolveOrganizationFromRoute::class],
                    'models' => '*',
                ],
            ],
            ['organization_identifier_column' => 'slug']
        );

        $org1 = \App\Models\Organization::forceCreate(['name' => 'Org One', 'slug' => 'org-one', 'domain' => null]);
        $org2 = \App\Models\Organization::forceCreate(['name' => 'Org Two', 'slug' => 'org-two', 'domain' => null]);

        RouteGroupPost::forceCreate(['title' => 'Org1 Post', 'organization_id' => $org1->id]);
        RouteGroupPost::forceCreate(['title' => 'Org2 Post', 'organization_id' => $org2->id]);

        $user = $this->createAuthenticatedUser(['*'], 'org-one');

        $response = $this->getJson('/api/org-one/posts');
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('Org1 Post', $data[0]['title']);
    }

    public function test_non_tenant_group_does_not_apply_organization_scoping(): void
    {
        Gate::policy(RouteGroupPost::class, RouteGroupAllowAllPolicy::class);

        $this->registerModelsAndLoadRoutes(
            ['posts' => RouteGroupPost::class],
            [
                'default' => [
                    'prefix' => '',
                    'middleware' => [],
                    'models' => '*',
                ],
            ]
        );

        $user = $this->createAuthenticatedUser();

        RouteGroupPost::forceCreate(['title' => 'Post A', 'organization_id' => 1]);
        RouteGroupPost::forceCreate(['title' => 'Post B', 'organization_id' => 2]);

        $response = $this->getJson('/api/posts');
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(2, $data);
    }

    // ==================================================================
    // Same Model in Multiple Groups
    // ==================================================================

    public function test_same_model_in_tenant_and_non_tenant_groups_returns_different_results(): void
    {
        Gate::policy(RouteGroupPost::class, RouteGroupAllowAllPolicy::class);

        $this->registerModelsAndLoadRoutes(
            ['posts' => RouteGroupPost::class],
            [
                'tenant' => [
                    'prefix' => '{organization}',
                    'middleware' => [\Rhino\Http\Middleware\ResolveOrganizationFromRoute::class],
                    'models' => '*',
                ],
                'admin' => [
                    'prefix' => 'admin',
                    'middleware' => [],
                    'models' => '*',
                ],
            ],
            ['organization_identifier_column' => 'slug']
        );

        $org = \App\Models\Organization::forceCreate(['name' => 'Org One', 'slug' => 'org-one', 'domain' => null]);

        RouteGroupPost::forceCreate(['title' => 'Org Post', 'organization_id' => $org->id]);
        RouteGroupPost::forceCreate(['title' => 'Other Post', 'organization_id' => 999]);

        $user = $this->createAuthenticatedUser(['*'], 'org-one');

        // Tenant group: scoped to org-one, returns 1 post
        $tenantResponse = $this->getJson('/api/org-one/posts');
        $tenantResponse->assertStatus(200);
        $this->assertCount(1, $tenantResponse->json('data'));
        $this->assertEquals('Org Post', $tenantResponse->json('data')[0]['title']);

        // Admin group: no scoping, returns all posts
        $adminResponse = $this->getJson('/api/admin/posts');
        $adminResponse->assertStatus(200);
        $this->assertCount(2, $adminResponse->json('data'));
    }

    // ==================================================================
    // Group-level Middleware
    // ==================================================================

    public function test_group_middleware_applied_to_all_routes_in_group(): void
    {
        $this->registerModelsAndLoadRoutes(
            ['posts' => RouteGroupPost::class],
            [
                'custom' => [
                    'prefix' => 'custom',
                    'middleware' => ['throttle:10,1'],
                    'models' => '*',
                ],
            ]
        );

        $middleware = $this->getRouteByName('custom.posts.index')->middleware();
        $this->assertContains('auth:sanctum', $middleware);
        $this->assertContains('throttle:10,1', $middleware);
    }

    // ==================================================================
    // Backward Compatibility
    // ==================================================================

    public function test_simple_non_tenant_config_works(): void
    {
        Gate::policy(RouteGroupPost::class, RouteGroupAllowAllPolicy::class);

        $this->registerModelsAndLoadRoutes(
            ['posts' => RouteGroupPost::class],
            [
                'default' => [
                    'prefix' => '',
                    'middleware' => [],
                    'models' => '*',
                ],
            ]
        );

        $user = $this->createAuthenticatedUser();
        RouteGroupPost::forceCreate(['title' => 'Simple Post']);

        $response = $this->getJson('/api/posts');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
    }

    public function test_simple_tenant_only_config_works(): void
    {
        Gate::policy(RouteGroupPost::class, RouteGroupAllowAllPolicy::class);

        $this->registerModelsAndLoadRoutes(
            ['posts' => RouteGroupPost::class],
            [
                'tenant' => [
                    'prefix' => '{organization}',
                    'middleware' => [\Rhino\Http\Middleware\ResolveOrganizationFromRoute::class],
                    'models' => '*',
                ],
            ],
            ['organization_identifier_column' => 'slug']
        );

        $org = \App\Models\Organization::forceCreate(['name' => 'My Org', 'slug' => 'my-org', 'domain' => null]);
        RouteGroupPost::forceCreate(['title' => 'Org Post', 'organization_id' => $org->id]);

        $user = $this->createAuthenticatedUser(['*'], 'my-org');

        $response = $this->getJson('/api/my-org/posts');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
    }

    // ==================================================================
    // Three-group config (tenant + driver + public)
    // ==================================================================

    public function test_three_groups_register_correct_routes(): void
    {
        $this->registerModelsAndLoadRoutes(
            ['posts' => RouteGroupPost::class, 'categories' => RouteGroupCategory::class],
            [
                'tenant' => [
                    'prefix' => '{organization}',
                    'middleware' => [],
                    'models' => '*',
                ],
                'driver' => [
                    'prefix' => 'driver',
                    'middleware' => [],
                    'models' => ['posts'],
                ],
                'public' => [
                    'prefix' => 'public',
                    'middleware' => [],
                    'models' => ['categories'],
                ],
            ]
        );

        $names = $this->getRouteNames();

        // Tenant: all models
        $this->assertContains('tenant.posts.index', $names);
        $this->assertContains('tenant.categories.index', $names);

        // Driver: only posts
        $this->assertContains('driver.posts.index', $names);
        $this->assertNotContains('driver.categories.index', $names);

        // Public: only categories
        $this->assertContains('public.categories.index', $names);
        $this->assertNotContains('public.posts.index', $names);

        // Verify prefixes
        $this->assertEquals('api/{organization}/posts', $this->getRouteByName('tenant.posts.index')->uri());
        $this->assertEquals('api/driver/posts', $this->getRouteByName('driver.posts.index')->uri());
        $this->assertEquals('api/public/categories', $this->getRouteByName('public.categories.index')->uri());

        // Verify auth middleware
        $this->assertContains('auth:sanctum', $this->getRouteByName('tenant.posts.index')->middleware());
        $this->assertContains('auth:sanctum', $this->getRouteByName('driver.posts.index')->middleware());
        $this->assertNotContains('auth:sanctum', $this->getRouteByName('public.categories.index')->middleware());
    }

    // ==================================================================
    // Invitation routes follow tenant group
    // ==================================================================

    public function test_invitation_routes_registered_when_tenant_group_exists(): void
    {
        $this->registerModelsAndLoadRoutes(
            ['posts' => RouteGroupPost::class],
            [
                'tenant' => [
                    'prefix' => '{organization}',
                    'middleware' => [],
                    'models' => '*',
                ],
            ]
        );

        $routes = collect(Route::getRoutes()->getRoutes());
        // Filter to invitation CRUD routes (exclude the global accept route)
        $invitationCrudRoutes = $routes->filter(fn ($r) =>
            str_contains($r->uri(), 'invitations') &&
            !str_contains($r->uri(), 'accept')
        );

        $this->assertTrue($invitationCrudRoutes->isNotEmpty(), 'Invitation CRUD routes should be registered');
        $firstInvitation = $invitationCrudRoutes->first();
        $this->assertStringContainsString('{organization}', $firstInvitation->uri());
    }

    public function test_invitation_routes_not_registered_without_tenant_group(): void
    {
        $this->registerModelsAndLoadRoutes(
            ['posts' => RouteGroupPost::class],
            [
                'default' => [
                    'prefix' => '',
                    'middleware' => [],
                    'models' => '*',
                ],
            ]
        );

        $routes = collect(Route::getRoutes()->getRoutes());
        $invitationCrudRoutes = $routes->filter(fn ($r) =>
            str_contains($r->uri(), 'invitations') &&
            !str_contains($r->uri(), 'accept')
        );

        $this->assertTrue($invitationCrudRoutes->isEmpty(), 'Invitation CRUD routes should not be registered without tenant group');
    }
}

// --------------------------------------------------------------------------
// Test Policies
// --------------------------------------------------------------------------

class RouteGroupAllowAllPolicy
{
    public function viewAny(?Authenticatable $user): bool
    {
        return true;
    }

    public function view(?Authenticatable $user, $model): bool
    {
        return true;
    }

    public function create(?Authenticatable $user): bool
    {
        return true;
    }

    public function update(?Authenticatable $user, $model): bool
    {
        return true;
    }

    public function delete(?Authenticatable $user, $model): bool
    {
        return true;
    }
}
