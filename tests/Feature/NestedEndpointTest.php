<?php

namespace Rhino\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Facades\Route;
use Rhino\Tests\TestCase;
use Rhino\Traits\HasValidation;
use Rhino\Traits\HidableColumns;

// --------------------------------------------------------------------------
// Test Models
// --------------------------------------------------------------------------

class NestedBlog extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'nested_blogs';
    protected $fillable = ['title'];

    protected $validationRules = [
        'title' => 'required|string|max:255',
    ];
    protected $validationRulesStore = ['title'];
    protected $validationRulesUpdate = ['title'];
}

class NestedPost extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'nested_posts';
    protected $fillable = ['blog_id', 'title', 'content'];

    protected $validationRules = [
        'blog_id' => 'required|integer',
        'title' => 'required|string|max:255',
        'content' => 'nullable|string',
    ];
    protected $validationRulesStore = ['blog_id', 'title', 'content'];
    protected $validationRulesUpdate = ['title', 'content'];

    public function blog()
    {
        return $this->belongsTo(NestedBlog::class, 'blog_id');
    }
}

// --------------------------------------------------------------------------
// Test Policies
// --------------------------------------------------------------------------

class NestedBlogPolicy
{
    public function viewAny(?Authenticatable $user): bool { return true; }
    public function view(?Authenticatable $user, $model): bool { return true; }
    public function create(?Authenticatable $user): bool { return true; }
    public function update(?Authenticatable $user, $model): bool { return true; }
    public function delete(?Authenticatable $user, $model): bool { return true; }
}

class NestedPostPolicy
{
    public function viewAny(?Authenticatable $user): bool { return true; }
    public function view(?Authenticatable $user, $model): bool { return true; }
    public function create(?Authenticatable $user): bool { return true; }
    public function update(?Authenticatable $user, $model): bool { return true; }
    public function delete(?Authenticatable $user, $model): bool { return true; }
}

class NestedPostDenyCreatePolicy
{
    public function viewAny(?Authenticatable $user): bool { return true; }
    public function view(?Authenticatable $user, $model): bool { return true; }
    public function create(?Authenticatable $user): bool { return false; }
    public function update(?Authenticatable $user, $model): bool { return true; }
    public function delete(?Authenticatable $user, $model): bool { return true; }
}

class NestedPostDenyUpdatePolicy
{
    public function viewAny(?Authenticatable $user): bool { return true; }
    public function view(?Authenticatable $user, $model): bool { return true; }
    public function create(?Authenticatable $user): bool { return true; }
    public function update(?Authenticatable $user, $model): bool { return false; }
    public function delete(?Authenticatable $user, $model): bool { return true; }
}

// --------------------------------------------------------------------------
// Tests
// --------------------------------------------------------------------------

class NestedEndpointTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('nested_blogs', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('nested_posts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('blog_id');
            $table->string('title');
            $table->text('content')->nullable();
            $table->timestamps();
            $table->unique('title'); // used by rollback test to force constraint failure on second create
        });

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        Gate::policy(NestedBlog::class, NestedBlogPolicy::class);
        Gate::policy(NestedPost::class, NestedPostPolicy::class);
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

    protected function registerRoutes(array $models, array $nestedConfig = []): void
    {
        $config = [
            'rhino.models' => $models,
            'rhino.route_groups' => [
                'default' => ['prefix' => '', 'middleware' => [], 'models' => '*'],
            ],
            'rhino.multi_tenant' => [
                'organization_identifier_column' => 'id',
            ],
            'rhino.nested' => array_merge([
                'path' => 'nested',
                'max_operations' => 50,
                'allowed_models' => null,
            ], $nestedConfig),
        ];
        config($config);

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

    // ------------------------------------------------------------------
    // Structure validation
    // ------------------------------------------------------------------

    public function test_nested_missing_operations_returns_422(): void
    {
        $this->registerRoutes(['blogs' => NestedBlog::class, 'posts' => NestedPost::class]);
        $this->authenticate();

        $response = $this->postJson('/api/nested', []);

        $response->assertStatus(422);
        $response->assertJsonPath('errors.operations.0', 'The operations field is required and must be an array.');
    }

    public function test_nested_operations_not_array_returns_422(): void
    {
        $this->registerRoutes(['blogs' => NestedBlog::class, 'posts' => NestedPost::class]);
        $this->authenticate();

        $response = $this->postJson('/api/nested', ['operations' => 'not-array']);

        $response->assertStatus(422);
    }

    public function test_nested_operation_missing_id_for_update_returns_422(): void
    {
        $this->registerRoutes(['blogs' => NestedBlog::class]);
        $this->authenticate();

        $response = $this->postJson('/api/nested', [
            'operations' => [
                ['model' => 'blogs', 'action' => 'update', 'data' => ['title' => 'Foo']],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Invalid structure.');
        $this->assertStringContainsString('id', json_encode($response->json('errors')));
    }

    public function test_nested_operation_missing_data_returns_422(): void
    {
        $this->registerRoutes(['blogs' => NestedBlog::class]);
        $this->authenticate();

        $response = $this->postJson('/api/nested', [
            'operations' => [
                ['model' => 'blogs', 'action' => 'create'],
            ],
        ]);

        $response->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // Per-operation validation
    // ------------------------------------------------------------------

    public function test_nested_validation_failure_returns_422_and_no_db_changes(): void
    {
        $this->registerRoutes(['blogs' => NestedBlog::class, 'posts' => NestedPost::class]);
        $this->authenticate();

        $blog = NestedBlog::forceCreate(['title' => 'Original']);
        $initialCount = NestedPost::count();

        $response = $this->postJson('/api/nested', [
            'operations' => [
                ['model' => 'blogs', 'action' => 'update', 'id' => $blog->id, 'data' => ['title' => 'Updated']],
                ['model' => 'posts', 'action' => 'create', 'data' => ['blog_id' => $blog->id, 'title' => '']], // title required
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Validation failed.');
        $this->assertArrayHasKey('operations.1.data.title', $response->json('errors'));
        // First operation must not have been applied (no transaction started)
        $blog->refresh();
        $this->assertEquals('Original', $blog->title);
        $this->assertEquals($initialCount, NestedPost::count());
    }

    // ------------------------------------------------------------------
    // Policy
    // ------------------------------------------------------------------

    public function test_nested_policy_deny_create_returns_403(): void
    {
        $this->registerRoutes(['blogs' => NestedBlog::class, 'posts' => NestedPost::class]);
        Gate::policy(NestedPost::class, NestedPostDenyCreatePolicy::class);
        $this->authenticate();

        $blog = NestedBlog::forceCreate(['title' => 'Blog']);
        $initialCount = NestedPost::count();

        $response = $this->postJson('/api/nested', [
            'operations' => [
                ['model' => 'posts', 'action' => 'create', 'data' => ['blog_id' => $blog->id, 'title' => 'Post', 'content' => 'Body']],
            ],
        ]);

        $response->assertStatus(403);
        $this->assertEquals($initialCount, NestedPost::count());
    }

    public function test_nested_policy_deny_update_returns_403(): void
    {
        $this->registerRoutes(['blogs' => NestedBlog::class, 'posts' => NestedPost::class]);
        Gate::policy(NestedPost::class, NestedPostDenyUpdatePolicy::class);
        $this->authenticate();

        $blog = NestedBlog::forceCreate(['title' => 'Blog']);
        $post = NestedPost::forceCreate(['blog_id' => $blog->id, 'title' => 'Original', 'content' => 'C']);

        $response = $this->postJson('/api/nested', [
            'operations' => [
                ['model' => 'posts', 'action' => 'update', 'id' => $post->id, 'data' => ['title' => 'Updated', 'content' => 'C']],
            ],
        ]);

        $response->assertStatus(403);
        $post->refresh();
        $this->assertEquals('Original', $post->title);
    }

    // ------------------------------------------------------------------
    // Update 404
    // ------------------------------------------------------------------

    public function test_nested_update_unknown_model_returns_422(): void
    {
        $this->registerRoutes(['blogs' => NestedBlog::class]);
        $this->authenticate();

        $response = $this->postJson('/api/nested', [
            'operations' => [
                ['model' => 'nonexistent', 'action' => 'update', 'id' => 1, 'data' => ['title' => 'X']],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Unknown model.');
        $this->assertStringContainsString('nonexistent', json_encode($response->json('errors')));
    }

    public function test_nested_update_nonexistent_id_returns_404(): void
    {
        $this->registerRoutes(['blogs' => NestedBlog::class]);
        $this->authenticate();

        $response = $this->postJson('/api/nested', [
            'operations' => [
                ['model' => 'blogs', 'action' => 'update', 'id' => 99999, 'data' => ['title' => 'X']],
            ],
        ]);

        $response->assertStatus(404);
        $response->assertJsonPath('message', 'Resource not found.');
    }

    // ------------------------------------------------------------------
    // Success with full content
    // ------------------------------------------------------------------

    public function test_nested_success_returns_200_with_full_content(): void
    {
        $this->registerRoutes(['blogs' => NestedBlog::class, 'posts' => NestedPost::class]);
        $this->authenticate();

        $blog = NestedBlog::forceCreate(['title' => 'Original Blog']);

        $response = $this->postJson('/api/nested', [
            'operations' => [
                ['model' => 'blogs', 'action' => 'update', 'id' => $blog->id, 'data' => ['title' => 'Updated Blog']],
                ['model' => 'posts', 'action' => 'create', 'data' => ['blog_id' => $blog->id, 'title' => 'New Post', 'content' => 'Body']],
            ],
        ]);

        $response->assertStatus(200);
        $results = $response->json('results');
        $this->assertCount(2, $results);

        $this->assertEquals('blogs', $results[0]['model']);
        $this->assertEquals('update', $results[0]['action']);
        $this->assertEquals($blog->id, $results[0]['id']);
        $this->assertEquals('Updated Blog', $results[0]['data']['title']);
        $this->assertArrayHasKey('id', $results[0]['data']);

        $this->assertEquals('posts', $results[1]['model']);
        $this->assertEquals('create', $results[1]['action']);
        $this->assertIsInt($results[1]['id']);
        $this->assertEquals('New Post', $results[1]['data']['title']);
        $this->assertEquals('Body', $results[1]['data']['content']);
        $this->assertEquals($blog->id, $results[1]['data']['blog_id']);
        $this->assertArrayHasKey('id', $results[1]['data']);

        $blog->refresh();
        $this->assertEquals('Updated Blog', $blog->title);
        $this->assertEquals(1, NestedPost::count());
        $post = NestedPost::first();
        $this->assertEquals('New Post', $post->title);
    }

    // ------------------------------------------------------------------
    // Transaction rollback
    // ------------------------------------------------------------------

    public function test_nested_transaction_rollback_on_second_operation_failure(): void
    {
        $this->registerRoutes(['blogs' => NestedBlog::class, 'posts' => NestedPost::class]);
        $this->authenticate();

        $blog = NestedBlog::forceCreate(['title' => 'Blog']);
        $initialPostCount = NestedPost::count();

        // Two creates with same title; unique on title causes second to fail, so first must roll back
        $response = $this->postJson('/api/nested', [
            'operations' => [
                ['model' => 'posts', 'action' => 'create', 'data' => ['blog_id' => $blog->id, 'title' => 'RollbackTestTitle', 'content' => 'C1']],
                ['model' => 'posts', 'action' => 'create', 'data' => ['blog_id' => $blog->id, 'title' => 'RollbackTestTitle', 'content' => 'C2']],
            ],
        ]);

        $this->assertTrue(in_array($response->status(), [422, 500], true));
        $this->assertEquals($initialPostCount, NestedPost::count());
    }

    // ------------------------------------------------------------------
    // Max operations
    // ------------------------------------------------------------------

    public function test_nested_max_operations_returns_422(): void
    {
        $this->registerRoutes(['blogs' => NestedBlog::class], ['max_operations' => 2]);
        $this->authenticate();

        $blog = NestedBlog::forceCreate(['title' => 'B']);

        $response = $this->postJson('/api/nested', [
            'operations' => [
                ['model' => 'blogs', 'action' => 'update', 'id' => $blog->id, 'data' => ['title' => 'B1']],
                ['model' => 'blogs', 'action' => 'update', 'id' => $blog->id, 'data' => ['title' => 'B2']],
                ['model' => 'blogs', 'action' => 'update', 'id' => $blog->id, 'data' => ['title' => 'B3']],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Too many operations.');
        $blog->refresh();
        $this->assertEquals('B', $blog->title);
    }

    // ------------------------------------------------------------------
    // Allowed models
    // ------------------------------------------------------------------

    public function test_nested_allowed_models_rejects_other_models(): void
    {
        $this->registerRoutes(['blogs' => NestedBlog::class, 'posts' => NestedPost::class], ['allowed_models' => ['blogs']]);
        $this->authenticate();

        $blog = NestedBlog::forceCreate(['title' => 'B']);

        $response = $this->postJson('/api/nested', [
            'operations' => [
                ['model' => 'posts', 'action' => 'create', 'data' => ['blog_id' => $blog->id, 'title' => 'P', 'content' => 'C']],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath('message', 'Operation not allowed.');
        $this->assertEquals(0, NestedPost::count());
    }
}
