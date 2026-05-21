<?php

namespace Rhino\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Rhino\Policies\ResourcePolicy;
use Rhino\Tests\TestCase;
use Rhino\Traits\HasValidation;
use Rhino\Traits\HidableColumns;

// --------------------------------------------------------------------------
// Test Models
// --------------------------------------------------------------------------

class SoftDeletePost extends Model
{
    use SoftDeletes, HasValidation, HidableColumns;

    protected $table = 'soft_delete_posts';
    protected $fillable = ['title', 'content'];
}

class NonSoftDeletePost extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'non_soft_delete_posts';
    protected $fillable = ['title', 'content'];
}

// --------------------------------------------------------------------------
// Test Policies
// --------------------------------------------------------------------------

/**
 * Permissive policy for functional tests (not permission tests).
 */
class SoftDeletePostPolicy
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

class NonSoftDeletePostPolicy
{
    public function viewAny(?Authenticatable $user): bool { return true; }
    public function view(?Authenticatable $user, $model): bool { return true; }
    public function create(?Authenticatable $user): bool { return true; }
    public function update(?Authenticatable $user, $model): bool { return true; }
    public function delete(?Authenticatable $user, $model): bool { return true; }
}

/**
 * ResourcePolicy-based policy for permission tests.
 */
class SoftDeletePostResourcePolicy extends ResourcePolicy
{
    protected ?string $resourceSlug = 'posts';
}

/**
 * Policy that restricts restore to only the record owner.
 */
class SoftDeleteRestrictedRestorePolicy extends ResourcePolicy
{
    protected ?string $resourceSlug = 'posts';

    public function restore(?Authenticatable $user, $model): bool
    {
        return parent::restore($user, $model) && $user->getAuthIdentifier() === ($model->user_id ?? null);
    }
}

// --------------------------------------------------------------------------
// Tests
// --------------------------------------------------------------------------

class SoftDeleteTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('soft_delete_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('non_soft_delete_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content')->nullable();
            $table->timestamps();
        });

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        Gate::policy(SoftDeletePost::class, SoftDeletePostPolicy::class);
        Gate::policy(NonSoftDeletePost::class, NonSoftDeletePostPolicy::class);
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

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function registerRoutes(array $models, array $public = []): void
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

    protected function authenticateWithPermissions(array $permissions, int $userId = 1): \App\Models\User
    {
        $user = \App\Models\User::firstOrCreate(
            ['id' => $userId],
            [
                'name' => "User {$userId}",
                'email' => "user{$userId}@example.com",
                'password' => bcrypt('password'),
                'permissions' => $permissions,
            ]
        );

        $this->actingAs($user, 'sanctum');

        return $user;
    }

    protected function createPost(string $title = 'Test Post', ?int $userId = null): SoftDeletePost
    {
        return SoftDeletePost::forceCreate([
            'title' => $title,
            'content' => "Content for {$title}",
            'user_id' => $userId,
        ]);
    }

    protected function createAndDeletePost(string $title = 'Deleted Post', ?int $userId = null): SoftDeletePost
    {
        $post = $this->createPost($title, $userId);
        $post->delete();
        return $post;
    }

    // ==================================================================
    // Helpers for route assertions
    // ==================================================================

    protected function getRouteNames(): array
    {
        return collect(Route::getRoutes()->getRoutes())
            ->map(fn ($route) => $route->getName())
            ->filter()
            ->values()
            ->toArray();
    }

    // ==================================================================
    // Route Registration
    // ==================================================================

    public function test_soft_delete_routes_registered_for_soft_delete_model(): void
    {
        $this->registerRoutes(['posts' => SoftDeletePost::class], ['posts']);

        $names = $this->getRouteNames();

        $this->assertContains('public.posts.trashed', $names, 'trashed route should exist');
        $this->assertContains('public.posts.restore', $names, 'restore route should exist');
        $this->assertContains('public.posts.forceDelete', $names, 'forceDelete route should exist');
    }

    public function test_soft_delete_routes_not_registered_for_non_soft_delete_model(): void
    {
        $this->registerRoutes(['no-soft-posts' => NonSoftDeletePost::class], ['no-soft-posts']);

        $names = $this->getRouteNames();

        $this->assertNotContains('public.no-soft-posts.trashed', $names, 'trashed route should NOT exist');
        $this->assertNotContains('public.no-soft-posts.restore', $names, 'restore route should NOT exist');
        $this->assertNotContains('public.no-soft-posts.forceDelete', $names, 'forceDelete route should NOT exist');
    }

    public function test_soft_delete_routes_respect_except_actions(): void
    {
        $this->registerRoutes(['posts' => SoftDeletePost::class], ['posts']);

        $names = $this->getRouteNames();

        // All 8 routes should exist (5 CRUD + 3 soft delete)
        $this->assertContains('public.posts.index', $names);
        $this->assertContains('public.posts.store', $names);
        $this->assertContains('public.posts.show', $names);
        $this->assertContains('public.posts.update', $names);
        $this->assertContains('public.posts.destroy', $names);
        $this->assertContains('public.posts.trashed', $names);
        $this->assertContains('public.posts.restore', $names);
        $this->assertContains('public.posts.forceDelete', $names);
    }

    // ==================================================================
    // GET /trashed — List soft-deleted records
    // ==================================================================

    public function test_trashed_returns_only_soft_deleted_records(): void
    {
        $this->registerRoutes(['posts' => SoftDeletePost::class], ['posts']);

        // Create active and deleted posts
        $this->createPost('Active Post');
        $this->createAndDeletePost('Deleted Post 1');
        $this->createAndDeletePost('Deleted Post 2');

        $response = $this->getJson('/api/posts/trashed');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertIsArray($data);
        $this->assertCount(2, $data);
        $this->assertEquals('Deleted Post 1', $data[0]['title']);
        $this->assertEquals('Deleted Post 2', $data[1]['title']);
    }

    public function test_trashed_does_not_return_active_records(): void
    {
        $this->registerRoutes(['posts' => SoftDeletePost::class], ['posts']);

        $this->createPost('Active Post 1');
        $this->createPost('Active Post 2');

        $response = $this->getJson('/api/posts/trashed');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertIsArray($data);
        $this->assertCount(0, $data);
    }

    public function test_trashed_returns_empty_when_no_deleted_records(): void
    {
        $this->registerRoutes(['posts' => SoftDeletePost::class], ['posts']);

        $response = $this->getJson('/api/posts/trashed');

        $response->assertStatus(200);
        $this->assertCount(0, $response->json('data'));
    }

    public function test_trashed_supports_pagination(): void
    {
        $this->registerRoutes(['posts' => SoftDeletePost::class], ['posts']);

        for ($i = 1; $i <= 8; $i++) {
            $this->createAndDeletePost("Deleted Post {$i}");
        }

        $response = $this->getJson('/api/posts/trashed?per_page=3');

        $response->assertStatus(200);
        $data = $response->json('data');

        $this->assertCount(3, $data);
        $response->assertHeader('X-Total', '8');
        $response->assertHeader('X-Per-Page', '3');
        $response->assertHeader('X-Last-Page', '3');
    }

    // ==================================================================
    // POST /{id}/restore — Restore a soft-deleted record
    // ==================================================================

    public function test_restore_brings_back_deleted_record(): void
    {
        $this->registerRoutes(['posts' => SoftDeletePost::class], ['posts']);

        $post = $this->createAndDeletePost('Deleted Post');
        $this->assertSoftDeleted('soft_delete_posts', ['id' => $post->id]);

        $response = $this->postJson("/api/posts/{$post->id}/restore");

        $response->assertStatus(200);
        $response->assertJsonFragment(['title' => 'Deleted Post']);

        // Verify the record is no longer soft-deleted
        $this->assertDatabaseHas('soft_delete_posts', [
            'id' => $post->id,
            'deleted_at' => null,
        ]);
    }

    public function test_restore_returns_404_for_non_deleted_record(): void
    {
        $this->registerRoutes(['posts' => SoftDeletePost::class], ['posts']);

        $post = $this->createPost('Active Post');

        $response = $this->postJson("/api/posts/{$post->id}/restore");

        // onlyTrashed() won't find an active record
        $response->assertStatus(404);
    }

    public function test_restore_returns_404_for_nonexistent_record(): void
    {
        $this->registerRoutes(['posts' => SoftDeletePost::class], ['posts']);

        $response = $this->postJson('/api/posts/999/restore');

        $response->assertStatus(404);
    }

    // ==================================================================
    // DELETE /{id}/force-delete — Permanently delete
    // ==================================================================

    public function test_force_delete_permanently_removes_record(): void
    {
        $this->registerRoutes(['posts' => SoftDeletePost::class], ['posts']);

        $post = $this->createAndDeletePost('To Be Gone');

        $response = $this->deleteJson("/api/posts/{$post->id}/force-delete");

        $response->assertStatus(204);

        // Record should be completely gone from the database
        $this->assertDatabaseMissing('soft_delete_posts', ['id' => $post->id]);
    }

    public function test_force_delete_returns_404_for_active_record(): void
    {
        $this->registerRoutes(['posts' => SoftDeletePost::class], ['posts']);

        $post = $this->createPost('Active Post');

        $response = $this->deleteJson("/api/posts/{$post->id}/force-delete");

        // onlyTrashed() won't find an active record
        $response->assertStatus(404);
    }

    public function test_force_delete_returns_404_for_nonexistent_record(): void
    {
        $this->registerRoutes(['posts' => SoftDeletePost::class], ['posts']);

        $response = $this->deleteJson('/api/posts/999/force-delete');

        $response->assertStatus(404);
    }

    // ==================================================================
    // Standard destroy still soft-deletes (not permanent)
    // ==================================================================

    public function test_destroy_soft_deletes_not_permanent(): void
    {
        $this->registerRoutes(['posts' => SoftDeletePost::class], ['posts']);

        $post = $this->createPost('Soft Delete Me');

        $response = $this->deleteJson("/api/posts/{$post->id}");

        $response->assertStatus(204);

        // Record should still exist but be soft-deleted
        $this->assertSoftDeleted('soft_delete_posts', ['id' => $post->id]);

        // It should show up in trashed
        $trashedResponse = $this->getJson('/api/posts/trashed');
        $trashedResponse->assertStatus(200);
        $this->assertCount(1, $trashedResponse->json('data'));
        $this->assertEquals('Soft Delete Me', $trashedResponse->json('data')[0]['title']);
    }

    // ==================================================================
    // Full lifecycle: create → delete → trashed → restore → force-delete
    // ==================================================================

    public function test_full_soft_delete_lifecycle(): void
    {
        $this->registerRoutes(['posts' => SoftDeletePost::class], ['posts']);

        // 1. Create
        $post = $this->createPost('Lifecycle Post');
        $this->assertDatabaseHas('soft_delete_posts', ['id' => $post->id, 'deleted_at' => null]);

        // 2. Soft delete (standard destroy)
        $this->deleteJson("/api/posts/{$post->id}")->assertStatus(204);
        $this->assertSoftDeleted('soft_delete_posts', ['id' => $post->id]);

        // 3. Visible in trashed
        $trashed = $this->getJson('/api/posts/trashed');
        $this->assertCount(1, $trashed->json('data'));

        // 4. Not visible in index
        $index = $this->getJson('/api/posts');
        $this->assertCount(0, $index->json('data'));

        // 5. Restore
        $this->postJson("/api/posts/{$post->id}/restore")->assertStatus(200);
        $this->assertDatabaseHas('soft_delete_posts', ['id' => $post->id, 'deleted_at' => null]);

        // 6. Visible in index again
        $index = $this->getJson('/api/posts');
        $this->assertCount(1, $index->json('data'));

        // 7. Soft delete again
        $this->deleteJson("/api/posts/{$post->id}")->assertStatus(204);

        // 8. Force delete (permanent)
        $this->deleteJson("/api/posts/{$post->id}/force-delete")->assertStatus(204);
        $this->assertDatabaseMissing('soft_delete_posts', ['id' => $post->id]);
    }

    // ==================================================================
    // Permission checks
    // ==================================================================

    public function test_trashed_requires_trashed_permission(): void
    {
        $this->registerRoutes(['posts' => SoftDeletePost::class]);
        Gate::policy(SoftDeletePost::class, SoftDeletePostResourcePolicy::class);

        // User has index but NOT trashed permission
        $this->authenticateWithPermissions(['posts.index']);

        $this->createAndDeletePost('Deleted');

        $response = $this->getJson('/api/posts/trashed');

        $response->assertStatus(403);
    }

    public function test_trashed_allowed_with_trashed_permission(): void
    {
        $this->registerRoutes(['posts' => SoftDeletePost::class]);
        Gate::policy(SoftDeletePost::class, SoftDeletePostResourcePolicy::class);

        $this->authenticateWithPermissions(['posts.trashed']);

        $this->createAndDeletePost('Deleted');

        $response = $this->getJson('/api/posts/trashed');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json());
    }

    public function test_restore_requires_restore_permission(): void
    {
        $this->registerRoutes(['posts' => SoftDeletePost::class]);
        Gate::policy(SoftDeletePost::class, SoftDeletePostResourcePolicy::class);

        // User has update but NOT restore
        $this->authenticateWithPermissions(['posts.update']);

        $post = $this->createAndDeletePost('Deleted');

        $response = $this->postJson("/api/posts/{$post->id}/restore");

        $response->assertStatus(403);
    }

    public function test_restore_allowed_with_restore_permission(): void
    {
        $this->registerRoutes(['posts' => SoftDeletePost::class]);
        Gate::policy(SoftDeletePost::class, SoftDeletePostResourcePolicy::class);

        $this->authenticateWithPermissions(['posts.restore']);

        $post = $this->createAndDeletePost('Deleted');

        $response = $this->postJson("/api/posts/{$post->id}/restore");

        $response->assertStatus(200);
    }

    public function test_force_delete_requires_force_delete_permission(): void
    {
        $this->registerRoutes(['posts' => SoftDeletePost::class]);
        Gate::policy(SoftDeletePost::class, SoftDeletePostResourcePolicy::class);

        // User has destroy but NOT forceDelete
        $this->authenticateWithPermissions(['posts.destroy']);

        $post = $this->createAndDeletePost('Deleted');

        $response = $this->deleteJson("/api/posts/{$post->id}/force-delete");

        $response->assertStatus(403);
    }

    public function test_force_delete_allowed_with_force_delete_permission(): void
    {
        $this->registerRoutes(['posts' => SoftDeletePost::class]);
        Gate::policy(SoftDeletePost::class, SoftDeletePostResourcePolicy::class);

        $this->authenticateWithPermissions(['posts.forceDelete']);

        $post = $this->createAndDeletePost('Deleted');

        $response = $this->deleteJson("/api/posts/{$post->id}/force-delete");

        $response->assertStatus(204);
    }

    public function test_wildcard_grants_all_soft_delete_actions(): void
    {
        $this->registerRoutes(['posts' => SoftDeletePost::class]);
        Gate::policy(SoftDeletePost::class, SoftDeletePostResourcePolicy::class);

        $this->authenticateWithPermissions(['*']);

        $post1 = $this->createAndDeletePost('Deleted 1');
        $post2 = $this->createAndDeletePost('Deleted 2');

        $this->getJson('/api/posts/trashed')->assertStatus(200);
        $this->postJson("/api/posts/{$post1->id}/restore")->assertStatus(200);
        $this->deleteJson("/api/posts/{$post2->id}/force-delete")->assertStatus(204);
    }

    public function test_resource_wildcard_grants_soft_delete_actions(): void
    {
        $this->registerRoutes(['posts' => SoftDeletePost::class]);
        Gate::policy(SoftDeletePost::class, SoftDeletePostResourcePolicy::class);

        $this->authenticateWithPermissions(['posts.*']);

        $post = $this->createAndDeletePost('Deleted');

        $this->getJson('/api/posts/trashed')->assertStatus(200);
        $this->postJson("/api/posts/{$post->id}/restore")->assertStatus(200);

        // Delete again then force delete
        $post->delete();
        $this->deleteJson("/api/posts/{$post->id}/force-delete")->assertStatus(204);
    }

    // ==================================================================
    // Policy override for soft delete methods
    // ==================================================================

    public function test_restore_policy_can_be_overridden(): void
    {
        $this->registerRoutes(['posts' => SoftDeletePost::class]);
        Gate::policy(SoftDeletePost::class, SoftDeleteRestrictedRestorePolicy::class);

        $user = $this->authenticateWithPermissions(['posts.*']);

        // Post owned by the user — should be allowed
        $ownedPost = $this->createAndDeletePost('My Post', $user->id);
        $this->postJson("/api/posts/{$ownedPost->id}/restore")->assertStatus(200);

        // Post owned by someone else — should be denied
        $otherPost = $this->createAndDeletePost('Other Post', 999);
        $this->postJson("/api/posts/{$otherPost->id}/restore")->assertStatus(403);
    }

    // ==================================================================
    // Non-SoftDeletes model returns 404
    // ==================================================================

    public function test_trashed_returns_404_for_model_without_soft_deletes(): void
    {
        $this->registerRoutes(['no-soft-posts' => NonSoftDeletePost::class], ['no-soft-posts']);

        // Routes shouldn't even be registered, so 404
        $response = $this->getJson('/api/no-soft-posts/trashed');

        $response->assertStatus(404);
    }
}
