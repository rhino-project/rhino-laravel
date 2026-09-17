<?php

namespace Rhino\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Contracts\Debug\ExceptionHandler;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Rhino\Http\Requests\ResourceRequest;
use Rhino\Policies\ResourcePolicy;
use Rhino\Tests\TestCase;
use Rhino\Traits\HasValidation;
use Rhino\Traits\HidableColumns;
use Symfony\Component\HttpKernel\Exception\AccessDeniedHttpException;

// --------------------------------------------------------------------------
// Models
// --------------------------------------------------------------------------

class RcPost extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'rc_posts';
    protected $fillable = ['title', 'content', 'status'];

    // Deliberately present: a request class must win over these, and the
    // 'in:draft,published' rule below must never fire when it does.
    protected $validationRules = [
        'title' => 'string|max:255',
        'content' => 'string',
        'status' => 'string|in:draft,published',
    ];
}

/** A model whose only validation is legacy field allowlisting. */
class RcLegacyPost extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'rc_posts';
    protected $fillable = ['title', 'content', 'status'];

    protected $validationRules = ['title' => 'string|max:255'];
    protected $validationRulesStore = ['title'];
    protected $validationRulesUpdate = ['title'];
}

/** A model with no request class at all — the 4.9.0 path must be untouched. */
class RcPlainPost extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'rc_posts';
    protected $fillable = ['title', 'content', 'status'];

    protected $validationRules = [
        'title' => 'string|max:255',
        'status' => 'string|in:draft,published',
    ];
}

/**
 * A model whose conventional request-class name is already taken in the app by
 * something that is not a FormRequest — the 4.9.0-upgrade collision case.
 */
class RcCollide extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'rc_posts';
    protected $fillable = ['title', 'content', 'status'];

    protected $validationRules = [
        'title' => 'string|max:255',
        'status' => 'string|in:draft,published',
    ];
}

// --------------------------------------------------------------------------
// Request classes (discovered by convention: {Model}StoreRequest / UpdateRequest)
// --------------------------------------------------------------------------

class RcPostStoreRequest extends ResourceRequest
{
    public static bool $prepareRan = false;
    public static bool $denyAuthorize = false;
    public static ?string $authorizeThrows = null;

    public function authorize(): bool
    {
        if (static::$authorizeThrows !== null) {
            throw new \Illuminate\Auth\Access\AuthorizationException(static::$authorizeThrows);
        }

        return ! static::$denyAuthorize;
    }

    public function prepare(array $input): array
    {
        static::$prepareRan = true;

        $input['title'] = trim($input['title'] ?? '');
        // Server-authored, and covered by a rule below, so it is persisted.
        $input['status'] = 'archived';
        // Server-authored but NOT covered by a rule — dropped, not persisted.
        $input['content'] = 'never persisted';

        return $input;
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string|max:10',
            // 'archived' is not in the model's own in:draft,published rule —
            // asserting the model rules did not run.
            'status' => 'required|string',
        ];
    }

    public function messages(): array
    {
        return ['title.required' => 'Every post needs a title.'];
    }
}

class RcPostUpdateRequest extends ResourceRequest
{
    public static array $seen = [];

    public function rules(): array
    {
        static::$seen = [
            'action' => $this->action(),
            'record_title' => $this->record()?->title,
            'route_group' => $this->routeGroup(),
            'organization' => $this->organization(),
        ];

        return [
            // Record-dependent: a published post may not go back to draft.
            'status' => $this->record()?->status === 'published'
                ? 'sometimes|in:published,archived'
                : 'sometimes|string',
            'title' => 'sometimes|string|max:255',
        ];
    }
}

/** Only a store class — update must fall back to the 4.9.0 path. */
class RcLegacyPostStoreRequest extends ResourceRequest
{
    public function rules(): array
    {
        return ['title' => 'required|string|max:5'];
    }
}

/** A plain FormRequest: accepted, but with no Rhino helpers. */
class RcPlainFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return ['title' => 'required|string|max:8'];
    }
}

/** Configured explicitly through rhino.requests.map. */
class RcMappedStoreRequest extends ResourceRequest
{
    public function rules(): array
    {
        return ['title' => 'required|string|starts_with:mapped'];
    }
}

/** Exists, but is not a FormRequest. Named in rhino.requests.map. */
class RcNotAFormRequest
{
}

/**
 * Matches the convention name for RcCollide but is not a FormRequest — e.g. an
 * app's own pre-existing request object. Must be ignored, never fatal.
 */
class RcCollideStoreRequest
{
}

// --------------------------------------------------------------------------
// Policies
// --------------------------------------------------------------------------

class RcPostPolicy extends ResourcePolicy
{
    public static bool $denyCreate = false;

    public function viewAny(?Authenticatable $user): bool { return true; }
    public function view(?Authenticatable $user, $model): bool { return true; }
    public function create(?Authenticatable $user): bool { return ! static::$denyCreate; }
    public function update(?Authenticatable $user, $model): bool { return true; }
    public function delete(?Authenticatable $user, $model): bool { return true; }

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

class RequestClassTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('rc_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content')->nullable();
            $table->string('status')->default('draft');
            $table->timestamps();
        });

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        RcPostStoreRequest::$prepareRan = false;
        RcPostStoreRequest::$denyAuthorize = false;
        RcPostStoreRequest::$authorizeThrows = null;
        RcPostUpdateRequest::$seen = [];
        RcPostPolicy::$denyCreate = false;
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
            // Convention discovery scans this namespace.
            'rhino.requests.namespace' => __NAMESPACE__,
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
    // Discovery + happy path
    // ------------------------------------------------------------------

    public function test_convention_discovered_store_request_validates_and_filters_the_payload(): void
    {
        Gate::policy(RcPost::class, RcPostPolicy::class);
        $this->registerRoutes(['rcposts' => RcPost::class]);
        $this->authenticate();

        $response = $this->postJson('/api/rcposts', [
            'title' => '  Hello  ',
            'status' => 'draft',
        ]);

        $response->assertStatus(201);
        $this->assertTrue(RcPostStoreRequest::$prepareRan);

        $post = RcPost::first();
        // prepare() normalized the title and set a server-authored status that
        // a rule covers.
        $this->assertSame('Hello', $post->title);
        $this->assertSame('archived', $post->status);
        // prepare() also set 'content', which no rule covers → dropped.
        $this->assertNull($post->content);
    }

    public function test_request_class_wins_over_the_models_own_validation_rules(): void
    {
        Gate::policy(RcPost::class, RcPostPolicy::class);
        $this->registerRoutes(['rcposts' => RcPost::class]);
        $this->authenticate();

        // The model's own rule is status in:draft,published; prepare() sets
        // 'archived'. A 201 proves the model rules never ran.
        $this->postJson('/api/rcposts', ['title' => 'ok'])->assertStatus(201);
        $this->assertSame('archived', RcPost::first()->status);
    }

    // ------------------------------------------------------------------
    // Envelopes
    // ------------------------------------------------------------------

    public function test_rules_failure_returns_422_with_the_errors_envelope(): void
    {
        Gate::policy(RcPost::class, RcPostPolicy::class);
        $this->registerRoutes(['rcposts' => RcPost::class]);
        $this->authenticate();

        $response = $this->postJson('/api/rcposts', ['title' => 'far too long to pass']);

        $response->assertStatus(422);
        $response->assertExactJson([
            'errors' => [
                'title' => ['The title field must not be greater than 10 characters.'],
            ],
        ]);
    }

    public function test_messages_override_is_honoured(): void
    {
        Gate::policy(RcPost::class, RcPostPolicy::class);
        $this->registerRoutes(['rcposts' => RcPost::class]);
        $this->authenticate();

        $this->postJson('/api/rcposts', ['title' => ''])
            ->assertStatus(422)
            ->assertJsonPath('errors.title.0', 'Every post needs a title.');
    }

    public function test_authorize_false_is_byte_identical_to_a_policy_denial(): void
    {
        Gate::policy(RcPost::class, RcPostPolicy::class);
        $this->registerRoutes(['rcposts' => RcPost::class]);
        $this->authenticate();

        RcPostStoreRequest::$denyAuthorize = true;
        $fromRequestClass = $this->postJson('/api/rcposts', ['title' => 'ok']);

        RcPostStoreRequest::$denyAuthorize = false;
        RcPostPolicy::$denyCreate = true;
        $fromPolicy = $this->postJson('/api/rcposts', ['title' => 'ok']);

        $fromRequestClass->assertStatus(403);
        $fromRequestClass->assertExactJson(['message' => 'This action is unauthorized.']);
        $this->assertSame($fromPolicy->getStatusCode(), $fromRequestClass->getStatusCode());
        // Byte-identical, not merely equivalent: both go through the app's
        // exception handler, so neither is rendered specially by Rhino.
        $this->assertSame($fromPolicy->getContent(), $fromRequestClass->getContent());
        $this->assertSame(0, RcPost::count());
    }

    /**
     * H-6 under an app that renders authorization failures its own way — which
     * the TaskFlow example app does ({"message":"Unauthorized"}). If Rhino
     * rendered the request-class denial itself, the two bodies would diverge
     * here and a client could enumerate which gate refused it.
     */
    public function test_authorize_false_matches_a_policy_denial_under_a_custom_renderable(): void
    {
        Gate::policy(RcPost::class, RcPostPolicy::class);
        $this->registerRoutes(['rcposts' => RcPost::class]);
        $this->authenticate();

        // The shape the TaskFlow example app uses: Laravel's handler maps an
        // AuthorizationException to AccessDeniedHttpException before renderables
        // run, so that is what an app hooks.
        $this->app[ExceptionHandler::class]->renderable(function (AccessDeniedHttpException $e) {
            return response()->json(['message' => 'Custom denial'], 403);
        });

        RcPostStoreRequest::$denyAuthorize = true;
        $fromRequestClass = $this->postJson('/api/rcposts', ['title' => 'ok']);

        RcPostStoreRequest::$denyAuthorize = false;
        RcPostPolicy::$denyCreate = true;
        $fromPolicy = $this->postJson('/api/rcposts', ['title' => 'ok']);

        $fromRequestClass->assertStatus(403);
        $fromRequestClass->assertExactJson(['message' => 'Custom denial']);
        $this->assertSame($fromPolicy->getStatusCode(), $fromRequestClass->getStatusCode());
        $this->assertSame($fromPolicy->getContent(), $fromRequestClass->getContent());
        $this->assertSame(0, RcPost::count());
    }

    /**
     * The developer's own message must never reach the client, whatever the
     * app's handler does with the exception.
     */
    public function test_a_custom_authorization_message_is_not_leaked(): void
    {
        Gate::policy(RcPost::class, RcPostPolicy::class);
        $this->registerRoutes(['rcposts' => RcPost::class]);
        $this->authenticate();

        $this->app[ExceptionHandler::class]->renderable(function (AccessDeniedHttpException $e) {
            return response()->json(['message' => $e->getMessage()], 403);
        });

        RcPostStoreRequest::$authorizeThrows = 'user 42 is not the project owner';

        $response = $this->postJson('/api/rcposts', ['title' => 'ok']);

        $response->assertStatus(403);
        $response->assertExactJson(['message' => 'This action is unauthorized.']);
        $this->assertStringNotContainsString('user 42', $response->getContent());
    }

    public function test_forbidden_field_403_still_runs_before_the_request_class(): void
    {
        Gate::policy(RcPost::class, RcPostPolicy::class);
        $this->registerRoutes(['rcposts' => RcPost::class]);
        $this->authenticate();

        // 'content' is not in permittedAttributesForCreate.
        $response = $this->postJson('/api/rcposts', ['title' => 'ok', 'content' => 'x']);

        $response->assertStatus(403);
        $response->assertJsonPath('message', 'You are not allowed to set the following field(s): content');
        // prepare() must not have run — it cannot launder a denied field.
        $this->assertFalse(RcPostStoreRequest::$prepareRan);
    }

    // ------------------------------------------------------------------
    // Update: context and record-dependent rules
    // ------------------------------------------------------------------

    public function test_update_request_receives_the_pre_update_record(): void
    {
        Gate::policy(RcPost::class, RcPostPolicy::class);
        $this->registerRoutes(['rcposts' => RcPost::class]);
        $this->authenticate();

        $post = RcPost::create(['title' => 'Before', 'status' => 'published']);

        // A published post may not go back to draft.
        $this->putJson("/api/rcposts/{$post->id}", ['status' => 'draft'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['status']]);

        $this->assertSame('update', RcPostUpdateRequest::$seen['action']);
        $this->assertSame('Before', RcPostUpdateRequest::$seen['record_title']);
        $this->assertSame('default', RcPostUpdateRequest::$seen['route_group']);
        $this->assertNull(RcPostUpdateRequest::$seen['organization']);

        $this->putJson("/api/rcposts/{$post->id}", ['status' => 'archived'])
            ->assertStatus(200);
    }

    // ------------------------------------------------------------------
    // Fallbacks
    // ------------------------------------------------------------------

    public function test_store_request_only_leaves_update_on_the_legacy_path(): void
    {
        Gate::policy(RcLegacyPost::class, RcPostPolicy::class);
        $this->registerRoutes(['rclegacyposts' => RcLegacyPost::class]);
        $this->authenticate();

        // Store goes through RcLegacyPostStoreRequest (max:5).
        $this->postJson('/api/rclegacyposts', ['title' => 'way too long'])->assertStatus(422);
        $this->postJson('/api/rclegacyposts', ['title' => 'ok'])->assertStatus(201);

        // Update has no request class → legacy validateUpdate, which allows
        // a title up to 255 characters.
        $post = RcLegacyPost::first();
        $this->putJson("/api/rclegacyposts/{$post->id}", ['title' => 'way too long'])
            ->assertStatus(200);
    }

    public function test_model_without_a_request_class_is_untouched(): void
    {
        Gate::policy(RcPlainPost::class, RcPostPolicy::class);
        $this->registerRoutes(['rcplainposts' => RcPlainPost::class]);
        $this->authenticate();

        $this->postJson('/api/rcplainposts', ['title' => 'ok', 'status' => 'nope'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['status']]);

        $this->postJson('/api/rcplainposts', ['title' => 'ok', 'status' => 'draft'])
            ->assertStatus(201);
    }

    // ------------------------------------------------------------------
    // Plain FormRequest
    // ------------------------------------------------------------------

    public function test_a_plain_form_request_subclass_is_accepted(): void
    {
        Gate::policy(RcPlainPost::class, RcPostPolicy::class);
        $this->registerRoutes(['rcplainposts' => RcPlainPost::class]);
        config(['rhino.requests.map.rcplainposts.store' => RcPlainFormRequest::class]);
        $this->authenticate();

        $this->postJson('/api/rcplainposts', ['title' => 'far too long'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['title']]);

        $this->postJson('/api/rcplainposts', ['title' => 'ok'])->assertStatus(201);
    }

    // ------------------------------------------------------------------
    // Explicit map
    // ------------------------------------------------------------------

    public function test_explicit_map_overrides_the_convention(): void
    {
        Gate::policy(RcPost::class, RcPostPolicy::class);
        $this->registerRoutes(['rcposts' => RcPost::class]);
        config(['rhino.requests.map.rcposts.store' => RcMappedStoreRequest::class]);
        $this->authenticate();

        $this->postJson('/api/rcposts', ['title' => 'nope'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['title']]);

        $this->postJson('/api/rcposts', ['title' => 'mapped!'])->assertStatus(201);
        // The convention class's prepare() never ran.
        $this->assertFalse(RcPostStoreRequest::$prepareRan);
    }

    public function test_explicit_map_pointing_at_a_missing_class_is_a_hard_error(): void
    {
        Gate::policy(RcPost::class, RcPostPolicy::class);
        $this->registerRoutes(['rcposts' => RcPost::class]);
        config(['rhino.requests.map.rcposts.store' => 'App\\Http\\Requests\\Typo']);
        $this->authenticate();

        $this->withoutExceptionHandling();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Rhino: request class [App\\Http\\Requests\\Typo] configured for [rcposts.store] does not exist.'
        );

        $this->postJson('/api/rcposts', ['title' => 'ok']);
    }

    public function test_explicit_map_pointing_at_a_non_form_request_is_a_hard_error(): void
    {
        Gate::policy(RcPost::class, RcPostPolicy::class);
        $this->registerRoutes(['rcposts' => RcPost::class]);
        config(['rhino.requests.map.rcposts.store' => RcNotAFormRequest::class]);
        $this->authenticate();

        $this->withoutExceptionHandling();
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage(
            'Rhino: request class [' . RcNotAFormRequest::class . '] configured for [rcposts.store] is not a FormRequest.'
        );

        $this->postJson('/api/rcposts', ['title' => 'ok']);
    }

    /**
     * An app upgrading from 4.9.0 may already own a class matching the
     * convention name. Rhino logs and falls through — it must never turn a
     * working app's POST into a 500.
     */
    public function test_convention_name_collision_warns_and_falls_through_to_the_legacy_path(): void
    {
        Log::spy();

        Gate::policy(RcCollide::class, RcPostPolicy::class);
        $this->registerRoutes(['rccollides' => RcCollide::class]);
        $this->authenticate();

        // The model's own rules ran: 'nope' is not in:draft,published.
        $this->postJson('/api/rccollides', ['title' => 'ok', 'status' => 'nope'])
            ->assertStatus(422)
            ->assertJsonStructure(['errors' => ['status']]);

        // ...and the legacy path still succeeds for valid input.
        $this->postJson('/api/rccollides', ['title' => 'ok', 'status' => 'draft'])
            ->assertStatus(201);

        Log::shouldHaveReceived('warning')->withArgs(
            fn ($message) => $message === 'Rhino: ignoring ' . RcCollideStoreRequest::class
                . ' for [rccollides.store]: it is not a FormRequest'
        );
    }
}
