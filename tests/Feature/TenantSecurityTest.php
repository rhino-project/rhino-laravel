<?php

namespace Rhino\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Rhino\Contracts\HasPermittedAttributes;
use Rhino\Policies\ResourcePolicy;
use Rhino\Tests\TestCase;
use Rhino\Traits\BelongsToOrganization;
use Rhino\Traits\HasValidation;
use Rhino\Traits\HidableColumns;

// --------------------------------------------------------------------------
// Test Models
// --------------------------------------------------------------------------

class TenantBlog extends Model
{
    use HasValidation, HidableColumns, BelongsToOrganization;

    protected $table = 'tenant_blogs';
    protected $fillable = ['organization_id', 'name', 'is_published'];

    protected $validationRules = [
        'organization_id' => 'required|integer|exists:organizations,id',
        'name' => 'required|string|max:255',
        'is_published' => 'boolean',
    ];
}

class TenantPost extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'tenant_posts';
    protected $fillable = ['blog_id', 'title', 'content'];

    protected $validationRules = [
        'blog_id' => 'required|integer|exists:tenant_blogs,id',
        'title' => 'required|string|max:255',
        'content' => 'string',
    ];
}

class TenantComment extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'tenant_comments';
    protected $fillable = ['post_id', 'body'];

    protected $validationRules = [
        'post_id' => 'required|integer|exists:tenant_posts,id',
        'body' => 'required|string',
    ];
}

class TenantReply extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'tenant_replies';
    protected $fillable = ['comment_id', 'body'];

    protected $validationRules = [
        'comment_id' => 'required|integer|exists:tenant_comments,id',
        'body' => 'required|string',
    ];
}

class TenantLegacyPost extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'tenant_posts';
    protected $fillable = ['blog_id', 'title', 'content'];

    protected $validationRules = [
        'blog_id' => 'required|integer|exists:tenant_blogs,id',
        'title' => 'required|string|max:255',
        'content' => 'string',
    ];

    protected $validationRulesStore = ['blog_id', 'title', 'content'];
    protected $validationRulesUpdate = ['blog_id', 'title', 'content'];
}

// --------------------------------------------------------------------------
// Test Policies
// --------------------------------------------------------------------------

class TenantBlogPolicy extends ResourcePolicy implements HasPermittedAttributes
{
    public function viewAny(?Authenticatable $user): bool { return true; }
    public function view(?Authenticatable $user, $model): bool { return true; }
    public function create(?Authenticatable $user): bool { return true; }
    public function update(?Authenticatable $user, $model): bool { return true; }
    public function delete(?Authenticatable $user, $model): bool { return true; }

    public function permittedAttributesForCreate(?Authenticatable $user): array
    {
        return ['name'];
    }

    public function permittedAttributesForUpdate(?Authenticatable $user): array
    {
        return ['name', 'is_published'];
    }
}

class TenantPostPolicy extends ResourcePolicy implements HasPermittedAttributes
{
    public function viewAny(?Authenticatable $user): bool { return true; }
    public function view(?Authenticatable $user, $model): bool { return true; }
    public function create(?Authenticatable $user): bool { return true; }
    public function update(?Authenticatable $user, $model): bool { return true; }
    public function delete(?Authenticatable $user, $model): bool { return true; }

    public function permittedAttributesForCreate(?Authenticatable $user): array
    {
        return ['blog_id', 'title', 'content'];
    }

    public function permittedAttributesForUpdate(?Authenticatable $user): array
    {
        return ['blog_id', 'title', 'content'];
    }
}

class TenantCommentPolicy extends ResourcePolicy implements HasPermittedAttributes
{
    public function viewAny(?Authenticatable $user): bool { return true; }
    public function view(?Authenticatable $user, $model): bool { return true; }
    public function create(?Authenticatable $user): bool { return true; }
    public function update(?Authenticatable $user, $model): bool { return true; }
    public function delete(?Authenticatable $user, $model): bool { return true; }

    public function permittedAttributesForCreate(?Authenticatable $user): array
    {
        return ['post_id', 'body'];
    }

    public function permittedAttributesForUpdate(?Authenticatable $user): array
    {
        return ['post_id', 'body'];
    }
}

class TenantReplyPolicy extends ResourcePolicy implements HasPermittedAttributes
{
    public function viewAny(?Authenticatable $user): bool { return true; }
    public function view(?Authenticatable $user, $model): bool { return true; }
    public function create(?Authenticatable $user): bool { return true; }
    public function update(?Authenticatable $user, $model): bool { return true; }
    public function delete(?Authenticatable $user, $model): bool { return true; }

    public function permittedAttributesForCreate(?Authenticatable $user): array
    {
        return ['comment_id', 'body'];
    }

    public function permittedAttributesForUpdate(?Authenticatable $user): array
    {
        return ['comment_id', 'body'];
    }
}

class TenantLegacyPostPolicy
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

class TenantSecurityTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        Schema::create('tenant_blogs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->boolean('is_published')->default(false);
            $table->timestamps();
        });

        Schema::create('tenant_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained('tenant_blogs')->cascadeOnDelete();
            $table->string('title');
            $table->text('content')->nullable();
            $table->timestamps();
        });

        Schema::create('tenant_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id')->constrained('tenant_posts')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
        });

        Schema::create('tenant_replies', function (Blueprint $table) {
            $table->id();
            $table->foreignId('comment_id')->constrained('tenant_comments')->cascadeOnDelete();
            $table->text('body');
            $table->timestamps();
        });
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

    // ------------------------------------------------------------------
    // organization_id cannot be changed on update (403)
    // ------------------------------------------------------------------

    public function test_rejects_updating_organization_id_on_blog_in_tenant_context(): void
    {
        Gate::policy(TenantBlog::class, TenantBlogPolicy::class);
        $this->registerTenantRoutes(['blogs' => TenantBlog::class]);

        [$user, $orgA] = $this->createUserInOrg('org-a');
        $orgB = \App\Models\Organization::forceCreate(['name' => 'Org B', 'slug' => 'org-b', 'domain' => null]);

        $blog = TenantBlog::forceCreate(['organization_id' => $orgA->id, 'name' => 'Original', 'is_published' => false]);

        $response = $this->putJson("/api/org-a/blogs/{$blog->id}", [
            'name' => 'Updated',
            'organization_id' => $orgB->id,
        ]);

        $response->assertStatus(403);
        $response->assertJson(['message' => 'The organization_id field cannot be changed.']);
    }

    public function test_allows_updating_blog_without_organization_id_in_tenant_context(): void
    {
        Gate::policy(TenantBlog::class, TenantBlogPolicy::class);
        $this->registerTenantRoutes(['blogs' => TenantBlog::class]);

        [$user, $org] = $this->createUserInOrg('org-a');
        $blog = TenantBlog::forceCreate(['organization_id' => $org->id, 'name' => 'Original', 'is_published' => false]);

        $response = $this->putJson("/api/org-a/blogs/{$blog->id}", [
            'name' => 'Updated Name',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('name', 'Updated Name');
        $this->assertEquals($org->id, $blog->fresh()->organization_id);
    }

    // ------------------------------------------------------------------
    // organization_id is stripped on store (set from route context)
    // ------------------------------------------------------------------

    public function test_ignores_user_supplied_organization_id_on_store(): void
    {
        Gate::policy(TenantBlog::class, TenantBlogPolicy::class);
        $this->registerTenantRoutes(['blogs' => TenantBlog::class]);

        [$user, $orgA] = $this->createUserInOrg('org-a');
        $orgB = \App\Models\Organization::forceCreate(['name' => 'Org B', 'slug' => 'org-b', 'domain' => null]);

        $response = $this->postJson("/api/org-a/blogs", [
            'name' => 'New Blog',
            'organization_id' => $orgB->id, // should be ignored
        ]);

        $response->assertStatus(201);
        $blog = TenantBlog::where('name', 'New Blog')->first();
        $this->assertNotNull($blog);
        $this->assertEquals($orgA->id, $blog->organization_id);
    }

    public function test_auto_assigns_organization_id_from_route_context(): void
    {
        Gate::policy(TenantBlog::class, TenantBlogPolicy::class);
        $this->registerTenantRoutes(['blogs' => TenantBlog::class]);

        [$user, $org] = $this->createUserInOrg('org-a');

        $response = $this->postJson("/api/org-a/blogs", [
            'name' => 'Auto Org Blog',
        ]);

        $response->assertStatus(201);
        $blog = TenantBlog::where('name', 'Auto Org Blog')->first();
        $this->assertNotNull($blog);
        $this->assertEquals($org->id, $blog->organization_id);
    }

    // ------------------------------------------------------------------
    // Cross-tenant FK validation: create
    // ------------------------------------------------------------------

    public function test_rejects_creating_post_with_blog_id_from_another_org(): void
    {
        Gate::policy(TenantBlog::class, TenantBlogPolicy::class);
        Gate::policy(TenantPost::class, TenantPostPolicy::class);
        $this->registerTenantRoutes(['blogs' => TenantBlog::class, 'posts' => TenantPost::class]);

        [$user, $orgA] = $this->createUserInOrg('org-a');
        $orgB = \App\Models\Organization::forceCreate(['name' => 'Org B', 'slug' => 'org-b', 'domain' => null]);

        $blogInOrgB = TenantBlog::forceCreate(['organization_id' => $orgB->id, 'name' => 'Other Org Blog', 'is_published' => false]);

        $response = $this->postJson("/api/org-a/posts", [
            'blog_id' => $blogInOrgB->id,
            'title' => 'Cross-tenant post',
            'content' => 'Should fail',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['blog_id']);
    }

    public function test_allows_creating_post_with_blog_id_from_same_org(): void
    {
        Gate::policy(TenantBlog::class, TenantBlogPolicy::class);
        Gate::policy(TenantPost::class, TenantPostPolicy::class);
        $this->registerTenantRoutes(['blogs' => TenantBlog::class, 'posts' => TenantPost::class]);

        [$user, $org] = $this->createUserInOrg('org-a');
        $blog = TenantBlog::forceCreate(['organization_id' => $org->id, 'name' => 'Same Org Blog', 'is_published' => false]);

        $response = $this->postJson("/api/org-a/posts", [
            'blog_id' => $blog->id,
            'title' => 'Same-tenant post',
            'content' => 'Should pass',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('title', 'Same-tenant post');
    }

    // ------------------------------------------------------------------
    // Cross-tenant FK validation: update
    // ------------------------------------------------------------------

    public function test_rejects_updating_post_blog_id_to_another_org(): void
    {
        Gate::policy(TenantBlog::class, TenantBlogPolicy::class);
        Gate::policy(TenantPost::class, TenantPostPolicy::class);
        $this->registerTenantRoutes(['blogs' => TenantBlog::class, 'posts' => TenantPost::class]);

        [$user, $orgA] = $this->createUserInOrg('org-a');
        $orgB = \App\Models\Organization::forceCreate(['name' => 'Org B', 'slug' => 'org-b', 'domain' => null]);

        $blogInOrgA = TenantBlog::forceCreate(['organization_id' => $orgA->id, 'name' => 'Blog A', 'is_published' => false]);
        $blogInOrgB = TenantBlog::forceCreate(['organization_id' => $orgB->id, 'name' => 'Blog B', 'is_published' => false]);
        $post = TenantPost::forceCreate(['blog_id' => $blogInOrgA->id, 'title' => 'Original', 'content' => 'Body']);

        $response = $this->putJson("/api/org-a/posts/{$post->id}", [
            'blog_id' => $blogInOrgB->id,
            'title' => 'Moved post',
            'content' => 'Should fail',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['blog_id']);
    }

    public function test_allows_updating_post_blog_id_to_same_org(): void
    {
        Gate::policy(TenantBlog::class, TenantBlogPolicy::class);
        Gate::policy(TenantPost::class, TenantPostPolicy::class);
        $this->registerTenantRoutes(['blogs' => TenantBlog::class, 'posts' => TenantPost::class]);

        [$user, $org] = $this->createUserInOrg('org-a');
        $blogA = TenantBlog::forceCreate(['organization_id' => $org->id, 'name' => 'Blog A', 'is_published' => false]);
        $blogB = TenantBlog::forceCreate(['organization_id' => $org->id, 'name' => 'Blog B', 'is_published' => false]);
        $post = TenantPost::forceCreate(['blog_id' => $blogA->id, 'title' => 'Original', 'content' => 'Body']);

        $response = $this->putJson("/api/org-a/posts/{$post->id}", [
            'blog_id' => $blogB->id,
            'title' => 'Moved within org',
            'content' => 'Should pass',
        ]);

        $response->assertStatus(200);
        $this->assertEquals($blogB->id, $post->fresh()->blog_id);
    }

    // ------------------------------------------------------------------
    // Cross-tenant FK validation: legacy path
    // ------------------------------------------------------------------

    public function test_legacy_path_rejects_cross_tenant_fk_on_create(): void
    {
        Gate::policy(TenantBlog::class, TenantBlogPolicy::class);
        Gate::policy(TenantLegacyPost::class, TenantLegacyPostPolicy::class);
        $this->registerTenantRoutes(['blogs' => TenantBlog::class, 'posts' => TenantLegacyPost::class]);

        [$user, $orgA] = $this->createUserInOrg('org-a');
        $orgB = \App\Models\Organization::forceCreate(['name' => 'Org B', 'slug' => 'org-b', 'domain' => null]);

        $blogInOrgB = TenantBlog::forceCreate(['organization_id' => $orgB->id, 'name' => 'Other Blog', 'is_published' => false]);

        $response = $this->postJson("/api/org-a/posts", [
            'blog_id' => $blogInOrgB->id,
            'title' => 'Cross-tenant legacy',
            'content' => 'Should fail',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['blog_id']);
    }

    public function test_legacy_path_allows_same_tenant_fk_on_create(): void
    {
        Gate::policy(TenantBlog::class, TenantBlogPolicy::class);
        Gate::policy(TenantLegacyPost::class, TenantLegacyPostPolicy::class);
        $this->registerTenantRoutes(['blogs' => TenantBlog::class, 'posts' => TenantLegacyPost::class]);

        [$user, $org] = $this->createUserInOrg('org-a');
        $blog = TenantBlog::forceCreate(['organization_id' => $org->id, 'name' => 'Same Org Blog', 'is_published' => false]);

        $response = $this->postJson("/api/org-a/posts", [
            'blog_id' => $blog->id,
            'title' => 'Same-tenant legacy',
            'content' => 'Should pass',
        ]);

        $response->assertStatus(201);
    }

    // ------------------------------------------------------------------
    // Non-org-scoped tables unaffected
    // ------------------------------------------------------------------

    public function test_exists_rules_for_non_org_tables_are_not_scoped(): void
    {
        $model = new TenantBlog();

        // Simulate tenant context
        request()->attributes->set('organization', (object) ['id' => 999]);

        $reflection = new \ReflectionMethod($model, 'scopeExistsRulesToOrganization');
        $reflection->setAccessible(true);

        // roles table has no organization_id column — should stay unchanged
        // (In :memory: SQLite there's no roles table, so hasColumn returns false → not scoped)
        $rules = ['role_id' => 'required|integer|exists:roles,id'];
        $result = $reflection->invoke($model, $rules);

        $this->assertSame('required|integer|exists:roles,id', $result['role_id']);

        // Clean up
        request()->attributes->remove('organization');
    }

    // ------------------------------------------------------------------
    // Indirect FK chain: comment → post → blog → org (2-level chain)
    // ------------------------------------------------------------------

    public function test_rejects_creating_comment_with_post_id_from_another_org(): void
    {
        Gate::policy(TenantBlog::class, TenantBlogPolicy::class);
        Gate::policy(TenantPost::class, TenantPostPolicy::class);
        Gate::policy(TenantComment::class, TenantCommentPolicy::class);
        $this->registerTenantRoutes([
            'blogs' => TenantBlog::class,
            'posts' => TenantPost::class,
            'comments' => TenantComment::class,
        ]);

        [$user, $orgA] = $this->createUserInOrg('org-a');
        $orgB = \App\Models\Organization::forceCreate(['name' => 'Org B', 'slug' => 'org-b', 'domain' => null]);

        $blogInOrgB = TenantBlog::forceCreate(['organization_id' => $orgB->id, 'name' => 'Blog B', 'is_published' => false]);
        $postInOrgB = TenantPost::forceCreate(['blog_id' => $blogInOrgB->id, 'title' => 'Post B', 'content' => 'Body']);

        $response = $this->postJson("/api/org-a/comments", [
            'post_id' => $postInOrgB->id,
            'body' => 'Cross-tenant comment',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['post_id']);
    }

    public function test_allows_creating_comment_with_post_id_from_same_org(): void
    {
        Gate::policy(TenantBlog::class, TenantBlogPolicy::class);
        Gate::policy(TenantPost::class, TenantPostPolicy::class);
        Gate::policy(TenantComment::class, TenantCommentPolicy::class);
        $this->registerTenantRoutes([
            'blogs' => TenantBlog::class,
            'posts' => TenantPost::class,
            'comments' => TenantComment::class,
        ]);

        [$user, $org] = $this->createUserInOrg('org-a');
        $blog = TenantBlog::forceCreate(['organization_id' => $org->id, 'name' => 'Blog A', 'is_published' => false]);
        $post = TenantPost::forceCreate(['blog_id' => $blog->id, 'title' => 'Post A', 'content' => 'Body']);

        $response = $this->postJson("/api/org-a/comments", [
            'post_id' => $post->id,
            'body' => 'Same-tenant comment',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('body', 'Same-tenant comment');
    }

    public function test_rejects_updating_comment_post_id_to_another_org(): void
    {
        Gate::policy(TenantBlog::class, TenantBlogPolicy::class);
        Gate::policy(TenantPost::class, TenantPostPolicy::class);
        Gate::policy(TenantComment::class, TenantCommentPolicy::class);
        $this->registerTenantRoutes([
            'blogs' => TenantBlog::class,
            'posts' => TenantPost::class,
            'comments' => TenantComment::class,
        ]);

        [$user, $orgA] = $this->createUserInOrg('org-a');
        $orgB = \App\Models\Organization::forceCreate(['name' => 'Org B', 'slug' => 'org-b', 'domain' => null]);

        $blogA = TenantBlog::forceCreate(['organization_id' => $orgA->id, 'name' => 'Blog A', 'is_published' => false]);
        $postA = TenantPost::forceCreate(['blog_id' => $blogA->id, 'title' => 'Post A', 'content' => 'Body']);
        $comment = TenantComment::forceCreate(['post_id' => $postA->id, 'body' => 'Original comment']);

        $blogB = TenantBlog::forceCreate(['organization_id' => $orgB->id, 'name' => 'Blog B', 'is_published' => false]);
        $postB = TenantPost::forceCreate(['blog_id' => $blogB->id, 'title' => 'Post B', 'content' => 'Body']);

        $response = $this->putJson("/api/org-a/comments/{$comment->id}", [
            'post_id' => $postB->id,
            'body' => 'Moved to another org',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['post_id']);
    }

    public function test_allows_updating_comment_post_id_within_same_org(): void
    {
        Gate::policy(TenantBlog::class, TenantBlogPolicy::class);
        Gate::policy(TenantPost::class, TenantPostPolicy::class);
        Gate::policy(TenantComment::class, TenantCommentPolicy::class);
        $this->registerTenantRoutes([
            'blogs' => TenantBlog::class,
            'posts' => TenantPost::class,
            'comments' => TenantComment::class,
        ]);

        [$user, $org] = $this->createUserInOrg('org-a');
        $blog = TenantBlog::forceCreate(['organization_id' => $org->id, 'name' => 'Blog A', 'is_published' => false]);
        $postA = TenantPost::forceCreate(['blog_id' => $blog->id, 'title' => 'Post A', 'content' => 'Body']);
        $postB = TenantPost::forceCreate(['blog_id' => $blog->id, 'title' => 'Post B', 'content' => 'Body']);
        $comment = TenantComment::forceCreate(['post_id' => $postA->id, 'body' => 'Original comment']);

        $response = $this->putJson("/api/org-a/comments/{$comment->id}", [
            'post_id' => $postB->id,
            'body' => 'Moved within org',
        ]);

        $response->assertStatus(200);
        $this->assertEquals($postB->id, $comment->fresh()->post_id);
    }

    // ------------------------------------------------------------------
    // Indirect FK chain: reply → comment → post → blog → org (3-level chain)
    // ------------------------------------------------------------------

    public function test_rejects_creating_reply_with_comment_id_from_another_org(): void
    {
        Gate::policy(TenantBlog::class, TenantBlogPolicy::class);
        Gate::policy(TenantPost::class, TenantPostPolicy::class);
        Gate::policy(TenantComment::class, TenantCommentPolicy::class);
        Gate::policy(TenantReply::class, TenantReplyPolicy::class);
        $this->registerTenantRoutes([
            'blogs' => TenantBlog::class,
            'posts' => TenantPost::class,
            'comments' => TenantComment::class,
            'replies' => TenantReply::class,
        ]);

        [$user, $orgA] = $this->createUserInOrg('org-a');
        $orgB = \App\Models\Organization::forceCreate(['name' => 'Org B', 'slug' => 'org-b', 'domain' => null]);

        $blogB = TenantBlog::forceCreate(['organization_id' => $orgB->id, 'name' => 'Blog B', 'is_published' => false]);
        $postB = TenantPost::forceCreate(['blog_id' => $blogB->id, 'title' => 'Post B', 'content' => 'Body']);
        $commentB = TenantComment::forceCreate(['post_id' => $postB->id, 'body' => 'Comment in Org B']);

        $response = $this->postJson("/api/org-a/replies", [
            'comment_id' => $commentB->id,
            'body' => 'Cross-tenant reply',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['comment_id']);
    }

    public function test_allows_creating_reply_with_comment_id_from_same_org(): void
    {
        Gate::policy(TenantBlog::class, TenantBlogPolicy::class);
        Gate::policy(TenantPost::class, TenantPostPolicy::class);
        Gate::policy(TenantComment::class, TenantCommentPolicy::class);
        Gate::policy(TenantReply::class, TenantReplyPolicy::class);
        $this->registerTenantRoutes([
            'blogs' => TenantBlog::class,
            'posts' => TenantPost::class,
            'comments' => TenantComment::class,
            'replies' => TenantReply::class,
        ]);

        [$user, $org] = $this->createUserInOrg('org-a');
        $blog = TenantBlog::forceCreate(['organization_id' => $org->id, 'name' => 'Blog A', 'is_published' => false]);
        $post = TenantPost::forceCreate(['blog_id' => $blog->id, 'title' => 'Post A', 'content' => 'Body']);
        $comment = TenantComment::forceCreate(['post_id' => $post->id, 'body' => 'Comment in Org A']);

        $response = $this->postJson("/api/org-a/replies", [
            'comment_id' => $comment->id,
            'body' => 'Same-tenant reply',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('body', 'Same-tenant reply');
    }

    public function test_rejects_updating_reply_comment_id_to_another_org(): void
    {
        Gate::policy(TenantBlog::class, TenantBlogPolicy::class);
        Gate::policy(TenantPost::class, TenantPostPolicy::class);
        Gate::policy(TenantComment::class, TenantCommentPolicy::class);
        Gate::policy(TenantReply::class, TenantReplyPolicy::class);
        $this->registerTenantRoutes([
            'blogs' => TenantBlog::class,
            'posts' => TenantPost::class,
            'comments' => TenantComment::class,
            'replies' => TenantReply::class,
        ]);

        [$user, $orgA] = $this->createUserInOrg('org-a');
        $orgB = \App\Models\Organization::forceCreate(['name' => 'Org B', 'slug' => 'org-b', 'domain' => null]);

        // Create reply in org A
        $blogA = TenantBlog::forceCreate(['organization_id' => $orgA->id, 'name' => 'Blog A', 'is_published' => false]);
        $postA = TenantPost::forceCreate(['blog_id' => $blogA->id, 'title' => 'Post A', 'content' => 'Body']);
        $commentA = TenantComment::forceCreate(['post_id' => $postA->id, 'body' => 'Comment A']);
        $reply = TenantReply::forceCreate(['comment_id' => $commentA->id, 'body' => 'Original reply']);

        // Create comment in org B
        $blogB = TenantBlog::forceCreate(['organization_id' => $orgB->id, 'name' => 'Blog B', 'is_published' => false]);
        $postB = TenantPost::forceCreate(['blog_id' => $blogB->id, 'title' => 'Post B', 'content' => 'Body']);
        $commentB = TenantComment::forceCreate(['post_id' => $postB->id, 'body' => 'Comment B']);

        $response = $this->putJson("/api/org-a/replies/{$reply->id}", [
            'comment_id' => $commentB->id,
            'body' => 'Moved to another org',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors(['comment_id']);
    }

    public function test_allows_updating_reply_comment_id_within_same_org(): void
    {
        Gate::policy(TenantBlog::class, TenantBlogPolicy::class);
        Gate::policy(TenantPost::class, TenantPostPolicy::class);
        Gate::policy(TenantComment::class, TenantCommentPolicy::class);
        Gate::policy(TenantReply::class, TenantReplyPolicy::class);
        $this->registerTenantRoutes([
            'blogs' => TenantBlog::class,
            'posts' => TenantPost::class,
            'comments' => TenantComment::class,
            'replies' => TenantReply::class,
        ]);

        [$user, $org] = $this->createUserInOrg('org-a');
        $blog = TenantBlog::forceCreate(['organization_id' => $org->id, 'name' => 'Blog A', 'is_published' => false]);
        $post = TenantPost::forceCreate(['blog_id' => $blog->id, 'title' => 'Post A', 'content' => 'Body']);
        $commentA = TenantComment::forceCreate(['post_id' => $post->id, 'body' => 'Comment A']);
        $commentB = TenantComment::forceCreate(['post_id' => $post->id, 'body' => 'Comment B']);
        $reply = TenantReply::forceCreate(['comment_id' => $commentA->id, 'body' => 'Original reply']);

        $response = $this->putJson("/api/org-a/replies/{$reply->id}", [
            'comment_id' => $commentB->id,
            'body' => 'Moved within org',
        ]);

        $response->assertStatus(200);
        $this->assertEquals($commentB->id, $reply->fresh()->comment_id);
    }
}
