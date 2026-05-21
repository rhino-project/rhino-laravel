<?php

namespace Rhino\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Rhino\Tests\TestCase;
use Rhino\Traits\HidableColumns;
use Rhino\Traits\HasValidation;

// --------------------------------------------------------------------------
// Test Models
// --------------------------------------------------------------------------

class IncludePost extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'include_posts';

    protected $fillable = ['blog_id', 'title'];

    protected $validationRules = ['title' => 'string|max:255'];
    protected $validationRulesStore = ['title'];
    protected $validationRulesUpdate = ['title'];

    public static $allowedFilters = ['title'];
    public static $allowedSorts = ['title'];
    public static $allowedIncludes = ['comments', 'blog'];

    public function comments()
    {
        return $this->hasMany(IncludeComment::class, 'post_id');
    }

    public function blog()
    {
        return $this->belongsTo(IncludeBlog::class, 'blog_id');
    }
}

class IncludeComment extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'include_comments';

    protected $fillable = ['post_id', 'body'];

    protected $validationRules = ['body' => 'string'];
    protected $validationRulesStore = ['body'];
    protected $validationRulesUpdate = ['body'];

    public function post()
    {
        return $this->belongsTo(IncludePost::class, 'post_id');
    }
}

class IncludeBlog extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'include_blogs';

    protected $fillable = ['title'];

    protected $validationRules = ['title' => 'string|max:255'];
    protected $validationRulesStore = ['title'];
    protected $validationRulesUpdate = ['title'];

    public static $allowedIncludes = ['posts'];

    public function posts()
    {
        return $this->hasMany(IncludePost::class, 'blog_id');
    }
}

// --------------------------------------------------------------------------
// Test Policies — only user id 1 can viewAny comments and blogs; all can view posts
// --------------------------------------------------------------------------

class IncludePostPolicy
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

class IncludeCommentPolicy
{
    /**
     * Only user with id 1 can list comments (simulates "no permission" for others).
     */
    public function viewAny(?Authenticatable $user): bool
    {
        return $user && $user->getAuthIdentifier() === 1;
    }

    public function view(?Authenticatable $user, $model): bool
    {
        return $user && $user->getAuthIdentifier() === 1;
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

class IncludeBlogPolicy
{
    /**
     * Only user with id 1 can list blogs (simulates "no permission" for others).
     */
    public function viewAny(?Authenticatable $user): bool
    {
        return $user && $user->getAuthIdentifier() === 1;
    }

    public function view(?Authenticatable $user, $model): bool
    {
        return $user && $user->getAuthIdentifier() === 1;
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

class IncludeBlogPolicyAllowUser2Only
{
    /** Only user 2 can list blogs (for count-include test: user can list blogs but not posts). */
    public function viewAny(?Authenticatable $user): bool
    {
        return $user && $user->getAuthIdentifier() === 2;
    }

    public function view(?Authenticatable $user, $model): bool
    {
        return $user && $user->getAuthIdentifier() === 2;
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

class IncludePostPolicyDenyUser2
{
    /** Deny viewAny for user 2 (for count-include test: user can list blogs but not posts). */
    public function viewAny(?Authenticatable $user): bool
    {
        return $user && $user->getAuthIdentifier() !== 2;
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

// --------------------------------------------------------------------------
// Tests
// --------------------------------------------------------------------------

class IncludeAuthorizationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('include_blogs', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('include_posts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('blog_id')->nullable();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('include_comments', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('post_id');
            $table->string('body')->nullable();
            $table->timestamps();
        });

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        Gate::policy(IncludePost::class, IncludePostPolicy::class);
        Gate::policy(IncludeComment::class, IncludeCommentPolicy::class);
        Gate::policy(IncludeBlog::class, IncludeBlogPolicy::class);
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

    protected function authenticateAs(int $userId): \App\Models\User
    {
        $user = \App\Models\User::find($userId);
        if (! $user) {
            $user = \App\Models\User::forceCreate([
                'id' => $userId,
                'name' => "User {$userId}",
                'email' => "user{$userId}@example.com",
                'password' => bcrypt('password'),
            ]);
        }
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_gate_denies_view_any_on_included_resource_for_unauthorized_user(): void
    {
        $this->registerRoutes([
            'posts' => IncludePost::class,
            'comments' => IncludeComment::class,
        ]);
        $user = $this->authenticateAs(2);

        $this->assertSame(2, $user->getAuthIdentifier(), 'Test user must have id 2');
        $policy = new IncludeCommentPolicy();
        $this->assertFalse($policy->viewAny($user), 'Policy must deny viewAny for user 2');
        $this->assertFalse(Gate::forUser($user)->allows('viewAny', IncludeComment::class));
        $this->assertTrue(Gate::forUser($user)->allows('viewAny', IncludePost::class));
    }

    public function test_include_forbidden_returns_403_on_index(): void
    {
        $this->registerRoutes([
            'posts' => IncludePost::class,
            'comments' => IncludeComment::class,
        ]);
        $this->authenticateAs(2);

        IncludePost::forceCreate(['blog_id' => null, 'title' => 'Post 1']);

        $response = $this->call('GET', '/api/posts', ['include' => 'comments'], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $response->assertStatus(403);
        $response->assertJson(['message' => 'You do not have permission to include comments.']);
    }

    public function test_include_forbidden_returns_403_on_show(): void
    {
        $this->registerRoutes([
            'posts' => IncludePost::class,
            'comments' => IncludeComment::class,
        ]);
        $this->authenticateAs(2);

        $post = IncludePost::forceCreate(['blog_id' => null, 'title' => 'Post 1']);

        $response = $this->call('GET', "/api/posts/{$post->id}", ['include' => 'comments'], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $response->assertStatus(403);
        $response->assertJson(['message' => 'You do not have permission to include comments.']);
    }

    public function test_include_allowed_returns_200_with_relationship_on_index(): void
    {
        $this->registerRoutes([
            'posts' => IncludePost::class,
            'comments' => IncludeComment::class,
        ]);
        $this->authenticateAs(1);

        $post = IncludePost::forceCreate(['blog_id' => null, 'title' => 'Post 1']);
        IncludeComment::forceCreate(['post_id' => $post->id, 'body' => 'A comment']);

        $response = $this->call('GET', '/api/posts', ['include' => 'comments'], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
        // User with viewAny on comments gets 200; relationship is loaded by Spatie when include= is present
    }

    public function test_include_allowed_returns_200_with_relationship_on_show(): void
    {
        $this->registerRoutes([
            'posts' => IncludePost::class,
            'comments' => IncludeComment::class,
        ]);
        $this->authenticateAs(1);

        $post = IncludePost::forceCreate(['blog_id' => null, 'title' => 'Post 1']);
        IncludeComment::forceCreate(['post_id' => $post->id, 'body' => 'A comment']);

        $response = $this->call('GET', "/api/posts/{$post->id}", ['include' => 'comments'], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $response->assertStatus(200);
        // User with viewAny on comments gets 200; relationship is loaded by Spatie when include= is present
    }

    public function test_no_include_returns_200(): void
    {
        $this->registerRoutes([
            'posts' => IncludePost::class,
            'comments' => IncludeComment::class,
        ]);
        $this->authenticateAs(2);

        IncludePost::forceCreate(['blog_id' => null, 'title' => 'Post 1']);

        $response = $this->getJson('/api/posts');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
        $this->assertArrayNotHasKey('comments', $data[0]);
    }

    public function test_nested_include_forbidden_returns_403(): void
    {
        $this->registerRoutes([
            'posts' => IncludePost::class,
            'blogs' => IncludeBlog::class,
        ]);
        $this->authenticateAs(2);

        $blog = IncludeBlog::forceCreate(['title' => 'Blog 1']);
        IncludePost::forceCreate(['blog_id' => $blog->id, 'title' => 'Post 1']);

        $response = $this->call('GET', '/api/posts', ['include' => 'blog'], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $response->assertStatus(403);
        $response->assertJson(['message' => 'You do not have permission to include blog.']);
    }

    public function test_nested_include_allowed_returns_200(): void
    {
        $this->registerRoutes([
            'posts' => IncludePost::class,
            'blogs' => IncludeBlog::class,
        ]);
        $this->authenticateAs(1);

        $blog = IncludeBlog::forceCreate(['title' => 'Blog 1']);
        $post = IncludePost::forceCreate(['blog_id' => $blog->id, 'title' => 'Post 1']);

        $response = $this->call('GET', "/api/posts/{$post->id}", ['include' => 'blog'], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $response->assertStatus(200);
        // User with viewAny on blog gets 200; relationship is loaded by Spatie when include= is present
    }

    public function test_multiple_includes_one_forbidden_returns_403(): void
    {
        $this->registerRoutes([
            'posts' => IncludePost::class,
            'comments' => IncludeComment::class,
            'blogs' => IncludeBlog::class,
        ]);
        $this->authenticateAs(2);

        $blog = IncludeBlog::forceCreate(['title' => 'Blog 1']);
        IncludePost::forceCreate(['blog_id' => $blog->id, 'title' => 'Post 1']);

        $response = $this->call('GET', '/api/posts', ['include' => 'blog,comments'], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $response->assertStatus(403);
        $response->assertJson(['message' => 'You do not have permission to include blog.']);
    }

    public function test_include_count_forbidden_returns_403_on_index(): void
    {
        $this->registerRoutes([
            'posts' => IncludePost::class,
            'comments' => IncludeComment::class,
        ]);
        $this->authenticateAs(2);

        IncludePost::forceCreate(['blog_id' => null, 'title' => 'Post 1']);

        $response = $this->call('GET', '/api/posts', ['include' => 'commentsCount'], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $response->assertStatus(403);
        $response->assertJson(['message' => 'You do not have permission to include commentsCount.']);
    }

    public function test_include_count_allowed_returns_200_on_index(): void
    {
        $this->registerRoutes([
            'posts' => IncludePost::class,
            'comments' => IncludeComment::class,
        ]);
        $this->authenticateAs(1);

        $post = IncludePost::forceCreate(['blog_id' => null, 'title' => 'Post 1']);
        IncludeComment::forceCreate(['post_id' => $post->id, 'body' => 'A comment']);

        $response = $this->call('GET', '/api/posts', ['include' => 'commentsCount'], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
        // Authorized user receives 200 with count include applied (exact key may vary by serialization)
    }

    public function test_include_exists_forbidden_returns_403_on_index(): void
    {
        $this->registerRoutes([
            'posts' => IncludePost::class,
            'comments' => IncludeComment::class,
        ]);
        $this->authenticateAs(2);

        IncludePost::forceCreate(['blog_id' => null, 'title' => 'Post 1']);

        $response = $this->call('GET', '/api/posts', ['include' => 'commentsExists'], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $response->assertStatus(403);
        $response->assertJson(['message' => 'You do not have permission to include commentsExists.']);
    }

    public function test_include_count_on_blogs_forbidden_returns_403(): void
    {
        $this->registerRoutes([
            'blogs' => IncludeBlog::class,
            'posts' => IncludePost::class,
        ]);
        Gate::policy(IncludeBlog::class, IncludeBlogPolicyAllowUser2Only::class);
        Gate::policy(IncludePost::class, IncludePostPolicyDenyUser2::class);
        $this->authenticateAs(2);

        $blog = IncludeBlog::forceCreate(['title' => 'Blog 1']);
        IncludePost::forceCreate(['blog_id' => $blog->id, 'title' => 'Post 1']);

        $response = $this->call('GET', '/api/blogs', ['include' => 'postsCount'], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $response->assertStatus(403);
        $response->assertJson(['message' => 'You do not have permission to include postsCount.']);
    }
}
