<?php

namespace Rhino\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Route;
use Rhino\Tests\TestCase;
use Rhino\Traits\HasValidation;
use Rhino\Traits\HidableColumns;

// --------------------------------------------------------------------------
// Test Models
// --------------------------------------------------------------------------

class RoutablePost extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'routable_posts';
    protected $fillable = ['title'];
}

class RoutablePostWithMiddleware extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'routable_posts';
    protected $fillable = ['title'];

    public static array $middleware = ['throttle:60,1'];

    public static array $middlewareActions = [
        'store' => ['verified'],
        'update' => ['verified'],
    ];
}

class RoutablePostWithExcept extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'routable_posts';
    protected $fillable = ['title'];

    public static array $exceptActions = ['destroy', 'update'];
}

// --------------------------------------------------------------------------
// Tests
// --------------------------------------------------------------------------

class RouteRegistrationTest extends TestCase
{
    /**
     * Helper: register models in config and load the route file.
     */
    protected function registerModelsAndLoadRoutes(array $models, array $routeGroups = [], array $multiTenant = []): void
    {
        if (empty($routeGroups)) {
            $routeGroups = [
                'default' => [
                    'prefix' => '',
                    'middleware' => [],
                    'models' => '*',
                ],
            ];
        }

        $defaultMultiTenant = [
            'organization_identifier_column' => 'id',
        ];

        config([
            'rhino.models' => $models,
            'rhino.route_groups' => $routeGroups,
            'rhino.multi_tenant' => array_merge($defaultMultiTenant, $multiTenant),
        ]);

        // Load routes within the api prefix to match real app behavior
        Route::prefix('api')->group(function () {
            require __DIR__ . '/../../routes/api.php';
        });
    }

    /**
     * Helper: get all registered route names.
     */
    protected function getRouteNames(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter()
            ->values()
            ->toArray();
    }

    /**
     * Helper: get route by name (iterates collection to avoid stale name lookup cache).
     */
    protected function getRouteByName(string $name): ?\Illuminate\Routing\Route
    {
        foreach (Route::getRoutes()->getRoutes() as $route) {
            if ($route->getName() === $name) {
                return $route;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Basic route registration
    // ------------------------------------------------------------------

    public function test_registers_all_crud_routes_for_model(): void
    {
        $this->registerModelsAndLoadRoutes([
            'posts' => RoutablePost::class,
        ]);

        $names = $this->getRouteNames();

        $this->assertContains('default.posts.index', $names);
        $this->assertContains('default.posts.store', $names);
        $this->assertContains('default.posts.show', $names);
        $this->assertContains('default.posts.update', $names);
        $this->assertContains('default.posts.destroy', $names);
    }

    public function test_registers_routes_for_multiple_models(): void
    {
        $this->registerModelsAndLoadRoutes([
            'posts' => RoutablePost::class,
            'comments' => RoutablePostWithMiddleware::class,
        ]);

        $names = $this->getRouteNames();

        $this->assertContains('default.posts.index', $names);
        $this->assertContains('default.posts.store', $names);
        $this->assertContains('default.comments.index', $names);
        $this->assertContains('default.comments.store', $names);
    }

    public function test_routes_have_correct_http_methods(): void
    {
        $this->registerModelsAndLoadRoutes([
            'posts' => RoutablePost::class,
        ]);

        $this->assertEquals(['GET', 'HEAD'], $this->getRouteByName('default.posts.index')->methods());
        $this->assertEquals(['POST'], $this->getRouteByName('default.posts.store')->methods());
        $this->assertEquals(['GET', 'HEAD'], $this->getRouteByName('default.posts.show')->methods());
        $this->assertEquals(['PUT'], $this->getRouteByName('default.posts.update')->methods());
        $this->assertEquals(['DELETE'], $this->getRouteByName('default.posts.destroy')->methods());
    }

    public function test_routes_have_correct_uri_without_multi_tenant(): void
    {
        $this->registerModelsAndLoadRoutes([
            'posts' => RoutablePost::class,
        ]);

        $this->assertEquals('api/posts', $this->getRouteByName('default.posts.index')->uri());
        $this->assertEquals('api/posts', $this->getRouteByName('default.posts.store')->uri());
        $this->assertEquals('api/posts/{id}', $this->getRouteByName('default.posts.show')->uri());
        $this->assertEquals('api/posts/{id}', $this->getRouteByName('default.posts.update')->uri());
        $this->assertEquals('api/posts/{id}', $this->getRouteByName('default.posts.destroy')->uri());
    }

    // ------------------------------------------------------------------
    // Multi-tenant route prefix (tenant route group)
    // ------------------------------------------------------------------

    public function test_routes_have_organization_prefix_with_tenant_route_group(): void
    {
        $this->registerModelsAndLoadRoutes(
            ['posts' => RoutablePost::class],
            [
                'tenant' => [
                    'prefix' => '{organization}',
                    'middleware' => [],
                    'models' => '*',
                ],
            ]
        );

        $this->assertEquals('api/{organization}/posts', $this->getRouteByName('tenant.posts.index')->uri());
        $this->assertEquals('api/{organization}/posts/{id}', $this->getRouteByName('tenant.posts.show')->uri());
    }

    public function test_routes_have_no_organization_prefix_with_empty_prefix_tenant_group(): void
    {
        $this->registerModelsAndLoadRoutes(
            ['posts' => RoutablePost::class],
            [
                'tenant' => [
                    'prefix' => '',
                    'middleware' => [],
                    'models' => '*',
                ],
            ]
        );

        $this->assertEquals('api/posts', $this->getRouteByName('tenant.posts.index')->uri());
        $this->assertEquals('api/posts/{id}', $this->getRouteByName('tenant.posts.show')->uri());
    }

    // ------------------------------------------------------------------
    // Middleware
    // ------------------------------------------------------------------

    public function test_auth_middleware_applied_to_non_public_group(): void
    {
        $this->registerModelsAndLoadRoutes(
            ['posts' => RoutablePost::class]
        );

        $middleware = $this->getRouteByName('default.posts.index')->middleware();
        $this->assertContains('auth:sanctum', $middleware);
    }

    public function test_auth_middleware_not_applied_to_public_group(): void
    {
        $this->registerModelsAndLoadRoutes(
            ['posts' => RoutablePost::class],
            [
                'public' => [
                    'prefix' => '',
                    'middleware' => [],
                    'models' => ['posts'],
                ],
            ]
        );

        $middleware = $this->getRouteByName('public.posts.index')->middleware();
        $this->assertNotContains('auth:sanctum', $middleware);
    }

    public function test_model_level_middleware_applied_to_all_actions(): void
    {
        $this->registerModelsAndLoadRoutes([
            'posts' => RoutablePostWithMiddleware::class,
        ]);

        $this->assertContains('throttle:60,1', $this->getRouteByName('default.posts.index')->middleware());
        $this->assertContains('throttle:60,1', $this->getRouteByName('default.posts.store')->middleware());
        $this->assertContains('throttle:60,1', $this->getRouteByName('default.posts.show')->middleware());
        $this->assertContains('throttle:60,1', $this->getRouteByName('default.posts.update')->middleware());
        $this->assertContains('throttle:60,1', $this->getRouteByName('default.posts.destroy')->middleware());
    }

    public function test_per_action_middleware_applied_only_to_specified_actions(): void
    {
        $this->registerModelsAndLoadRoutes([
            'posts' => RoutablePostWithMiddleware::class,
        ]);

        $this->assertContains('verified', $this->getRouteByName('default.posts.store')->middleware());
        $this->assertContains('verified', $this->getRouteByName('default.posts.update')->middleware());

        $this->assertNotContains('verified', $this->getRouteByName('default.posts.index')->middleware());
        $this->assertNotContains('verified', $this->getRouteByName('default.posts.show')->middleware());
        $this->assertNotContains('verified', $this->getRouteByName('default.posts.destroy')->middleware());
    }

    public function test_tenant_group_middleware_applied(): void
    {
        $this->registerModelsAndLoadRoutes(
            ['posts' => RoutablePost::class],
            [
                'tenant' => [
                    'prefix' => '{organization}',
                    'middleware' => ['App\Http\Middleware\ResolveOrganizationFromRoute'],
                    'models' => '*',
                ],
            ]
        );

        $middleware = $this->getRouteByName('tenant.posts.index')->middleware();
        $this->assertContains('App\Http\Middleware\ResolveOrganizationFromRoute', $middleware);
    }

    // ------------------------------------------------------------------
    // Except actions
    // ------------------------------------------------------------------

    public function test_excepted_actions_are_not_registered(): void
    {
        $this->registerModelsAndLoadRoutes([
            'posts' => RoutablePostWithExcept::class,
        ]);

        $names = $this->getRouteNames();

        $this->assertNotContains('default.posts.destroy', $names);
        $this->assertNotContains('default.posts.update', $names);

        $this->assertContains('default.posts.index', $names);
        $this->assertContains('default.posts.store', $names);
        $this->assertContains('default.posts.show', $names);
    }

    // ------------------------------------------------------------------
    // Route defaults
    // ------------------------------------------------------------------

    public function test_model_slug_passed_via_route_defaults(): void
    {
        $this->registerModelsAndLoadRoutes([
            'posts' => RoutablePost::class,
        ]);

        $route = $this->getRouteByName('default.posts.index');
        $defaults = $route->defaults;

        $this->assertArrayHasKey('model', $defaults);
        $this->assertEquals('posts', $defaults['model']);
        $this->assertArrayHasKey('route_group', $defaults);
        $this->assertEquals('default', $defaults['route_group']);
    }

    // ------------------------------------------------------------------
    // Empty config
    // ------------------------------------------------------------------

    public function test_no_crud_routes_registered_when_no_models_configured(): void
    {
        $this->registerModelsAndLoadRoutes([]);

        $names = $this->getRouteNames();

        $this->assertNotContains('default.posts.index', $names);
    }
}
