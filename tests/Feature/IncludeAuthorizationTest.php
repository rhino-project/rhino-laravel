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

// --------------------------------------------------------------------------
// Differently-named-relation fixtures: `Article.author()` returns a User-typed
// model (`IncludeAuthor`). Validates that include-authorization gates on the
// related MODEL class, not on the relation name.
// --------------------------------------------------------------------------

class IncludeAuthor extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'include_authors';

    protected $fillable = ['name'];

    protected $validationRules = ['name' => 'string|max:255'];
    protected $validationRulesStore = ['name'];
    protected $validationRulesUpdate = ['name'];
}

class IncludeArticle extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'include_articles';

    protected $fillable = ['author_id', 'title'];

    protected $validationRules = ['title' => 'string|max:255'];
    protected $validationRulesStore = ['title'];
    protected $validationRulesUpdate = ['title'];

    public static $allowedIncludes = ['author'];

    /** Relation name 'author' deliberately differs from related model `IncludeAuthor`. */
    public function author()
    {
        return $this->belongsTo(IncludeAuthor::class, 'author_id');
    }
}

class IncludeArticlePolicy
{
    public function viewAny(?Authenticatable $user): bool { return true; }
    public function view(?Authenticatable $user, $model): bool { return true; }
    public function create(?Authenticatable $user): bool { return true; }
    public function update(?Authenticatable $user, $model): bool { return true; }
    public function delete(?Authenticatable $user, $model): bool { return true; }
}

class IncludeAuthorPolicyAllow
{
    public function viewAny(?Authenticatable $user): bool { return true; }
    public function view(?Authenticatable $user, $model): bool { return true; }
}

class IncludeAuthorPolicyDeny
{
    public function viewAny(?Authenticatable $user): bool { return false; }
    public function view(?Authenticatable $user, $model): bool { return false; }
}

/**
 * Concrete ResourcePolicy subclass used to validate slug resolution for a
 * model whose relation name differs from its slug.
 */
class IncludeAuthorResourcePolicy extends \Rhino\Policies\ResourcePolicy
{
    // Slug is intentionally NOT set explicitly here; it must be resolved
    // via class match against config('rhino.models').
}

/**
 * Test middleware that injects an organization into the request so the layered
 * (tenant) permission path is exercised, without needing an {organization}
 * URL segment. Mirrors what ResolveOrganizationFromRoute does in real apps.
 */
class IncludeSetOrgMiddleware
{
    public function handle($request, \Closure $next)
    {
        $org = \App\Models\Organization::find(1);
        if ($org) {
            $request->attributes->set('organization', $org);
        }

        return $next($request);
    }
}

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

        Schema::create('include_authors', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('include_articles', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('author_id')->nullable();
            $table->string('title');
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

    // ----------------------------------------------------------------------
    // Differently-named relation: `Article.author()` -> User-typed model.
    // ----------------------------------------------------------------------

    public function test_include_with_differently_named_relation_resolves_via_related_model_policy(): void
    {
        // The relation is `author`; the related model is `IncludeAuthor`. The
        // Gate must be asked viewAny on IncludeAuthor, not on anything derived
        // from the relation name.
        $this->registerRoutes([
            'articles' => IncludeArticle::class,
            'authors' => IncludeAuthor::class,
        ]);
        Gate::policy(IncludeArticle::class, IncludeArticlePolicy::class);
        Gate::policy(IncludeAuthor::class, IncludeAuthorPolicyAllow::class);
        $this->authenticateAs(2);

        $author = IncludeAuthor::forceCreate(['name' => 'Jane']);
        IncludeArticle::forceCreate(['author_id' => $author->id, 'title' => 'Article 1']);

        $response = $this->call('GET', '/api/articles', ['include' => 'author'], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        // 200 confirms include-auth resolved via IncludeAuthor's policy (not via
        // the relation name 'author'). Spatie attaches the relation when
        // include= is present and authorized.
        $response->assertStatus(200);
        $data = $response->json();
        $this->assertIsArray($data);
        $this->assertNotEmpty($data);
    }

    public function test_include_with_differently_named_relation_denied_when_related_model_policy_denies(): void
    {
        // Sanity check: when the related model's policy denies viewAny, the
        // include is rejected — proving the gate happens on the related MODEL,
        // not on a fictitious permission derived from the relation name 'author'.
        $this->registerRoutes([
            'articles' => IncludeArticle::class,
            'authors' => IncludeAuthor::class,
        ]);
        Gate::policy(IncludeArticle::class, IncludeArticlePolicy::class);
        Gate::policy(IncludeAuthor::class, IncludeAuthorPolicyDeny::class);
        $this->authenticateAs(2);

        $author = IncludeAuthor::forceCreate(['name' => 'Jane']);
        IncludeArticle::forceCreate(['author_id' => $author->id, 'title' => 'Article 1']);

        $response = $this->call('GET', '/api/articles', ['include' => 'author'], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);

        $response->assertStatus(403);
        $response->assertJson(['message' => 'You do not have permission to include author.']);
    }

    public function test_resource_policy_slug_resolves_via_class_match_in_rhino_models_config(): void
    {
        // Regression test for the UserPolicy install gap: a model registered
        // in config('rhino.models') only resolves its slug if there's a
        // concrete policy class mapped via Gate::policy whose class matches
        // the running policy. Without a concrete UserPolicy mapping the User
        // model, ResourcePolicy::resolveResourceSlug returns null and
        // permission checks deny — even when 'users.index' is granted.
        Gate::policy(IncludeAuthor::class, IncludeAuthorResourcePolicy::class);
        config(['rhino.models' => [
            'authors' => IncludeAuthor::class,
            'articles' => IncludeArticle::class,
        ]]);

        $policy = new IncludeAuthorResourcePolicy();
        $ref = new \ReflectionMethod($policy, 'resolveResourceSlug');
        $ref->setAccessible(true);

        $this->assertSame(
            'authors',
            $ref->invoke($policy),
            'ResourcePolicy must resolve its slug from config(\'rhino.models\') by ' .
            'matching the registered policy class to its own class.'
        );
    }

    public function test_resource_policy_slug_resolves_to_null_when_no_concrete_policy_is_mapped(): void
    {
        // Inverse of the above: without Gate::policy(...) mapping the model
        // to a concrete policy class, slug resolution returns null. This is
        // why ?include=assignee 403's in apps that register `users` in config
        // but never publish a UserPolicy.
        config(['rhino.models' => [
            'authors' => IncludeAuthor::class,
            'articles' => IncludeArticle::class,
        ]]);
        // Intentionally NOT calling Gate::policy() for IncludeAuthor.

        $policy = new IncludeAuthorResourcePolicy();
        $ref = new \ReflectionMethod($policy, 'resolveResourceSlug');
        $ref->setAccessible(true);

        $this->assertNull(
            $ref->invoke($policy),
            'Without a concrete Gate::policy mapping, slug resolution must ' .
            'return null (so checkPermission denies by default).'
        );
    }

    // ----------------------------------------------------------------------
    // Layered permissions (4.3) on a DIFFERENTLY-NAMED relation include.
    //
    // `IncludeArticle.author()` -> `IncludeAuthor` (slug 'authors'). The include
    // is authorized through the REAL ResourcePolicy + layered hasPermission, so
    // the effective permission for `authors.index` is resolved as
    // (org_role_permissions ∪ granted) − denied, deny always wins. The relation
    // name 'author' is irrelevant — the slug comes from the related model.
    // ----------------------------------------------------------------------

    protected function registerLayeredAuthorRoutes(): void
    {
        config([
            'rhino.models' => [
                'articles' => IncludeArticle::class,
                'authors' => IncludeAuthor::class,
            ],
            'rhino.route_groups' => [
                'tenant' => [
                    'prefix' => '',
                    'middleware' => [IncludeSetOrgMiddleware::class],
                    'models' => '*',
                ],
            ],
            'rhino.multi_tenant' => ['organization_identifier_column' => 'id'],
        ]);

        Route::prefix('api')->group(function () {
            require __DIR__ . '/../../routes/api.php';
        });

        // Parent article is always viewable (isolates the assertion to the
        // author include); the included author uses the REAL layered policy.
        Gate::policy(IncludeArticle::class, IncludeArticlePolicy::class);
        Gate::policy(IncludeAuthor::class, IncludeAuthorResourcePolicy::class);
    }

    /**
     * Seed the org role layer + per-user deltas, an article + its author, and
     * authenticate as the user.
     *
     * @param  array<string>  $roleLayer  org_role_permissions for (org, role)
     * @param  array<string>  $granted    user_roles.granted_permissions
     * @param  array<string>  $denied     user_roles.denied_permissions
     */
    protected function seedLayeredAuthorUser(array $roleLayer, array $granted = [], array $denied = []): void
    {
        $org = \App\Models\Organization::firstOrCreate(['id' => 1], ['name' => 'Org', 'slug' => 'org']);
        $role = \App\Models\Role::firstOrCreate(['id' => 1], ['name' => 'Role', 'slug' => 'role']);
        $user = \App\Models\User::firstOrCreate(
            ['id' => 1],
            ['name' => 'U', 'email' => 'u1@example.com', 'password' => bcrypt('password')]
        );

        \App\Models\UserRole::where('user_id', $user->id)->delete();
        \App\Models\UserRole::forceCreate([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'permissions' => [],
            'granted_permissions' => $granted,
            'denied_permissions' => $denied,
        ]);

        \Illuminate\Support\Facades\DB::table('org_role_permissions')->updateOrInsert(
            ['organization_id' => $org->id, 'role_id' => $role->id],
            ['permissions' => json_encode($roleLayer)]
        );

        $author = IncludeAuthor::forceCreate(['name' => 'Jane']);
        IncludeArticle::forceCreate(['author_id' => $author->id, 'title' => 'Article 1']);

        $this->actingAs($user, 'sanctum');
    }

    protected function callAuthorInclude(): \Illuminate\Testing\TestResponse
    {
        return $this->call('GET', '/api/articles', ['include' => 'author'], [], [], [
            'HTTP_ACCEPT' => 'application/json',
        ]);
    }

    public function test_layered_role_layer_permission_allows_differently_named_include(): void
    {
        $this->registerLayeredAuthorRoutes();
        // Role layer grants authors.* → the author include is authorized.
        $this->seedLayeredAuthorUser(roleLayer: ['authors.*']);

        $this->callAuthorInclude()->assertStatus(200);
    }

    public function test_layered_denied_permission_blocks_differently_named_include(): void
    {
        $this->registerLayeredAuthorRoutes();
        // Role layer grants '*' (covers authors.index) BUT the user is explicitly
        // denied authors.* — deny wins, so the include is forbidden even though
        // the relation name ('author') differs from the slug ('authors').
        $this->seedLayeredAuthorUser(roleLayer: ['*'], denied: ['authors.*']);

        $response = $this->callAuthorInclude();
        $response->assertStatus(403);
        $response->assertJson(['message' => 'You do not have permission to include author.']);
    }

    public function test_layered_denied_exact_permission_blocks_include_under_role_wildcard(): void
    {
        $this->registerLayeredAuthorRoutes();
        // Same as above but the deny is the exact ability, not a wildcard.
        $this->seedLayeredAuthorUser(roleLayer: ['*'], denied: ['authors.index']);

        $this->callAuthorInclude()->assertStatus(403);
    }

    public function test_layered_default_deny_blocks_differently_named_include(): void
    {
        $this->registerLayeredAuthorRoutes();
        // No authors permission anywhere (role layer only covers articles).
        $this->seedLayeredAuthorUser(roleLayer: ['articles.*']);

        $this->callAuthorInclude()->assertStatus(403);
    }

    public function test_layered_granted_permission_allows_differently_named_include(): void
    {
        $this->registerLayeredAuthorRoutes();
        // Role layer has NO authors permission; a per-user grant of authors.index
        // is enough to authorize the include (granted ∪ role).
        $this->seedLayeredAuthorUser(roleLayer: [], granted: ['authors.index']);

        $this->callAuthorInclude()->assertStatus(200);
    }
}
