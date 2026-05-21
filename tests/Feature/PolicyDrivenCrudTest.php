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
use Rhino\Traits\HasValidation;
use Rhino\Traits\HidableColumns;

// --------------------------------------------------------------------------
// Test Models
// --------------------------------------------------------------------------

/**
 * Model using the NEW policy-driven path (no $validationRulesStore/$validationRulesUpdate).
 */
class PolicyDrivenPost extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'policy_driven_posts';
    protected $fillable = ['title', 'content', 'status'];

    protected $validationRules = [
        'title' => 'string|max:255',
        'content' => 'string',
        'status' => 'string|in:draft,published',
    ];
}

/**
 * Model using the LEGACY path (has $validationRulesStore/$validationRulesUpdate).
 */
class LegacyPost extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'policy_driven_posts';
    protected $fillable = ['title', 'content', 'status'];

    protected $validationRules = [
        'title' => 'string|max:255',
        'content' => 'string',
        'status' => 'string|in:draft,published',
    ];

    protected $validationRulesStore = ['title', 'content'];
    protected $validationRulesUpdate = ['title', 'content'];
}

// --------------------------------------------------------------------------
// Test Policies
// --------------------------------------------------------------------------

/**
 * Policy that restricts create/update to title and content only (no status).
 */
class PolicyDrivenPostPolicy extends ResourcePolicy
{
    public function viewAny(?Authenticatable $user): bool { return true; }
    public function view(?Authenticatable $user, $model): bool { return true; }
    public function create(?Authenticatable $user): bool { return true; }
    public function update(?Authenticatable $user, $model): bool { return true; }
    public function delete(?Authenticatable $user, $model): bool { return true; }

    public function permittedAttributesForCreate(?Authenticatable $user): array
    {
        return ['title', 'content'];
    }

    public function permittedAttributesForUpdate(?Authenticatable $user): array
    {
        return ['title', 'content'];
    }
}

/**
 * Policy that allows all fields (wildcard).
 */
class PolicyDrivenPostWildcardPolicy extends ResourcePolicy
{
    public function viewAny(?Authenticatable $user): bool { return true; }
    public function view(?Authenticatable $user, $model): bool { return true; }
    public function create(?Authenticatable $user): bool { return true; }
    public function update(?Authenticatable $user, $model): bool { return true; }
    public function delete(?Authenticatable $user, $model): bool { return true; }

    public function permittedAttributesForCreate(?Authenticatable $user): array
    {
        return ['*'];
    }

    public function permittedAttributesForUpdate(?Authenticatable $user): array
    {
        return ['*'];
    }
}

/**
 * Simple policy for legacy model (allows all CRUD, no attribute restrictions).
 */
class LegacyPostPolicy
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

class PolicyDrivenCrudTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('policy_driven_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content')->nullable();
            $table->string('status')->default('draft');
            $table->timestamps();
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
    // Store (POST) tests
    // ------------------------------------------------------------------

    public function test_store_with_permitted_fields_succeeds(): void
    {
        Gate::policy(PolicyDrivenPost::class, PolicyDrivenPostPolicy::class);
        $this->registerRoutes(['posts' => PolicyDrivenPost::class]);
        $this->authenticate();

        $response = $this->postJson('/api/posts', [
            'title' => 'My Post',
            'content' => 'Hello world',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('title', 'My Post');
        $response->assertJsonPath('content', 'Hello world');
    }

    public function test_store_with_forbidden_fields_returns_403(): void
    {
        Gate::policy(PolicyDrivenPost::class, PolicyDrivenPostPolicy::class);
        $this->registerRoutes(['posts' => PolicyDrivenPost::class]);
        $this->authenticate();

        $response = $this->postJson('/api/posts', [
            'title' => 'My Post',
            'content' => 'Hello',
            'status' => 'published', // not permitted
        ]);

        $response->assertStatus(403);
        $response->assertJsonFragment([
            'message' => 'You are not allowed to set the following field(s): status',
        ]);
    }

    public function test_store_with_wildcard_permits_all_fields(): void
    {
        Gate::policy(PolicyDrivenPost::class, PolicyDrivenPostWildcardPolicy::class);
        $this->registerRoutes(['posts' => PolicyDrivenPost::class]);
        $this->authenticate();

        $response = $this->postJson('/api/posts', [
            'title' => 'My Post',
            'content' => 'Hello',
            'status' => 'published',
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('status', 'published');
    }

    public function test_store_validation_failure_returns_422(): void
    {
        Gate::policy(PolicyDrivenPost::class, PolicyDrivenPostWildcardPolicy::class);
        $this->registerRoutes(['posts' => PolicyDrivenPost::class]);
        $this->authenticate();

        $response = $this->postJson('/api/posts', [
            'title' => 'Valid',
            'status' => 'invalid_status', // fails in:draft,published
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('status');
    }

    // ------------------------------------------------------------------
    // Update (PUT) tests
    // ------------------------------------------------------------------

    public function test_update_with_permitted_fields_succeeds(): void
    {
        Gate::policy(PolicyDrivenPost::class, PolicyDrivenPostPolicy::class);
        $this->registerRoutes(['posts' => PolicyDrivenPost::class]);
        $this->authenticate();

        $post = PolicyDrivenPost::forceCreate(['title' => 'Original', 'content' => 'Body']);

        $response = $this->putJson("/api/posts/{$post->id}", [
            'title' => 'Updated Title',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('title', 'Updated Title');
    }

    public function test_update_with_forbidden_fields_returns_403(): void
    {
        Gate::policy(PolicyDrivenPost::class, PolicyDrivenPostPolicy::class);
        $this->registerRoutes(['posts' => PolicyDrivenPost::class]);
        $this->authenticate();

        $post = PolicyDrivenPost::forceCreate(['title' => 'Original', 'content' => 'Body']);

        $response = $this->putJson("/api/posts/{$post->id}", [
            'title' => 'Updated',
            'status' => 'published', // not permitted
        ]);

        $response->assertStatus(403);
        $response->assertJsonFragment([
            'message' => 'You are not allowed to set the following field(s): status',
        ]);
    }

    // ------------------------------------------------------------------
    // Legacy model still uses old path
    // ------------------------------------------------------------------

    public function test_legacy_model_still_uses_old_validation_path(): void
    {
        Gate::policy(LegacyPost::class, LegacyPostPolicy::class);
        $this->registerRoutes(['posts' => LegacyPost::class]);
        $this->authenticate();

        // Legacy model: $validationRulesStore only allows ['title', 'content']
        // Extra fields like 'status' are silently ignored (not 403)
        $response = $this->postJson('/api/posts', [
            'title' => 'My Post',
            'content' => 'Hello',
            'status' => 'published', // silently ignored by legacy path
        ]);

        $response->assertStatus(201);
        $response->assertJsonPath('title', 'My Post');
        // Status should be the default value, not 'published'
        $this->assertEquals('draft', PolicyDrivenPost::find($response->json('id'))->status);
    }

    // ------------------------------------------------------------------
    // Nested endpoint tests
    // ------------------------------------------------------------------

    public function test_nested_create_with_forbidden_fields_returns_403(): void
    {
        Gate::policy(PolicyDrivenPost::class, PolicyDrivenPostPolicy::class);
        $this->registerRoutes(['posts' => PolicyDrivenPost::class]);
        $this->authenticate();

        $response = $this->postJson('/api/nested', [
            'operations' => [
                [
                    'action' => 'create',
                    'model' => 'posts',
                    'data' => [
                        'title' => 'Nested Post',
                        'content' => 'Body',
                        'status' => 'published', // not permitted
                    ],
                ],
            ],
        ]);

        $response->assertStatus(403);
    }

    public function test_nested_create_with_permitted_fields_succeeds(): void
    {
        Gate::policy(PolicyDrivenPost::class, PolicyDrivenPostPolicy::class);
        $this->registerRoutes(['posts' => PolicyDrivenPost::class]);
        $this->authenticate();

        $response = $this->postJson('/api/nested', [
            'operations' => [
                [
                    'action' => 'create',
                    'model' => 'posts',
                    'data' => [
                        'title' => 'Nested Post',
                        'content' => 'Body',
                    ],
                ],
            ],
        ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('policy_driven_posts', ['title' => 'Nested Post']);
    }
}
