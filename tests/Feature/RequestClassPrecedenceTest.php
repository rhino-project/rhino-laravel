<?php

namespace Rhino\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Rhino\Http\Requests\ResourceRequest;
use Rhino\Policies\ResourcePolicy;
use Rhino\Tests\TestCase;
use Rhino\Traits\HasValidation;
use Rhino\Traits\HidableColumns;

/**
 * Precedence between a request class, the legacy $validationRulesStore/Update
 * config and the policy-driven path — per action, independently (test matrix
 * rows 13, 14, 15 and 17).
 *
 * The one behavior change for an existing model is asserted here too: a model
 * that keeps its legacy rules AND gains a request class also starts getting
 * the policy's forbidden-field 403, which the legacy path skips.
 */

// --------------------------------------------------------------------------
// Models
// --------------------------------------------------------------------------

/** Legacy rules config AND a store request class. */
class RcPrecLegacy extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'rc_prec_posts';
    protected $fillable = ['title', 'status', 'notes'];

    protected $validationRules = [
        'title' => 'string|max:255',
        'status' => 'string|in:draft,published',
    ];

    protected $validationRulesStore = ['title', 'status'];
    protected $validationRulesUpdate = ['title'];
}

/** The same legacy config with NO request class — the 4.9.0 control. */
class RcPrecPureLegacy extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'rc_prec_posts';
    protected $fillable = ['title', 'status', 'notes'];

    protected $validationRules = [
        'title' => 'string|max:255',
        'status' => 'string|in:draft,published',
    ];

    protected $validationRulesStore = ['title', 'status'];
    protected $validationRulesUpdate = ['title'];
}

/** Only an UpdateRequest exists: store stays on the policy-driven path. */
class RcPrecUpdateOnly extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'rc_prec_posts';
    protected $fillable = ['title', 'status', 'notes'];

    protected $validationRules = [
        'title' => 'string|max:255',
        'status' => 'string|in:draft,published',
    ];
}

/** Registered through rhino.requests.map, for the update action only. */
class RcPrecMapped extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'rc_prec_posts';
    protected $fillable = ['title', 'status', 'notes'];

    protected $validationRules = [
        'title' => 'string|max:255',
        'status' => 'string|in:draft,published',
    ];
}

// --------------------------------------------------------------------------
// Request classes
// --------------------------------------------------------------------------

class RcPrecLegacyStoreRequest extends ResourceRequest
{
    public static bool $ran = false;

    public function rules(): array
    {
        static::$ran = true;

        return [
            'title' => 'required|string|max:50',
            // 'archived' would fail the model's own in:draft,published rule.
            'status' => 'required|string',
        ];
    }
}

class RcPrecUpdateOnlyUpdateRequest extends ResourceRequest
{
    public function rules(): array
    {
        return ['status' => 'sometimes|string', 'title' => 'sometimes|string'];
    }
}

class RcPrecMappedEditRequest extends ResourceRequest
{
    public function rules(): array
    {
        return ['title' => 'required|string|starts_with:mapped'];
    }
}

// --------------------------------------------------------------------------
// Policy
// --------------------------------------------------------------------------

class RcPrecPolicy extends ResourcePolicy
{
    public function viewAny(?Authenticatable $user): bool { return true; }
    public function view(?Authenticatable $user, $model): bool { return true; }
    public function create(?Authenticatable $user): bool { return true; }
    public function update(?Authenticatable $user, $model): bool { return true; }
    public function delete(?Authenticatable $user, $model): bool { return true; }

    /** 'notes' is denied on purpose. */
    public function permittedAttributesForCreate(?Authenticatable $user): array
    {
        return ['title', 'status'];
    }

    public function permittedAttributesForUpdate(?Authenticatable $user): array
    {
        return ['title', 'status'];
    }
}

// --------------------------------------------------------------------------
// Tests
// --------------------------------------------------------------------------

class RequestClassPrecedenceTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('rc_prec_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('status')->nullable();
            $table->text('notes')->nullable();
            $table->timestamps();
        });

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        RcPrecLegacyStoreRequest::$ran = false;
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
            'rhino.multi_tenant' => ['organization_identifier_column' => 'id'],
            'rhino.nested' => ['path' => 'nested', 'max_operations' => 50, 'allowed_models' => null],
            'rhino.requests.namespace' => __NAMESPACE__,
        ]);

        foreach ($models as $modelClass) {
            Gate::policy($modelClass, RcPrecPolicy::class);
        }

        Route::prefix('api')->group(function () {
            require __DIR__ . '/../../routes/api.php';
        });
    }

    protected function authenticate(): \App\Models\User
    {
        $user = \App\Models\User::firstOrCreate(
            ['email' => 'prec@example.com'],
            ['name' => 'Test User', 'password' => bcrypt('password')]
        );
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    // ------------------------------------------------------------------
    // Row 14 — the request class wins over legacy model rules
    // ------------------------------------------------------------------

    public function test_the_request_class_wins_over_the_legacy_rules_config(): void
    {
        $this->registerRoutes(['rcpreclegacies' => RcPrecLegacy::class]);
        $this->authenticate();

        // 'archived' fails the model's in:draft,published rule and passes the
        // request class's. A 201 proves which one ran.
        $this->postJson('/api/rcpreclegacies', ['title' => 'ok', 'status' => 'archived'])
            ->assertStatus(201);

        $this->assertTrue(RcPrecLegacyStoreRequest::$ran);
        $this->assertSame('archived', RcPrecLegacy::first()->status);

        // ...and the request class's own stricter rule is enforced.
        $this->postJson('/api/rcpreclegacies', [
            'title' => str_repeat('x', 51),
            'status' => 'archived',
        ])->assertStatus(422)->assertExactJson([
            'errors' => ['title' => ['The title field must not be greater than 50 characters.']],
        ]);
    }

    /**
     * The one behavior change for a model that keeps its legacy rules: because
     * the legacy branch is skipped when a request class exists, the policy's
     * forbidden-field gate now runs for that action. Contrast with the pure
     * legacy model below, which is untouched.
     */
    public function test_a_legacy_model_with_a_request_class_also_gets_the_forbidden_field_check(): void
    {
        $this->registerRoutes(['rcpreclegacies' => RcPrecLegacy::class]);
        $this->authenticate();

        $response = $this->postJson('/api/rcpreclegacies', [
            'title' => 'ok',
            'status' => 'draft',
            'notes' => 'denied by the policy',
        ]);

        $response->assertStatus(403);
        $response->assertExactJson([
            'message' => 'You are not allowed to set the following field(s): notes',
        ]);
        $this->assertFalse(RcPrecLegacyStoreRequest::$ran);
        $this->assertSame(0, RcPrecLegacy::count());
    }

    public function test_a_legacy_model_without_a_request_class_is_byte_identical_to_4_9(): void
    {
        $this->registerRoutes(['rcprecpurelegacies' => RcPrecPureLegacy::class]);
        $this->authenticate();

        // Same payload as the test above. On the legacy path there is no
        // forbidden-field check: the denied field is silently ignored, not 403.
        $this->postJson('/api/rcprecpurelegacies', [
            'title' => 'ok',
            'status' => 'draft',
            'notes' => 'denied by the policy',
        ])->assertStatus(201);

        $record = RcPrecPureLegacy::first();
        $this->assertSame('draft', $record->status);
        $this->assertNull($record->notes);

        // And the model's own rules still decide: 'archived' is rejected.
        $this->postJson('/api/rcprecpurelegacies', ['title' => 'ok', 'status' => 'archived'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['status']]);
    }

    // ------------------------------------------------------------------
    // Row 15 — resolution is per action
    // ------------------------------------------------------------------

    public function test_a_store_only_request_class_leaves_update_on_the_legacy_path(): void
    {
        $this->registerRoutes(['rcpreclegacies' => RcPrecLegacy::class]);
        $this->authenticate();

        $long = str_repeat('x', 100);

        // Store is the request class (max:50) ...
        $this->postJson('/api/rcpreclegacies', ['title' => $long, 'status' => 'draft'])
            ->assertStatus(422);

        $post = RcPrecLegacy::create(['title' => 'Before', 'status' => 'draft']);

        // ... update is the legacy validateUpdate, whose rule allows max:255.
        $this->putJson("/api/rcpreclegacies/{$post->id}", ['title' => $long])
            ->assertStatus(200);
        $this->assertSame($long, $post->fresh()->title);
    }

    public function test_an_update_only_request_class_leaves_store_on_the_policy_path(): void
    {
        $this->registerRoutes(['rcprecupdateonlies' => RcPrecUpdateOnly::class]);
        $this->authenticate();

        // Store: the model's own rules apply, so 'archived' is rejected.
        $this->postJson('/api/rcprecupdateonlies', ['title' => 'ok', 'status' => 'archived'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['status']]);

        $post = RcPrecUpdateOnly::create(['title' => 'ok', 'status' => 'draft']);

        // Update: the request class owns it, and it allows any status string.
        $this->putJson("/api/rcprecupdateonlies/{$post->id}", ['status' => 'archived'])
            ->assertStatus(200);
        $this->assertSame('archived', $post->fresh()->status);
    }

    // ------------------------------------------------------------------
    // Row 17 — the explicit map, per action
    // ------------------------------------------------------------------

    public function test_the_explicit_map_can_register_a_single_action(): void
    {
        $this->registerRoutes(['rcprecmappeds' => RcPrecMapped::class]);
        config(['rhino.requests.map.rcprecmappeds.update' => RcPrecMappedEditRequest::class]);
        $this->authenticate();

        // Store is untouched by an update-only registration.
        $this->postJson('/api/rcprecmappeds', ['title' => 'anything', 'status' => 'draft'])
            ->assertStatus(201);

        $post = RcPrecMapped::first();

        $this->putJson("/api/rcprecmappeds/{$post->id}", ['title' => 'nope'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['title']]);

        $this->putJson("/api/rcprecmappeds/{$post->id}", ['title' => 'mapped title'])
            ->assertStatus(200);
        $this->assertSame('mapped title', $post->fresh()->title);
    }

    public function test_a_typo_in_the_explicit_map_for_update_is_a_hard_error(): void
    {
        $this->registerRoutes(['rcprecmappeds' => RcPrecMapped::class]);
        config(['rhino.requests.map.rcprecmappeds.update' => 'App\\Http\\Requests\\Nope']);
        $this->authenticate();

        $post = RcPrecMapped::create(['title' => 'ok']);

        $this->withoutExceptionHandling();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Rhino: request class [App\\Http\\Requests\\Nope] configured for [rcprecmappeds.update] does not exist.'
        );

        $this->putJson("/api/rcprecmappeds/{$post->id}", ['title' => 'x']);
    }

    /**
     * An empty string in the map is not a registration — the convention (and
     * then the legacy path) still applies, rather than throwing.
     */
    public function test_an_empty_map_entry_falls_through_to_the_convention(): void
    {
        $this->registerRoutes(['rcprecmappeds' => RcPrecMapped::class]);
        config(['rhino.requests.map.rcprecmappeds.store' => '']);
        $this->authenticate();

        $this->postJson('/api/rcprecmappeds', ['title' => 'ok', 'status' => 'archived'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['status']]);
    }
}
