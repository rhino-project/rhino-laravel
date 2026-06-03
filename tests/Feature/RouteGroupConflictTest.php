<?php

namespace Rhino\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Rhino\Exceptions\RouteGroupConflictException;
use Rhino\Routing\RouteGroupValidator;
use Rhino\Tests\TestCase;
use Rhino\Traits\HasValidation;
use Rhino\Traits\HidableColumns;

// --------------------------------------------------------------------------
// Test Models
// --------------------------------------------------------------------------

class ConflictPost extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'cf_posts';
    protected $fillable = ['title', 'organization_id'];
}

class ConflictCategory extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'cf_categories';
    protected $fillable = ['name'];
}

// --------------------------------------------------------------------------
// Tests: route groups that would silently shadow each other must throw at boot.
// --------------------------------------------------------------------------

class RouteGroupConflictTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('cf_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->timestamps();
        });

        Schema::create('cf_categories', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    /**
     * Register routes through the real routes/api.php so the validation runs
     * exactly where it does at boot time.
     */
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

    protected function defaultModels(): array
    {
        return [
            'posts' => ConflictPost::class,
            'categories' => ConflictCategory::class,
        ];
    }

    // ==================================================================
    // Cases that MUST throw
    // ==================================================================

    public function test_two_root_groups_without_domain_throw(): void
    {
        $this->expectException(RouteGroupConflictException::class);

        $this->registerModelsAndLoadRoutes($this->defaultModels(), [
            'a' => ['prefix' => '', 'middleware' => [], 'models' => '*'],
            'b' => ['prefix' => '', 'middleware' => [], 'models' => '*'],
        ]);
    }

    public function test_wildcard_vs_subset_models_overlap_throws(): void
    {
        $this->expectException(RouteGroupConflictException::class);

        $this->registerModelsAndLoadRoutes($this->defaultModels(), [
            'a' => ['prefix' => '', 'middleware' => [], 'models' => '*'],
            'b' => ['prefix' => '', 'middleware' => [], 'models' => ['posts']],
        ]);
    }

    public function test_same_nonempty_prefix_without_domain_throws(): void
    {
        $this->expectException(RouteGroupConflictException::class);

        $this->registerModelsAndLoadRoutes($this->defaultModels(), [
            'a' => ['prefix' => 'admin', 'middleware' => [], 'models' => '*'],
            'b' => ['prefix' => 'admin', 'middleware' => [], 'models' => '*'],
        ]);
    }

    public function test_same_prefix_same_literal_domain_throws(): void
    {
        $this->expectException(RouteGroupConflictException::class);

        $this->registerModelsAndLoadRoutes($this->defaultModels(), [
            'a' => ['prefix' => '', 'domain' => 'app.example.com', 'middleware' => [], 'models' => '*'],
            'b' => ['prefix' => '', 'domain' => 'app.example.com', 'middleware' => [], 'models' => '*'],
        ]);
    }

    public function test_same_prefix_same_parameterized_domain_throws(): void
    {
        $this->expectException(RouteGroupConflictException::class);

        $this->registerModelsAndLoadRoutes($this->defaultModels(), [
            'a' => ['prefix' => '', 'domain' => '{organization}.example.com', 'middleware' => [], 'models' => '*'],
            'b' => ['prefix' => '', 'domain' => '{organization}.example.com', 'middleware' => [], 'models' => '*'],
        ]);
    }

    public function test_no_domain_catch_all_conflicts_with_domained_group(): void
    {
        // A group with no domain is a wildcard host, so it also answers on
        // admin.example.com — colliding with the domained group there.
        $this->expectException(RouteGroupConflictException::class);

        $this->registerModelsAndLoadRoutes($this->defaultModels(), [
            'catch_all' => ['prefix' => '', 'middleware' => [], 'models' => '*'],
            'admin' => ['prefix' => '', 'domain' => 'admin.example.com', 'middleware' => [], 'models' => '*'],
        ]);
    }

    public function test_only_conflicting_pair_among_many_throws_and_is_named(): void
    {
        try {
            $this->registerModelsAndLoadRoutes($this->defaultModels(), [
                'driver' => ['prefix' => 'driver', 'middleware' => [], 'models' => '*'],
                'a' => ['prefix' => '', 'middleware' => [], 'models' => ['posts']],
                'admin' => ['prefix' => 'admin', 'middleware' => [], 'models' => '*'],
                'b' => ['prefix' => '', 'middleware' => [], 'models' => ['posts']],
            ]);
            $this->fail('Expected RouteGroupConflictException was not thrown');
        } catch (RouteGroupConflictException $e) {
            $this->assertStringContainsString("'a'", $e->getMessage());
            $this->assertStringContainsString("'b'", $e->getMessage());
            // The non-conflicting groups must not be implicated.
            $this->assertStringNotContainsString("'driver'", $e->getMessage());
            $this->assertStringNotContainsString("'admin'", $e->getMessage());
        }
    }

    public function test_exception_message_names_groups_prefix_and_models(): void
    {
        try {
            $this->registerModelsAndLoadRoutes($this->defaultModels(), [
                'first' => ['prefix' => 'shared', 'middleware' => [], 'models' => ['posts']],
                'second' => ['prefix' => 'shared', 'middleware' => [], 'models' => ['posts']],
            ]);
            $this->fail('Expected RouteGroupConflictException was not thrown');
        } catch (RouteGroupConflictException $e) {
            $message = $e->getMessage();
            $this->assertStringContainsString("'first'", $message);
            $this->assertStringContainsString("'second'", $message);
            $this->assertStringContainsString("'shared'", $message);
            $this->assertStringContainsString('posts', $message);
        }
    }

    public function test_blank_domain_does_not_rescue_a_root_collision(): void
    {
        // domain '' is treated as no-domain, so this is the same as two plain
        // root groups -> still a conflict.
        $this->expectException(RouteGroupConflictException::class);

        $this->registerModelsAndLoadRoutes($this->defaultModels(), [
            'a' => ['prefix' => '', 'domain' => '', 'middleware' => [], 'models' => '*'],
            'b' => ['prefix' => '', 'middleware' => [], 'models' => '*'],
        ]);
    }

    public function test_null_and_empty_prefix_are_treated_as_the_same_root(): void
    {
        $this->expectException(RouteGroupConflictException::class);

        $this->registerModelsAndLoadRoutes($this->defaultModels(), [
            'a' => ['prefix' => null, 'middleware' => [], 'models' => '*'],
            'b' => ['prefix' => '', 'middleware' => [], 'models' => '*'],
        ]);
    }

    public function test_omitted_prefix_collides_with_explicit_root_prefix(): void
    {
        // 'a' omits 'prefix' entirely; it defaults to root and collides with 'b'.
        $this->expectException(RouteGroupConflictException::class);

        $this->registerModelsAndLoadRoutes($this->defaultModels(), [
            'a' => ['middleware' => [], 'models' => '*'],
            'b' => ['prefix' => '', 'middleware' => [], 'models' => '*'],
        ]);
    }

    // ==================================================================
    // Cases that must NOT throw (valid configs)
    // ==================================================================

    public function test_disjoint_models_at_root_without_domain_is_valid(): void
    {
        $this->registerModelsAndLoadRoutes($this->defaultModels(), [
            'a' => ['prefix' => '', 'middleware' => [], 'models' => ['posts']],
            'b' => ['prefix' => '', 'middleware' => [], 'models' => ['categories']],
        ]);

        $this->assertNotNull($this->getRouteByName('a.posts.index'));
        $this->assertNotNull($this->getRouteByName('b.categories.index'));
    }

    public function test_same_prefix_distinct_literal_domains_is_valid(): void
    {
        $this->registerModelsAndLoadRoutes($this->defaultModels(), [
            'us' => ['prefix' => '', 'domain' => 'us.example.com', 'middleware' => [], 'models' => '*'],
            'eu' => ['prefix' => '', 'domain' => 'eu.example.com', 'middleware' => [], 'models' => '*'],
        ]);

        $this->assertEquals('us.example.com', $this->getRouteByName('us.posts.index')->getDomain());
        $this->assertEquals('eu.example.com', $this->getRouteByName('eu.posts.index')->getDomain());
    }

    public function test_distinct_parameterized_domains_is_valid(): void
    {
        $this->registerModelsAndLoadRoutes($this->defaultModels(), [
            'a' => ['prefix' => '', 'domain' => '{organization}.a.example.com', 'middleware' => [], 'models' => '*'],
            'b' => ['prefix' => '', 'domain' => '{organization}.b.example.com', 'middleware' => [], 'models' => '*'],
        ]);

        $this->assertNotNull($this->getRouteByName('a.posts.index'));
        $this->assertNotNull($this->getRouteByName('b.posts.index'));
    }

    public function test_same_domain_different_prefixes_is_valid(): void
    {
        $this->registerModelsAndLoadRoutes($this->defaultModels(), [
            'a' => ['prefix' => 'v1', 'domain' => 'api.example.com', 'middleware' => [], 'models' => '*'],
            'b' => ['prefix' => 'v2', 'domain' => 'api.example.com', 'middleware' => [], 'models' => '*'],
        ]);

        $this->assertNotNull($this->getRouteByName('a.posts.index'));
        $this->assertNotNull($this->getRouteByName('b.posts.index'));
    }

    public function test_different_prefixes_without_domains_is_valid(): void
    {
        $this->registerModelsAndLoadRoutes($this->defaultModels(), [
            'driver' => ['prefix' => 'driver', 'middleware' => [], 'models' => '*'],
            'admin' => ['prefix' => 'admin', 'middleware' => [], 'models' => '*'],
        ]);

        $this->assertNotNull($this->getRouteByName('driver.posts.index'));
        $this->assertNotNull($this->getRouteByName('admin.posts.index'));
    }

    public function test_single_root_group_without_domain_is_valid(): void
    {
        $this->registerModelsAndLoadRoutes($this->defaultModels(), [
            'default' => ['prefix' => '', 'middleware' => [], 'models' => '*'],
        ]);

        $this->assertNotNull($this->getRouteByName('default.posts.index'));
    }

    public function test_single_group_with_domain_and_root_prefix_is_valid(): void
    {
        // The headline requirement: with a subdomain, the prefix is not
        // required — root on that host is allowed.
        $this->registerModelsAndLoadRoutes($this->defaultModels(), [
            'tenant' => [
                'prefix' => '',
                'domain' => '{organization}.example.com',
                'middleware' => [],
                'models' => '*',
            ],
        ]);

        $route = $this->getRouteByName('tenant.posts.index');
        $this->assertNotNull($route);
        $this->assertEquals('{organization}.example.com', $route->getDomain());
        $this->assertEquals('api/posts', $route->uri());
    }

    public function test_tenant_and_public_reserved_groups_with_distinct_prefixes_is_valid(): void
    {
        $this->registerModelsAndLoadRoutes($this->defaultModels(), [
            'tenant' => [
                'prefix' => '{organization}',
                'middleware' => [],
                'models' => '*',
            ],
            'public' => [
                'prefix' => 'public',
                'middleware' => [],
                'models' => ['categories'],
            ],
        ], ['organization_identifier_column' => 'slug']);

        $this->assertNotNull($this->getRouteByName('tenant.posts.index'));
        $this->assertNotNull($this->getRouteByName('public.categories.index'));
    }

    public function test_empty_route_groups_is_valid(): void
    {
        $this->registerModelsAndLoadRoutes($this->defaultModels(), []);

        $this->assertTrue(true); // no exception thrown
    }

    // ==================================================================
    // Unit-level checks against the validator directly
    // ==================================================================

    public function test_validator_throws_for_overlapping_root_groups(): void
    {
        $this->expectException(RouteGroupConflictException::class);

        RouteGroupValidator::validate(
            [
                'a' => ['prefix' => '', 'models' => '*'],
                'b' => ['prefix' => '', 'models' => '*'],
            ],
            ['posts' => ConflictPost::class]
        );
    }

    public function test_validator_passes_for_disjoint_models(): void
    {
        RouteGroupValidator::validate(
            [
                'a' => ['prefix' => '', 'models' => ['posts']],
                'b' => ['prefix' => '', 'models' => ['categories']],
            ],
            ['posts' => ConflictPost::class, 'categories' => ConflictCategory::class]
        );

        $this->assertTrue(true);
    }

    public function test_validator_passes_for_distinct_domains_same_prefix(): void
    {
        RouteGroupValidator::validate(
            [
                'a' => ['prefix' => '', 'domain' => 'a.example.com', 'models' => '*'],
                'b' => ['prefix' => '', 'domain' => 'b.example.com', 'models' => '*'],
            ],
            ['posts' => ConflictPost::class]
        );

        $this->assertTrue(true);
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
}
