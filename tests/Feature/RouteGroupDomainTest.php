<?php

namespace Rhino\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Rhino\Tests\TestCase;
use Rhino\Traits\HasValidation;
use Rhino\Traits\HidableColumns;

// --------------------------------------------------------------------------
// Test Models
// --------------------------------------------------------------------------

class DomainPost extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'dom_posts';
    protected $fillable = ['title', 'organization_id'];

    protected $validationRules = [
        'title' => 'string|max:255',
    ];
}

class DomainCategory extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'dom_categories';
    protected $fillable = ['name'];
}

class DomainSoftPost extends Model
{
    use HasValidation, HidableColumns, SoftDeletes;

    protected $table = 'dom_soft_posts';
    protected $fillable = ['title', 'organization_id'];
}

// --------------------------------------------------------------------------
// Tests for domain-aware route groups
// --------------------------------------------------------------------------

class RouteGroupDomainTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('dom_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->timestamps();
        });

        Schema::create('dom_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('dom_soft_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
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

    protected function getRouteByName(string $name): ?\Illuminate\Routing\Route
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->getName() === $name) {
                return $route;
            }
        }

        return null;
    }

    protected function createAuthenticatedUser(array $permissions = ['*'], array $orgSlugs = []): \App\Models\User
    {
        $user = \App\Models\User::forceCreate([
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
            'permissions' => $permissions,
        ]);

        $role = \App\Models\Role::firstOrCreate(
            ['id' => 1],
            ['name' => 'Test Role', 'slug' => 'test-role']
        );

        foreach ($orgSlugs as $orgSlug) {
            $org = \App\Models\Organization::firstOrCreate(
                ['slug' => $orgSlug],
                ['name' => ucfirst($orgSlug), 'domain' => null]
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
    // Registration: the domain is applied to the route definition
    // ==================================================================

    public function test_group_with_literal_domain_sets_route_domain(): void
    {
        $this->registerModelsAndLoadRoutes(
            ['posts' => DomainPost::class],
            [
                'admin' => [
                    'prefix' => '',
                    'domain' => 'admin.example.com',
                    'middleware' => [],
                    'models' => '*',
                ],
            ]
        );

        $this->assertEquals('admin.example.com', $this->getRouteByName('admin.posts.index')->getDomain());
        $this->assertEquals('admin.example.com', $this->getRouteByName('admin.posts.show')->getDomain());
    }

    public function test_group_without_domain_has_null_route_domain(): void
    {
        $this->registerModelsAndLoadRoutes(
            ['posts' => DomainPost::class],
            [
                'default' => [
                    'prefix' => '',
                    'middleware' => [],
                    'models' => '*',
                ],
            ]
        );

        $this->assertNull($this->getRouteByName('default.posts.index')->getDomain());
    }

    public function test_empty_string_domain_is_treated_as_no_domain(): void
    {
        $this->registerModelsAndLoadRoutes(
            ['posts' => DomainPost::class],
            [
                'default' => [
                    'prefix' => '',
                    'domain' => '',
                    'middleware' => [],
                    'models' => '*',
                ],
            ]
        );

        $this->assertNull($this->getRouteByName('default.posts.index')->getDomain());
    }

    public function test_parameterized_domain_sets_route_domain(): void
    {
        $this->registerModelsAndLoadRoutes(
            ['posts' => DomainPost::class],
            [
                'tenant' => [
                    'prefix' => '',
                    'domain' => '{organization}.example.com',
                    'middleware' => [],
                    'models' => '*',
                ],
            ]
        );

        $this->assertEquals('{organization}.example.com', $this->getRouteByName('tenant.posts.index')->getDomain());
    }

    public function test_two_groups_sharing_prefix_but_different_domains_both_register(): void
    {
        $this->registerModelsAndLoadRoutes(
            ['posts' => DomainPost::class],
            [
                'us' => [
                    'prefix' => '',
                    'domain' => 'us.example.com',
                    'middleware' => [],
                    'models' => '*',
                ],
                'eu' => [
                    'prefix' => '',
                    'domain' => 'eu.example.com',
                    'middleware' => [],
                    'models' => '*',
                ],
            ]
        );

        // Same prefix/URI, distinct route names, distinct domains.
        $us = $this->getRouteByName('us.posts.index');
        $eu = $this->getRouteByName('eu.posts.index');

        $this->assertNotNull($us);
        $this->assertNotNull($eu);
        $this->assertEquals('api/posts', $us->uri());
        $this->assertEquals('api/posts', $eu->uri());
        $this->assertEquals('us.example.com', $us->getDomain());
        $this->assertEquals('eu.example.com', $eu->getDomain());
    }

    public function test_domain_and_prefix_combine(): void
    {
        $this->registerModelsAndLoadRoutes(
            ['posts' => DomainPost::class],
            [
                'admin' => [
                    'prefix' => 'admin',
                    'domain' => 'admin.example.com',
                    'middleware' => [],
                    'models' => '*',
                ],
            ]
        );

        $route = $this->getRouteByName('admin.posts.index');
        $this->assertEquals('api/admin/posts', $route->uri());
        $this->assertEquals('admin.example.com', $route->getDomain());
    }

    public function test_tenant_domain_applies_to_invitation_routes(): void
    {
        $this->registerModelsAndLoadRoutes(
            ['posts' => DomainPost::class],
            [
                'tenant' => [
                    'prefix' => '',
                    'domain' => '{organization}.example.com',
                    'middleware' => [\Rhino\Http\Middleware\ResolveOrganizationFromRoute::class],
                    'models' => '*',
                ],
            ]
        );

        // Invitation routes are registered for the tenant group; they must
        // inherit the tenant domain.
        $invitationRoute = null;
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->uri() === 'api/invitations' && in_array('GET', $route->methods())) {
                $invitationRoute = $route;
                break;
            }
        }

        $this->assertNotNull($invitationRoute, 'Invitation index route should be registered');
        $this->assertEquals('{organization}.example.com', $invitationRoute->getDomain());
    }

    public function test_tenant_domain_applies_to_nested_route(): void
    {
        $this->registerModelsAndLoadRoutes(
            ['posts' => DomainPost::class],
            [
                'tenant' => [
                    'prefix' => '',
                    'domain' => '{organization}.example.com',
                    'middleware' => [\Rhino\Http\Middleware\ResolveOrganizationFromRoute::class],
                    'models' => '*',
                ],
            ]
        );

        $this->assertEquals('{organization}.example.com', $this->getRouteByName('nested')->getDomain());
    }

    public function test_nested_route_has_no_domain_without_tenant_group(): void
    {
        $this->registerModelsAndLoadRoutes(
            ['posts' => DomainPost::class],
            [
                'default' => [
                    'prefix' => '',
                    'middleware' => [],
                    'models' => '*',
                ],
            ]
        );

        $this->assertNull($this->getRouteByName('nested')->getDomain());
    }

    // ==================================================================
    // HTTP behavior: the host selects which group answers
    // ==================================================================

    public function test_request_to_matching_host_reaches_group(): void
    {
        Gate::policy(DomainCategory::class, DomainAllowAllPolicy::class);

        $this->registerModelsAndLoadRoutes(
            ['categories' => DomainCategory::class],
            [
                'public' => [
                    'prefix' => '',
                    'domain' => 'public.example.com',
                    'middleware' => [],
                    'models' => ['categories'],
                ],
            ]
        );

        DomainCategory::forceCreate(['name' => 'Test Category']);

        $response = $this->getJson('http://public.example.com/api/categories');
        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
    }

    public function test_request_to_nonmatching_host_returns_404(): void
    {
        Gate::policy(DomainCategory::class, DomainAllowAllPolicy::class);

        $this->registerModelsAndLoadRoutes(
            ['categories' => DomainCategory::class],
            [
                'public' => [
                    'prefix' => '',
                    'domain' => 'public.example.com',
                    'middleware' => [],
                    'models' => ['categories'],
                ],
            ]
        );

        DomainCategory::forceCreate(['name' => 'Test Category']);

        $response = $this->getJson('http://other.example.com/api/categories');
        $response->assertStatus(404);
    }

    public function test_host_selects_correct_group_when_prefix_is_shared(): void
    {
        Gate::policy(DomainCategory::class, DomainAllowAllPolicy::class);

        // Two groups, same prefix and same model, distinguished only by host.
        // 'public' skips auth; 'secure' requires auth:sanctum.
        $this->registerModelsAndLoadRoutes(
            ['categories' => DomainCategory::class],
            [
                'public' => [
                    'prefix' => '',
                    'domain' => 'public.example.com',
                    'middleware' => [],
                    'models' => ['categories'],
                ],
                'secure' => [
                    'prefix' => '',
                    'domain' => 'secure.example.com',
                    'middleware' => [],
                    'models' => ['categories'],
                ],
            ]
        );

        DomainCategory::forceCreate(['name' => 'Test Category']);

        // The public host resolves the auth-free public group.
        $this->getJson('http://public.example.com/api/categories')->assertStatus(200);

        // The secure host resolves the authenticated group; no user -> 401.
        $this->getJson('http://secure.example.com/api/categories')->assertStatus(401);

        // An unrelated host matches neither group -> 404.
        $this->getJson('http://nope.example.com/api/categories')->assertStatus(404);
    }

    // ==================================================================
    // Parameterized domain drives organization resolution (subdomain tenancy)
    // ==================================================================

    public function test_parameterized_domain_resolves_organization_from_subdomain(): void
    {
        Gate::policy(DomainPost::class, DomainAllowAllPolicy::class);

        $this->registerModelsAndLoadRoutes(
            ['posts' => DomainPost::class],
            [
                'tenant' => [
                    'prefix' => '',
                    'domain' => '{organization}.example.com',
                    'middleware' => [\Rhino\Http\Middleware\ResolveOrganizationFromRoute::class],
                    'models' => '*',
                ],
            ],
            ['organization_identifier_column' => 'slug']
        );

        $org1 = \App\Models\Organization::forceCreate(['name' => 'Org One', 'slug' => 'org-one', 'domain' => null]);
        $org2 = \App\Models\Organization::forceCreate(['name' => 'Org Two', 'slug' => 'org-two', 'domain' => null]);

        DomainPost::forceCreate(['title' => 'Org1 Post', 'organization_id' => $org1->id]);
        DomainPost::forceCreate(['title' => 'Org2 Post', 'organization_id' => $org2->id]);

        $this->createAuthenticatedUser(['*'], ['org-one', 'org-two']);

        $response = $this->getJson('http://org-one.example.com/api/posts');
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('Org1 Post', $data[0]['title']);
    }

    public function test_parameterized_domain_isolates_tenants_per_subdomain(): void
    {
        Gate::policy(DomainPost::class, DomainAllowAllPolicy::class);

        $this->registerModelsAndLoadRoutes(
            ['posts' => DomainPost::class],
            [
                'tenant' => [
                    'prefix' => '',
                    'domain' => '{organization}.example.com',
                    'middleware' => [\Rhino\Http\Middleware\ResolveOrganizationFromRoute::class],
                    'models' => '*',
                ],
            ],
            ['organization_identifier_column' => 'slug']
        );

        $org1 = \App\Models\Organization::forceCreate(['name' => 'Org One', 'slug' => 'org-one', 'domain' => null]);
        $org2 = \App\Models\Organization::forceCreate(['name' => 'Org Two', 'slug' => 'org-two', 'domain' => null]);

        DomainPost::forceCreate(['title' => 'Org1 Post', 'organization_id' => $org1->id]);
        DomainPost::forceCreate(['title' => 'Org2 Post', 'organization_id' => $org2->id]);

        // The user belongs to both organizations.
        $this->createAuthenticatedUser(['*'], ['org-one', 'org-two']);

        // The subdomain selects the tenant scope: org-two host sees only org2 data.
        $response = $this->getJson('http://org-two.example.com/api/posts');
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('Org2 Post', $data[0]['title']);
    }

    public function test_parameterized_domain_unknown_subdomain_returns_404(): void
    {
        Gate::policy(DomainPost::class, DomainAllowAllPolicy::class);

        $this->registerModelsAndLoadRoutes(
            ['posts' => DomainPost::class],
            [
                'tenant' => [
                    'prefix' => '',
                    'domain' => '{organization}.example.com',
                    'middleware' => [\Rhino\Http\Middleware\ResolveOrganizationFromRoute::class],
                    'models' => '*',
                ],
            ],
            ['organization_identifier_column' => 'slug']
        );

        \App\Models\Organization::forceCreate(['name' => 'Org One', 'slug' => 'org-one', 'domain' => null]);

        $this->createAuthenticatedUser(['*'], ['org-one']);

        // 'ghost' matches the domain pattern but no such organization exists.
        $response = $this->getJson('http://ghost.example.com/api/posts');
        $response->assertStatus(404);
    }

    public function test_parameterized_domain_denies_non_member_subdomain(): void
    {
        Gate::policy(DomainPost::class, DomainAllowAllPolicy::class);

        $this->registerModelsAndLoadRoutes(
            ['posts' => DomainPost::class],
            [
                'tenant' => [
                    'prefix' => '',
                    'domain' => '{organization}.example.com',
                    'middleware' => [\Rhino\Http\Middleware\ResolveOrganizationFromRoute::class],
                    'models' => '*',
                ],
            ],
            ['organization_identifier_column' => 'slug']
        );

        \App\Models\Organization::forceCreate(['name' => 'Org One', 'slug' => 'org-one', 'domain' => null]);
        \App\Models\Organization::forceCreate(['name' => 'Org Two', 'slug' => 'org-two', 'domain' => null]);

        // User is a member of org-one only.
        $this->createAuthenticatedUser(['*'], ['org-one']);

        // Requesting org-two's subdomain -> middleware rejects with 404.
        $response = $this->getJson('http://org-two.example.com/api/posts');
        $response->assertStatus(404);
    }

    public function test_domain_plus_prefix_request_succeeds_on_matching_host(): void
    {
        Gate::policy(DomainPost::class, DomainAllowAllPolicy::class);

        $this->registerModelsAndLoadRoutes(
            ['posts' => DomainPost::class],
            [
                'admin' => [
                    'prefix' => 'admin',
                    'domain' => 'admin.example.com',
                    'middleware' => [],
                    'models' => '*',
                ],
            ]
        );

        $this->createAuthenticatedUser();
        DomainPost::forceCreate(['title' => 'Admin Post']);

        $this->getJson('http://admin.example.com/api/admin/posts')->assertStatus(200);

        // Right path, wrong host -> 404.
        $this->getJson('http://other.example.com/api/admin/posts')->assertStatus(404);
    }

    // ==================================================================
    // Domain parameters match a single host label only
    // ==================================================================

    public function test_parameterized_domain_does_not_match_multi_label_host(): void
    {
        Gate::policy(DomainPost::class, DomainAllowAllPolicy::class);

        $this->registerModelsAndLoadRoutes(
            ['posts' => DomainPost::class],
            [
                'tenant' => [
                    'prefix' => '',
                    'domain' => '{organization}.example.com',
                    'middleware' => [\Rhino\Http\Middleware\ResolveOrganizationFromRoute::class],
                    'models' => '*',
                ],
            ],
            ['organization_identifier_column' => 'slug']
        );

        \App\Models\Organization::forceCreate(['name' => 'Org One', 'slug' => 'org-one', 'domain' => null]);
        $this->createAuthenticatedUser(['*'], ['org-one']);

        // The domain parameter is constrained to one label ([^.]+), so a
        // multi-label host cannot capture a dotted value like 'org-one.evil'.
        $this->getJson('http://org-one.evil.example.com/api/posts')->assertStatus(404);
        $this->getJson('http://a.b.example.com/api/posts')->assertStatus(404);
    }

    public function test_parameterized_domain_constrains_param_to_single_label(): void
    {
        $this->registerModelsAndLoadRoutes(
            ['posts' => DomainPost::class],
            [
                'tenant' => [
                    'prefix' => '',
                    'domain' => '{organization}.example.com',
                    'middleware' => [],
                    'models' => '*',
                ],
            ]
        );

        $route = $this->getRouteByName('tenant.posts.index');
        $this->assertEquals('[^.]+', $route->wheres['organization'] ?? null);
    }

    public function test_parameterized_domain_matches_single_label_host(): void
    {
        Gate::policy(DomainPost::class, DomainAllowAllPolicy::class);

        $this->registerModelsAndLoadRoutes(
            ['posts' => DomainPost::class],
            [
                'tenant' => [
                    'prefix' => '',
                    'domain' => '{organization}.example.com',
                    'middleware' => [\Rhino\Http\Middleware\ResolveOrganizationFromRoute::class],
                    'models' => '*',
                ],
            ],
            ['organization_identifier_column' => 'slug']
        );

        $org = \App\Models\Organization::forceCreate(['name' => 'Org One', 'slug' => 'org-one', 'domain' => null]);
        DomainPost::forceCreate(['title' => 'Org1 Post', 'organization_id' => $org->id]);
        $this->createAuthenticatedUser(['*'], ['org-one']);

        $this->getJson('http://org-one.example.com/api/posts')->assertStatus(200);
    }

    // ==================================================================
    // Write methods and soft-delete routes respect the domain
    // ==================================================================

    public function test_write_methods_respect_the_domain(): void
    {
        Gate::policy(DomainPost::class, DomainAllowAllPolicy::class);

        $this->registerModelsAndLoadRoutes(
            ['posts' => DomainPost::class],
            [
                'admin' => [
                    'prefix' => '',
                    'domain' => 'admin.example.com',
                    'middleware' => [],
                    'models' => '*',
                ],
            ]
        );

        $this->createAuthenticatedUser();

        // POST succeeds on the matching host.
        $this->postJson('http://admin.example.com/api/posts', ['title' => 'Created'])
            ->assertStatus(201);
        $this->assertDatabaseHas('dom_posts', ['title' => 'Created']);

        // POST to the wrong host does not match the route.
        $this->postJson('http://other.example.com/api/posts', ['title' => 'Nope'])
            ->assertStatus(404);
        $this->assertDatabaseMissing('dom_posts', ['title' => 'Nope']);
    }

    public function test_soft_delete_routes_inherit_group_domain(): void
    {
        $this->registerModelsAndLoadRoutes(
            ['soft-posts' => DomainSoftPost::class],
            [
                'admin' => [
                    'prefix' => '',
                    'domain' => 'admin.example.com',
                    'middleware' => [],
                    'models' => '*',
                ],
            ]
        );

        // Every generated route for the group — including the soft-delete
        // branches registered before the {id} routes — carries the domain.
        foreach (['index', 'store', 'show', 'update', 'destroy', 'trashed', 'restore', 'forceDelete'] as $action) {
            $route = $this->getRouteByName("admin.soft-posts.{$action}");
            $this->assertNotNull($route, "Route admin.soft-posts.{$action} should exist");
            $this->assertEquals('admin.example.com', $route->getDomain(), "Action {$action} should inherit the domain");
        }
    }

    // ==================================================================
    // Host matching is case-insensitive (DNS)
    // ==================================================================

    public function test_literal_domain_matches_case_insensitively(): void
    {
        Gate::policy(DomainCategory::class, DomainAllowAllPolicy::class);

        $this->registerModelsAndLoadRoutes(
            ['categories' => DomainCategory::class],
            [
                'public' => [
                    'prefix' => '',
                    'domain' => 'public.example.com',
                    'middleware' => [],
                    'models' => ['categories'],
                ],
            ]
        );

        DomainCategory::forceCreate(['name' => 'Test Category']);

        // DNS hostnames are case-insensitive; Laravel compiles the host regex
        // case-insensitively, so a mixed-case host still resolves the group.
        $this->getJson('http://PUBLIC.example.com/api/categories')->assertStatus(200);
    }
}

// --------------------------------------------------------------------------
// Test Policies
// --------------------------------------------------------------------------

class DomainAllowAllPolicy
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
