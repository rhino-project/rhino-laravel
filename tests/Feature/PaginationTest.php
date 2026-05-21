<?php

namespace Rhino\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
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

class PaginatedPost extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'paginated_posts';
    protected $fillable = ['title', 'content'];
}

class PaginatedPostWithPaginationEnabled extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'paginated_posts';
    protected $fillable = ['title', 'content'];

    public static bool $paginationEnabled = true;
    protected $perPage = 5;
}

class PaginatedPostWithCustomPerPage extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'paginated_posts';
    protected $fillable = ['title', 'content'];

    public static bool $paginationEnabled = true;
    protected $perPage = 3;
}

// --------------------------------------------------------------------------
// Test Policy
// --------------------------------------------------------------------------

class PaginatedPostPolicy
{
    public function viewAny(?Authenticatable $user): bool
    {
        return true;
    }

    public function view(?Authenticatable $user, $post): bool
    {
        return true;
    }

    public function create(?Authenticatable $user): bool
    {
        return true;
    }

    public function update(?Authenticatable $user, $post): bool
    {
        return true;
    }

    public function delete(?Authenticatable $user, $post): bool
    {
        return true;
    }
}

// --------------------------------------------------------------------------
// Tests
// --------------------------------------------------------------------------

class PaginationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Create the test table
        Schema::create('paginated_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        // Run user migrations for actingAs support
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Register policy for all test models
        Gate::policy(PaginatedPost::class, PaginatedPostPolicy::class);
        Gate::policy(PaginatedPostWithPaginationEnabled::class, PaginatedPostPolicy::class);
        Gate::policy(PaginatedPostWithCustomPerPage::class, PaginatedPostPolicy::class);
    }

    /**
     * Define environment setup — configure the sanctum guard so the controller can resolve it.
     */
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Register the 'sanctum' guard as a simple session guard pointing to the users provider
        $app['config']->set('auth.guards.sanctum', [
            'driver' => 'session',
            'provider' => 'users',
        ]);

        $app['config']->set('auth.providers.users', [
            'driver' => 'eloquent',
            'model' => \App\Models\User::class,
        ]);
    }

    /**
     * Helper: register models, load routes, and seed data.
     */
    protected function registerAndLoadRoutes(array $models, array $public = []): void
    {
        if (!empty($public)) {
            $nonPublicSlugs = array_diff(array_keys($models), $public);
            $routeGroups = [
                'public' => ['prefix' => '', 'middleware' => [], 'models' => $public],
            ];
            if (!empty($nonPublicSlugs)) {
                $routeGroups['default'] = ['prefix' => '', 'middleware' => [], 'models' => array_values($nonPublicSlugs)];
            }
        } else {
            $routeGroups = [
                'default' => ['prefix' => '', 'middleware' => [], 'models' => '*'],
            ];
        }

        config([
            'rhino.models' => $models,
            'rhino.route_groups' => $routeGroups,
            'rhino.multi_tenant' => [
                'organization_identifier_column' => 'id',
            ],
        ]);

        Route::prefix('api')->group(function () {
            require __DIR__ . '/../../routes/api.php';
        });
    }

    /**
     * Helper: seed N posts into the paginated_posts table.
     */
    protected function seedPosts(int $count): void
    {
        for ($i = 1; $i <= $count; $i++) {
            PaginatedPost::forceCreate([
                'title' => "Post {$i}",
                'content' => "Content for post {$i}",
            ]);
        }
    }

    /**
     * Helper: create and authenticate a test user.
     */
    protected function authenticateUser(): \App\Models\User
    {
        $user = \App\Models\User::forceCreate([
            'id' => 1,
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ]);

        $this->actingAs($user, 'sanctum');

        return $user;
    }

    // ------------------------------------------------------------------
    // Default behavior: no pagination
    // ------------------------------------------------------------------

    public function test_index_returns_flat_array_without_pagination(): void
    {
        $this->registerAndLoadRoutes(
            ['posts' => PaginatedPost::class],
            ['posts']
        );
        $this->seedPosts(20);

        $response = $this->getJson('/api/posts');

        $response->assertStatus(200);
        $data = $response->json('data');

        // Flat array of all results
        $this->assertIsArray($data);
        $this->assertCount(20, $data);
        $this->assertArrayHasKey('title', $data[0]);

        // No pagination headers
        $response->assertHeaderMissing('X-Total');
    }

    // ------------------------------------------------------------------
    // ?per_page query param triggers pagination
    // ------------------------------------------------------------------

    public function test_per_page_returns_flat_array_with_pagination_headers(): void
    {
        $this->registerAndLoadRoutes(
            ['posts' => PaginatedPost::class],
            ['posts']
        );
        $this->seedPosts(15);

        $response = $this->getJson('/api/posts?per_page=5');

        $response->assertStatus(200);
        $data = $response->json('data');

        // Body is a flat array
        $this->assertIsArray($data);
        $this->assertCount(5, $data);
        $this->assertArrayHasKey('title', $data[0]);

        // Pagination meta in headers
        $response->assertHeader('X-Current-Page', '1');
        $response->assertHeader('X-Last-Page', '3');
        $response->assertHeader('X-Per-Page', '5');
        $response->assertHeader('X-Total', '15');
    }

    public function test_pagination_navigates_to_second_page(): void
    {
        $this->registerAndLoadRoutes(
            ['posts' => PaginatedPost::class],
            ['posts']
        );
        $this->seedPosts(15);

        $response = $this->getJson('/api/posts?per_page=5&page=2');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(5, $data);
        $response->assertHeader('X-Current-Page', '2');
        $this->assertEquals('Post 6', $data[0]['title']);
    }

    public function test_pagination_last_page_returns_remaining_items(): void
    {
        $this->registerAndLoadRoutes(
            ['posts' => PaginatedPost::class],
            ['posts']
        );
        $this->seedPosts(12);

        $response = $this->getJson('/api/posts?per_page=5&page=3');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(2, $data); // 12 total, 5 per page, page 3 = 2 items
        $response->assertHeader('X-Current-Page', '3');
        $response->assertHeader('X-Last-Page', '3');
    }

    // ------------------------------------------------------------------
    // per_page clamping
    // ------------------------------------------------------------------

    public function test_per_page_is_clamped_to_minimum_of_1(): void
    {
        $this->registerAndLoadRoutes(
            ['posts' => PaginatedPost::class],
            ['posts']
        );
        $this->seedPosts(5);

        $response = $this->getJson('/api/posts?per_page=0');

        $response->assertStatus(200);
        $data = $response->json();

        // 0 should be clamped to 1
        $response->assertHeader('X-Per-Page', '1');
        $this->assertCount(1, $data);
    }

    public function test_per_page_is_clamped_to_maximum_of_100(): void
    {
        $this->registerAndLoadRoutes(
            ['posts' => PaginatedPost::class],
            ['posts']
        );
        $this->seedPosts(5);

        $response = $this->getJson('/api/posts?per_page=500');

        $response->assertStatus(200);
        $response->assertHeader('X-Per-Page', '100');
    }

    public function test_negative_per_page_is_clamped_to_1(): void
    {
        $this->registerAndLoadRoutes(
            ['posts' => PaginatedPost::class],
            ['posts']
        );
        $this->seedPosts(5);

        $response = $this->getJson('/api/posts?per_page=-10');

        $response->assertStatus(200);
        $response->assertHeader('X-Per-Page', '1');
    }

    // ------------------------------------------------------------------
    // Model-level $paginationEnabled
    // ------------------------------------------------------------------

    public function test_pagination_enabled_on_model_paginates_by_default(): void
    {
        $this->registerAndLoadRoutes(
            ['posts' => PaginatedPostWithPaginationEnabled::class],
            ['posts']
        );
        $this->seedPosts(12);

        $response = $this->getJson('/api/posts');

        $response->assertStatus(200);
        $data = $response->json('data');

        // Model has $paginationEnabled = true, $perPage = 5
        $this->assertCount(5, $data);
        $response->assertHeader('X-Per-Page', '5');
        $response->assertHeader('X-Total', '12');
        $response->assertHeader('X-Last-Page', '3'); // ceil(12/5) = 3
    }

    public function test_per_page_query_param_overrides_model_default(): void
    {
        $this->registerAndLoadRoutes(
            ['posts' => PaginatedPostWithPaginationEnabled::class],
            ['posts']
        );
        $this->seedPosts(12);

        // Model default is 5, but we request 10
        $response = $this->getJson('/api/posts?per_page=10');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(10, $data);
        $response->assertHeader('X-Per-Page', '10');
        $response->assertHeader('X-Last-Page', '2'); // ceil(12/10) = 2
    }

    public function test_custom_per_page_on_model(): void
    {
        $this->registerAndLoadRoutes(
            ['posts' => PaginatedPostWithCustomPerPage::class],
            ['posts']
        );
        $this->seedPosts(10);

        $response = $this->getJson('/api/posts');

        $response->assertStatus(200);
        $data = $response->json('data');

        // Model has $perPage = 3
        $this->assertCount(3, $data);
        $response->assertHeader('X-Per-Page', '3');
        $response->assertHeader('X-Total', '10');
        $response->assertHeader('X-Last-Page', '4'); // ceil(10/3) = 4
    }

    // ------------------------------------------------------------------
    // Response format consistency
    // ------------------------------------------------------------------

    public function test_paginated_and_non_paginated_return_same_format(): void
    {
        $this->registerAndLoadRoutes(
            ['posts' => PaginatedPost::class],
            ['posts']
        );
        $this->seedPosts(3);

        $withoutPagination = $this->getJson('/api/posts')->json();
        $withPagination = $this->getJson('/api/posts?per_page=10')->json();

        // Both are flat arrays with identical content
        $this->assertEquals($withoutPagination, $withPagination);
    }

    // ------------------------------------------------------------------
    // Pagination with authenticated routes
    // ------------------------------------------------------------------

    public function test_pagination_works_with_authenticated_routes(): void
    {
        $this->registerAndLoadRoutes(
            ['posts' => PaginatedPost::class],
            [] // NOT public — requires auth
        );
        $this->seedPosts(10);
        $this->authenticateUser();

        $response = $this->getJson('/api/posts?per_page=3');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(3, $data);
        $response->assertHeader('X-Total', '10');
    }

    // ------------------------------------------------------------------
    // Empty results
    // ------------------------------------------------------------------

    public function test_pagination_with_no_results(): void
    {
        $this->registerAndLoadRoutes(
            ['posts' => PaginatedPost::class],
            ['posts']
        );

        $response = $this->getJson('/api/posts?per_page=5');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertIsArray($data);
        $this->assertCount(0, $data);
        $response->assertHeader('X-Total', '0');
        $response->assertHeader('X-Current-Page', '1');
    }

    public function test_no_pagination_with_no_results(): void
    {
        $this->registerAndLoadRoutes(
            ['posts' => PaginatedPost::class],
            ['posts']
        );

        $response = $this->getJson('/api/posts');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertIsArray($data);
        $this->assertCount(0, $data);
    }
}
