<?php

namespace Rhino\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Rhino\Contracts\HasPermittedAttributes;
use Rhino\Tests\TestCase;
use Rhino\Traits\HasValidation;
use Rhino\Traits\HidableColumns;

// --------------------------------------------------------------------------
// Test Models
// --------------------------------------------------------------------------

class GcNestedArticle extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'gc_nested_articles';
    protected $fillable = ['title', 'content'];

    protected $validationRules = [
        'title' => 'required|string|max:255',
        'content' => 'string',
    ];
}

class GcNestedLegacyPost extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'gc_nested_legacy_posts';
    protected $fillable = ['title', 'body'];

    protected $validationRules = [
        'title' => 'string|max:255',
        'body' => 'string',
    ];

    protected $validationRulesStore = ['title', 'body'];
    protected $validationRulesUpdate = ['title'];
}

class GcNestedSoftModel extends Model
{
    use SoftDeletes, HasValidation, HidableColumns;

    protected $table = 'gc_nested_soft_models';
    protected $fillable = ['title'];

    public static $allowedFilters = ['title'];
    public static $allowedSorts = ['title', 'created_at'];
    public static $allowedSearch = ['title'];
    public static $defaultSort = '-created_at';
    public static $paginationEnabled = true;
}

class GcNestedNoPaginationModel extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'gc_nested_articles';
    protected $fillable = ['title', 'content'];

    public static $allowedFilters = ['title'];
    public static $allowedSorts = ['title'];
    public static $allowedFields = ['id', 'title', 'content'];
}

class GcNestedOwnerModel extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'gc_nested_articles';
    protected $fillable = ['title', 'content'];

    public static $owner = 'none';
}

// --------------------------------------------------------------------------
// Test Policies
// --------------------------------------------------------------------------

class GcNestedPermissivePolicy
{
    public function viewAny(?Authenticatable $user): bool { return true; }
    public function view(?Authenticatable $user, $model): bool { return true; }
    public function create(?Authenticatable $user): bool { return true; }
    public function update(?Authenticatable $user, $model): bool { return true; }
    public function delete(?Authenticatable $user, $model): bool { return true; }
}

class GcNestedSoftPolicy
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

class GcNestedDenySoftPolicy
{
    public function viewAny(?Authenticatable $user): bool { return true; }
    public function view(?Authenticatable $user, $model): bool { return true; }
    public function create(?Authenticatable $user): bool { return true; }
    public function update(?Authenticatable $user, $model): bool { return true; }
    public function delete(?Authenticatable $user, $model): bool { return true; }
    public function viewTrashed(?Authenticatable $user): bool { return false; }
    public function restore(?Authenticatable $user, $model): bool { return false; }
    public function forceDelete(?Authenticatable $user, $model): bool { return false; }
}

class GcNestedLegacyPolicy
{
    public function viewAny(?Authenticatable $user): bool { return true; }
    public function view(?Authenticatable $user, $model): bool { return true; }
    public function create(?Authenticatable $user): bool { return true; }
    public function update(?Authenticatable $user, $model): bool { return true; }
    public function delete(?Authenticatable $user, $model): bool { return true; }
}

class GcNestedDenyCreatePolicy
{
    public function viewAny(?Authenticatable $user): bool { return true; }
    public function view(?Authenticatable $user, $model): bool { return true; }
    public function create(?Authenticatable $user): bool { return false; }
    public function update(?Authenticatable $user, $model): bool { return false; }
    public function delete(?Authenticatable $user, $model): bool { return false; }
}

class GcNestedPermittedPolicy implements HasPermittedAttributes
{
    public function viewAny(?Authenticatable $user): bool { return true; }
    public function view(?Authenticatable $user, $model): bool { return true; }
    public function create(?Authenticatable $user): bool { return true; }
    public function update(?Authenticatable $user, $model): bool { return true; }
    public function delete(?Authenticatable $user, $model): bool { return true; }

    public function permittedAttributesForCreate(?Authenticatable $user): array
    {
        return ['title'];
    }

    public function permittedAttributesForUpdate(?Authenticatable $user): array
    {
        return ['title'];
    }

    public function permittedAttributesForShow(?Authenticatable $user): array
    {
        return ['*'];
    }

    public function hiddenAttributesForShow(?Authenticatable $user): array
    {
        return [];
    }
}

// --------------------------------------------------------------------------
// Tests
// --------------------------------------------------------------------------

class GlobalControllerNestedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('gc_nested_articles', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content')->nullable();
            $table->timestamps();
        });

        Schema::create('gc_nested_legacy_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('body')->nullable();
            $table->timestamps();
        });

        Schema::create('gc_nested_soft_models', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->timestamps();
            $table->softDeletes();
        });

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        Gate::policy(GcNestedArticle::class, GcNestedPermissivePolicy::class);
        Gate::policy(GcNestedLegacyPost::class, GcNestedLegacyPolicy::class);
        Gate::policy(GcNestedSoftModel::class, GcNestedSoftPolicy::class);
        Gate::policy(GcNestedNoPaginationModel::class, GcNestedPermissivePolicy::class);
        Gate::policy(GcNestedOwnerModel::class, GcNestedPermissivePolicy::class);
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

    protected function registerRoutes(array $models, array $nestedConfig = [], bool $public = false): void
    {
        $routeGroups = $public
            ? ['public' => ['prefix' => '', 'middleware' => [], 'models' => array_keys($models)]]
            : ['default' => ['prefix' => '', 'middleware' => [], 'models' => '*']];

        config([
            'rhino.models' => $models,
            'rhino.route_groups' => $routeGroups,
            'rhino.nested' => array_merge([
                'path' => 'nested',
                'max_operations' => 50,
                'allowed_models' => null,
            ], $nestedConfig),
        ]);

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

    // ==================================================================
    // Nested: structure validation
    // ==================================================================

    public function test_nested_requires_operations_array(): void
    {
        $this->registerRoutes(['articles' => GcNestedArticle::class]);
        $this->authenticate();

        $response = $this->postJson('/api/nested', ['foo' => 'bar']);
        $response->assertStatus(422);
        $response->assertJsonFragment(['operations' => ['The operations field is required and must be an array.']]);
    }

    public function test_nested_rejects_non_array_operation(): void
    {
        $this->registerRoutes(['articles' => GcNestedArticle::class]);
        $this->authenticate();

        $response = $this->postJson('/api/nested', ['operations' => ['not-an-array']]);
        $response->assertStatus(422);
    }

    public function test_nested_rejects_missing_model(): void
    {
        $this->registerRoutes(['articles' => GcNestedArticle::class]);
        $this->authenticate();

        $response = $this->postJson('/api/nested', [
            'operations' => [
                ['action' => 'create', 'data' => ['title' => 'Test']],
            ],
        ]);
        $response->assertStatus(422);
    }

    public function test_nested_rejects_invalid_action(): void
    {
        $this->registerRoutes(['articles' => GcNestedArticle::class]);
        $this->authenticate();

        $response = $this->postJson('/api/nested', [
            'operations' => [
                ['model' => 'articles', 'action' => 'delete', 'data' => ['title' => 'Test']],
            ],
        ]);
        $response->assertStatus(422);
    }

    public function test_nested_rejects_missing_data(): void
    {
        $this->registerRoutes(['articles' => GcNestedArticle::class]);
        $this->authenticate();

        $response = $this->postJson('/api/nested', [
            'operations' => [
                ['model' => 'articles', 'action' => 'create'],
            ],
        ]);
        $response->assertStatus(422);
    }

    public function test_nested_update_requires_id(): void
    {
        $this->registerRoutes(['articles' => GcNestedArticle::class]);
        $this->authenticate();

        $response = $this->postJson('/api/nested', [
            'operations' => [
                ['model' => 'articles', 'action' => 'update', 'data' => ['title' => 'Updated']],
            ],
        ]);
        $response->assertStatus(422);
    }

    public function test_nested_rejects_unknown_model(): void
    {
        $this->registerRoutes(['articles' => GcNestedArticle::class]);
        $this->authenticate();

        $response = $this->postJson('/api/nested', [
            'operations' => [
                ['model' => 'unknown_model', 'action' => 'create', 'data' => ['title' => 'Test']],
            ],
        ]);
        $response->assertStatus(422);
    }

    // ==================================================================
    // Nested: max_operations enforcement
    // ==================================================================

    public function test_nested_enforces_max_operations(): void
    {
        $this->registerRoutes(['articles' => GcNestedArticle::class], ['max_operations' => 2]);
        $this->authenticate();

        $response = $this->postJson('/api/nested', [
            'operations' => [
                ['model' => 'articles', 'action' => 'create', 'data' => ['title' => 'One']],
                ['model' => 'articles', 'action' => 'create', 'data' => ['title' => 'Two']],
                ['model' => 'articles', 'action' => 'create', 'data' => ['title' => 'Three']],
            ],
        ]);
        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Too many operations.']);
    }

    // ==================================================================
    // Nested: allowed_models enforcement
    // ==================================================================

    public function test_nested_enforces_allowed_models(): void
    {
        $this->registerRoutes(
            ['articles' => GcNestedArticle::class, 'posts' => GcNestedLegacyPost::class],
            ['allowed_models' => ['articles']]
        );
        $this->authenticate();

        $response = $this->postJson('/api/nested', [
            'operations' => [
                ['model' => 'posts', 'action' => 'create', 'data' => ['title' => 'Denied']],
            ],
        ]);
        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Operation not allowed.']);
    }

    // ==================================================================
    // Nested: successful create
    // ==================================================================

    public function test_nested_create_succeeds(): void
    {
        $this->registerRoutes(['articles' => GcNestedArticle::class]);
        $this->authenticate();

        $response = $this->postJson('/api/nested', [
            'operations' => [
                ['model' => 'articles', 'action' => 'create', 'data' => ['title' => 'Nested Article']],
            ],
        ]);
        $response->assertStatus(200);
        $data = $response->json();
        $this->assertArrayHasKey('results', $data);
        $this->assertCount(1, $data['results']);
        $this->assertEquals('create', $data['results'][0]['action']);
        $this->assertEquals('articles', $data['results'][0]['model']);
        $this->assertDatabaseHas('gc_nested_articles', ['title' => 'Nested Article']);
    }

    // ==================================================================
    // Nested: successful update
    // ==================================================================

    public function test_nested_update_succeeds(): void
    {
        $this->registerRoutes(['articles' => GcNestedArticle::class]);
        $this->authenticate();

        $article = GcNestedArticle::forceCreate(['title' => 'Original']);

        $response = $this->postJson('/api/nested', [
            'operations' => [
                ['model' => 'articles', 'action' => 'update', 'id' => $article->id, 'data' => ['title' => 'Updated Nested']],
            ],
        ]);
        $response->assertStatus(200);
        $data = $response->json();
        $this->assertEquals('update', $data['results'][0]['action']);
        $this->assertDatabaseHas('gc_nested_articles', ['title' => 'Updated Nested']);
    }

    // ==================================================================
    // Nested: update nonexistent returns 404
    // ==================================================================

    public function test_nested_update_nonexistent_returns_404(): void
    {
        $this->registerRoutes(['articles' => GcNestedArticle::class]);
        $this->authenticate();

        $response = $this->postJson('/api/nested', [
            'operations' => [
                ['model' => 'articles', 'action' => 'update', 'id' => 99999, 'data' => ['title' => 'Updated']],
            ],
        ]);
        $response->assertStatus(404);
    }

    // ==================================================================
    // Nested: legacy validation path
    // ==================================================================

    public function test_nested_create_with_legacy_validation(): void
    {
        $this->registerRoutes(['posts' => GcNestedLegacyPost::class]);
        $this->authenticate();

        $response = $this->postJson('/api/nested', [
            'operations' => [
                ['model' => 'posts', 'action' => 'create', 'data' => ['title' => 'Legacy Post', 'body' => 'Content']],
            ],
        ]);
        $response->assertStatus(200);
        $this->assertDatabaseHas('gc_nested_legacy_posts', ['title' => 'Legacy Post']);
    }

    public function test_nested_update_with_legacy_validation(): void
    {
        $this->registerRoutes(['posts' => GcNestedLegacyPost::class]);
        $this->authenticate();

        $post = GcNestedLegacyPost::forceCreate(['title' => 'Old', 'body' => 'Body']);

        $response = $this->postJson('/api/nested', [
            'operations' => [
                ['model' => 'posts', 'action' => 'update', 'id' => $post->id, 'data' => ['title' => 'Updated Legacy']],
            ],
        ]);
        $response->assertStatus(200);
        $this->assertDatabaseHas('gc_nested_legacy_posts', ['title' => 'Updated Legacy']);
    }

    // ==================================================================
    // Nested: validation failure
    // ==================================================================

    public function test_nested_create_validation_failure(): void
    {
        $this->registerRoutes(['articles' => GcNestedArticle::class]);
        $this->authenticate();

        $response = $this->postJson('/api/nested', [
            'operations' => [
                ['model' => 'articles', 'action' => 'create', 'data' => ['content' => 'No title']],
            ],
        ]);
        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Validation failed.']);
    }

    // ==================================================================
    // Nested: forbidden fields via policy
    // ==================================================================

    public function test_nested_create_forbidden_fields_returns_403(): void
    {
        Gate::policy(GcNestedArticle::class, GcNestedPermittedPolicy::class);
        $this->registerRoutes(['articles' => GcNestedArticle::class]);
        $this->authenticate();

        $response = $this->postJson('/api/nested', [
            'operations' => [
                ['model' => 'articles', 'action' => 'create', 'data' => ['title' => 'Test', 'content' => 'Forbidden']],
            ],
        ]);
        $response->assertStatus(403);
    }

    // ==================================================================
    // Nested: authorization denied
    // ==================================================================

    public function test_nested_create_denied_by_policy(): void
    {
        Gate::policy(GcNestedArticle::class, GcNestedDenyCreatePolicy::class);
        $this->registerRoutes(['articles' => GcNestedArticle::class]);
        $this->authenticate();

        $response = $this->postJson('/api/nested', [
            'operations' => [
                ['model' => 'articles', 'action' => 'create', 'data' => ['title' => 'Denied']],
            ],
        ]);
        $response->assertStatus(403);
    }

    // ==================================================================
    // Nested: multiple operations in single request
    // ==================================================================

    public function test_nested_multiple_operations(): void
    {
        $this->registerRoutes(['articles' => GcNestedArticle::class]);
        $this->authenticate();

        $article = GcNestedArticle::forceCreate(['title' => 'Existing']);

        $response = $this->postJson('/api/nested', [
            'operations' => [
                ['model' => 'articles', 'action' => 'create', 'data' => ['title' => 'New Article']],
                ['model' => 'articles', 'action' => 'update', 'id' => $article->id, 'data' => ['title' => 'Updated Existing']],
            ],
        ]);
        $response->assertStatus(200);
        $results = $response->json('results');
        $this->assertCount(2, $results);
        $this->assertEquals('create', $results[0]['action']);
        $this->assertEquals('update', $results[1]['action']);
    }

    // ==================================================================
    // Soft Delete: restore
    // ==================================================================

    public function test_restore_soft_deleted_record(): void
    {
        $this->registerRoutes(['softmodels' => GcNestedSoftModel::class], [], true);

        $model = GcNestedSoftModel::forceCreate(['title' => 'Soft Item']);
        $model->delete();

        $this->assertSoftDeleted('gc_nested_soft_models', ['id' => $model->id]);

        $response = $this->postJson("/api/softmodels/{$model->id}/restore");
        $response->assertStatus(200);

        $this->assertDatabaseHas('gc_nested_soft_models', ['id' => $model->id, 'deleted_at' => null]);
    }

    public function test_restore_nonexistent_returns_404(): void
    {
        $this->registerRoutes(['softmodels' => GcNestedSoftModel::class], [], true);

        $response = $this->postJson('/api/softmodels/99999/restore');
        $response->assertStatus(404);
    }

    // ==================================================================
    // Soft Delete: force-delete
    // ==================================================================

    public function test_force_delete_permanently_removes_record(): void
    {
        $this->registerRoutes(['softmodels' => GcNestedSoftModel::class], [], true);

        $model = GcNestedSoftModel::forceCreate(['title' => 'Permanent Delete']);
        $model->delete();

        $response = $this->deleteJson("/api/softmodels/{$model->id}/force-delete");
        $response->assertStatus(204);

        $this->assertDatabaseMissing('gc_nested_soft_models', ['id' => $model->id]);
    }

    public function test_force_delete_nonexistent_returns_404(): void
    {
        $this->registerRoutes(['softmodels' => GcNestedSoftModel::class], [], true);

        $response = $this->deleteJson('/api/softmodels/99999/force-delete');
        $response->assertStatus(404);
    }

    // ==================================================================
    // Soft Delete: trashed list with pagination
    // ==================================================================

    public function test_trashed_returns_soft_deleted_records(): void
    {
        $this->registerRoutes(['softmodels' => GcNestedSoftModel::class], [], true);

        $model1 = GcNestedSoftModel::forceCreate(['title' => 'Trash A']);
        $model2 = GcNestedSoftModel::forceCreate(['title' => 'Trash B']);
        GcNestedSoftModel::forceCreate(['title' => 'Active']);
        $model1->delete();
        $model2->delete();

        $response = $this->getJson('/api/softmodels/trashed');
        $response->assertStatus(200);
        // With paginationEnabled = true, response uses headers
        $response->assertHeader('X-Total', '2');
    }

    public function test_trashed_with_search_filter(): void
    {
        $this->registerRoutes(['softmodels' => GcNestedSoftModel::class], [], true);

        $model1 = GcNestedSoftModel::forceCreate(['title' => 'Matching Item']);
        $model2 = GcNestedSoftModel::forceCreate(['title' => 'Other Item']);
        $model1->delete();
        $model2->delete();

        $response = $this->getJson('/api/softmodels/trashed?search=Matching');
        $response->assertStatus(200);
        $response->assertHeader('X-Total', '1');
    }

    public function test_trashed_with_sort(): void
    {
        $this->registerRoutes(['softmodels' => GcNestedSoftModel::class], [], true);

        $model1 = GcNestedSoftModel::forceCreate(['title' => 'Zebra']);
        $model2 = GcNestedSoftModel::forceCreate(['title' => 'Apple']);
        $model1->delete();
        $model2->delete();

        $response = $this->getJson('/api/softmodels/trashed?sort=title');
        $response->assertStatus(200);
    }

    public function test_trashed_with_filter(): void
    {
        $this->registerRoutes(['softmodels' => GcNestedSoftModel::class], [], true);

        $model1 = GcNestedSoftModel::forceCreate(['title' => 'UniqueFilterTitle']);
        $model2 = GcNestedSoftModel::forceCreate(['title' => 'Other']);
        $model1->delete();
        $model2->delete();

        // Filters are applied but we just verify the endpoint works with filters
        $response = $this->getJson('/api/softmodels/trashed?filter[title]=UniqueFilterTitle');
        $response->assertStatus(200);
    }

    // ==================================================================
    // Soft Delete: ensureSoftDeletes on non-soft-delete model
    // ==================================================================

    public function test_trashed_endpoint_returns_404_for_non_softdelete_model(): void
    {
        $this->registerRoutes(['articles' => GcNestedArticle::class], [], true);

        // GcNestedArticle doesn't use SoftDeletes
        $response = $this->getJson('/api/articles/trashed');
        $response->assertStatus(404);
    }

    // ==================================================================
    // Index: no pagination model
    // ==================================================================

    public function test_index_without_pagination_returns_all(): void
    {
        $this->registerRoutes(['nopag' => GcNestedNoPaginationModel::class], [], true);

        for ($i = 1; $i <= 3; $i++) {
            GcNestedNoPaginationModel::forceCreate(['title' => "Item {$i}", 'content' => 'body']);
        }

        $response = $this->getJson('/api/nopag');
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(3, $data);
    }

    public function test_index_with_per_page_query_param(): void
    {
        $this->registerRoutes(['nopag' => GcNestedNoPaginationModel::class], [], true);

        for ($i = 1; $i <= 5; $i++) {
            GcNestedNoPaginationModel::forceCreate(['title' => "Item {$i}", 'content' => 'body']);
        }

        $response = $this->getJson('/api/nopag?per_page=2');
        $response->assertStatus(200);
        $response->assertHeader('X-Total', '5');
        $response->assertHeader('X-Per-Page', '2');
    }

    public function test_index_with_filter_and_sort(): void
    {
        $this->registerRoutes(['nopag' => GcNestedNoPaginationModel::class], [], true);

        GcNestedNoPaginationModel::forceCreate(['title' => 'Alpha', 'content' => 'body']);
        GcNestedNoPaginationModel::forceCreate(['title' => 'Beta', 'content' => 'body']);

        // Just verify filters and sorts don't cause errors
        $response = $this->getJson('/api/nopag?filter[title]=Alpha&sort=title');
        $response->assertStatus(200);
    }

    public function test_index_with_fields_selection(): void
    {
        $this->registerRoutes(['nopag' => GcNestedNoPaginationModel::class], [], true);

        GcNestedNoPaginationModel::forceCreate(['title' => 'Sel', 'content' => 'body']);

        $response = $this->getJson('/api/nopag?fields[nopag]=id,title');
        $response->assertStatus(200);
    }

    // ==================================================================
    // Owner model with $owner = 'none'
    // ==================================================================

    public function test_owner_none_model_returns_all_records(): void
    {
        $this->registerRoutes(['ownermodels' => GcNestedOwnerModel::class], [], true);

        GcNestedOwnerModel::forceCreate(['title' => 'Global 1', 'content' => 'body']);
        GcNestedOwnerModel::forceCreate(['title' => 'Global 2', 'content' => 'body']);

        $response = $this->getJson('/api/ownermodels');
        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(2, $data);
    }

    // ==================================================================
    // Store: validation error
    // ==================================================================

    public function test_store_validation_error(): void
    {
        $this->registerRoutes(['articles' => GcNestedArticle::class], [], true);

        $response = $this->postJson('/api/articles', ['content' => 'No title provided']);
        $response->assertStatus(422);
    }

    // ==================================================================
    // Update: reject organization_id change in tenant context
    // (simulated by setting request attributes)
    // ==================================================================

    public function test_update_succeeds_without_org_context(): void
    {
        $this->registerRoutes(['articles' => GcNestedArticle::class], [], true);

        $article = GcNestedArticle::forceCreate(['title' => 'Original']);
        $response = $this->putJson("/api/articles/{$article->id}", ['title' => 'Changed']);
        $response->assertStatus(200);
        $this->assertDatabaseHas('gc_nested_articles', ['title' => 'Changed']);
    }

    // ==================================================================
    // Show: model not found
    // ==================================================================

    public function test_show_nonexistent_record_returns_404(): void
    {
        $this->registerRoutes(['articles' => GcNestedArticle::class], [], true);

        $response = $this->getJson('/api/articles/99999');
        $response->assertStatus(404);
    }

    // ==================================================================
    // Destroy with auth required
    // ==================================================================

    public function test_destroy_with_auth_succeeds(): void
    {
        $this->registerRoutes(['articles' => GcNestedArticle::class]);
        $this->authenticate();

        $article = GcNestedArticle::forceCreate(['title' => 'To Delete']);
        $response = $this->deleteJson("/api/articles/{$article->id}");
        $response->assertStatus(204);
        $this->assertDatabaseMissing('gc_nested_articles', ['id' => $article->id]);
    }

    public function test_destroy_denied_by_policy(): void
    {
        Gate::policy(GcNestedArticle::class, GcNestedDenyCreatePolicy::class);
        $this->registerRoutes(['articles' => GcNestedArticle::class]);
        $this->authenticate();

        $article = GcNestedArticle::forceCreate(['title' => 'Protected']);
        $response = $this->deleteJson("/api/articles/{$article->id}");
        $response->assertStatus(403);
    }

    // ==================================================================
    // Soft Delete: authorization denied
    // ==================================================================

    public function test_trashed_denied_by_policy(): void
    {
        Gate::policy(GcNestedSoftModel::class, GcNestedDenySoftPolicy::class);
        $this->registerRoutes(['softmodels' => GcNestedSoftModel::class], [], true);

        $model = GcNestedSoftModel::forceCreate(['title' => 'Denied Trash']);
        $model->delete();

        $response = $this->getJson('/api/softmodels/trashed');
        $response->assertStatus(403);
    }

    public function test_restore_denied_by_policy(): void
    {
        Gate::policy(GcNestedSoftModel::class, GcNestedDenySoftPolicy::class);
        $this->registerRoutes(['softmodels' => GcNestedSoftModel::class], [], true);

        $model = GcNestedSoftModel::forceCreate(['title' => 'Denied Restore']);
        $model->delete();

        $response = $this->postJson("/api/softmodels/{$model->id}/restore");
        $response->assertStatus(403);
    }

    public function test_force_delete_denied_by_policy(): void
    {
        Gate::policy(GcNestedSoftModel::class, GcNestedDenySoftPolicy::class);
        $this->registerRoutes(['softmodels' => GcNestedSoftModel::class], [], true);

        $model = GcNestedSoftModel::forceCreate(['title' => 'Denied Force']);
        $model->delete();

        $response = $this->deleteJson("/api/softmodels/{$model->id}/force-delete");
        $response->assertStatus(403);
    }

    // ==================================================================
    // Nested: legacy validation failure
    // ==================================================================

    public function test_nested_legacy_validation_failure_on_create(): void
    {
        $this->registerRoutes(['posts' => GcNestedLegacyPost::class]);
        $this->authenticate();

        // Send data with an email field that's not in validationRulesStore
        $response = $this->postJson('/api/nested', [
            'operations' => [
                ['model' => 'posts', 'action' => 'create', 'data' => ['title' => str_repeat('a', 300)]],
            ],
        ]);
        // Should fail due to max:255 rule on title
        $response->assertStatus(422);
    }

    // ==================================================================
    // Nested: policy-driven validation failure on update
    // ==================================================================

    public function test_nested_update_validation_failure(): void
    {
        $this->registerRoutes(['articles' => GcNestedArticle::class]);
        $this->authenticate();

        $article = GcNestedArticle::forceCreate(['title' => 'Valid']);

        $response = $this->postJson('/api/nested', [
            'operations' => [
                ['model' => 'articles', 'action' => 'update', 'id' => $article->id, 'data' => ['title' => '']],
            ],
        ]);
        // title is required, empty string should fail
        $response->assertStatus(422);
    }
}
