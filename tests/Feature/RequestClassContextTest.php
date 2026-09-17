<?php

namespace Rhino\Tests\Feature;

use Illuminate\Auth\Access\AuthorizationException;
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
 * The context a request class is handed: the matched route group, the
 * authenticated user, the action, and (here, outside tenant context) a null
 * organization — plus the write-payload rule that only rule-covered keys are
 * persisted.
 *
 * Test matrix rows 2, 8 (non-nested half), 9, 10 (non-tenant half) and the
 * §4.6 "rules() returns []" edge.
 */

// --------------------------------------------------------------------------
// Models
// --------------------------------------------------------------------------

class RcCtxDoc extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'rc_ctx_docs';
    protected $fillable = ['title', 'status', 'notes', 'priority'];
}

/** Its request class declares no rules at all — everything must be dropped. */
class RcCtxEmpty extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'rc_ctx_docs';
    protected $fillable = ['title', 'status', 'notes', 'priority'];
}

/** Its request class throws a chatty AuthorizationException. */
class RcCtxSecret extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'rc_ctx_docs';
    protected $fillable = ['title', 'status', 'notes', 'priority'];
}

/** Its request class uses the validator hooks (attributes/after). */
class RcCtxHooked extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'rc_ctx_docs';
    protected $fillable = ['title', 'status', 'notes', 'priority'];
}

// --------------------------------------------------------------------------
// Request classes
// --------------------------------------------------------------------------

class RcCtxDocStoreRequest extends ResourceRequest
{
    /** Every context the rules saw, one entry per invocation. */
    public static array $seen = [];

    public function rules(): array
    {
        static::$seen[] = [
            'route_group' => $this->routeGroup(),
            'organization' => $this->organization(),
            'user' => $this->user()?->email,
            'action' => $this->action(),
            'record' => $this->record(),
        ];

        $rules = ['title' => 'required|string'];

        // Route-group-dependent: the admin group demands a priority, the
        // default group does not. One class, two contracts.
        if ($this->routeGroup() === 'admin') {
            $rules['priority'] = 'required|integer';
        } else {
            $rules['priority'] = 'sometimes|integer';
        }

        // User-dependent: an admin may set any status, everyone else is held
        // to the two workflow values.
        $rules['status'] = $this->user()?->email === 'admin@example.com'
            ? 'sometimes|string'
            : 'sometimes|in:todo,doing';

        return $rules;
    }
}

class RcCtxDocUpdateRequest extends ResourceRequest
{
    public static array $seen = [];

    public function rules(): array
    {
        static::$seen[] = [
            'action' => $this->action(),
            'record_title' => $this->record()?->title,
            'route_group' => $this->routeGroup(),
        ];

        // Deliberately no rule for 'notes' even though the policy permits the
        // client to set it: a field with no rule is never written.
        return ['title' => 'sometimes|string|max:255'];
    }
}

/** §4.6: a request class whose rules() is empty drops the whole payload. */
class RcCtxEmptyStoreRequest extends ResourceRequest
{
    public function rules(): array
    {
        return [];
    }
}

/** H-6: the exception's own message must never reach the client. */
class RcCtxSecretStoreRequest extends ResourceRequest
{
    public function authorize(): bool
    {
        throw new AuthorizationException('user 42 is not the owner of project 7');
    }

    public function rules(): array
    {
        return ['title' => 'required|string'];
    }
}

class RcCtxHookedStoreRequest extends ResourceRequest
{
    public function rules(): array
    {
        return ['title' => 'required|string', 'status' => 'required|string'];
    }

    public function attributes(): array
    {
        return ['title' => 'headline'];
    }

    public function withValidator($validator): void
    {
        $validator->after(function ($validator) {
            if ($validator->getData()['status'] ?? null) {
                $validator->errors()->add('status', 'Status is decided by the workflow, not the client.');
            }
        });
    }
}

// --------------------------------------------------------------------------
// Policy
// --------------------------------------------------------------------------

class RcCtxPolicy extends ResourcePolicy
{
    public function viewAny(?Authenticatable $user): bool { return true; }
    public function view(?Authenticatable $user, $model): bool { return true; }
    public function create(?Authenticatable $user): bool { return true; }
    public function update(?Authenticatable $user, $model): bool { return true; }
    public function delete(?Authenticatable $user, $model): bool { return true; }

    public function permittedAttributesForCreate(?Authenticatable $user): array
    {
        return ['title', 'status', 'notes', 'priority'];
    }

    public function permittedAttributesForUpdate(?Authenticatable $user): array
    {
        return ['title', 'status', 'notes', 'priority'];
    }
}

/** Same permissions, minus 'notes' on update. */
class RcCtxNoNotesOnUpdatePolicy extends RcCtxPolicy
{
    public function permittedAttributesForUpdate(?Authenticatable $user): array
    {
        return ['title', 'status', 'priority'];
    }
}

// --------------------------------------------------------------------------
// Tests
// --------------------------------------------------------------------------

class RequestClassContextTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('rc_ctx_docs', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->string('status')->nullable();
            $table->text('notes')->nullable();
            $table->integer('priority')->nullable();
            $table->timestamps();
        });

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        RcCtxDocStoreRequest::$seen = [];
        RcCtxDocUpdateRequest::$seen = [];
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

    /**
     * Two groups over the same models: the default group at the root and an
     * 'admin' group behind a literal prefix.
     */
    protected function registerRoutes(array $models, bool $withAdminGroup = false): void
    {
        $groups = [
            'default' => ['prefix' => '', 'middleware' => [], 'models' => '*'],
        ];

        if ($withAdminGroup) {
            $groups['admin'] = ['prefix' => 'admin', 'middleware' => [], 'models' => '*'];
        }

        config([
            'rhino.models' => $models,
            'rhino.route_groups' => $groups,
            'rhino.multi_tenant' => ['organization_identifier_column' => 'id'],
            'rhino.nested' => ['path' => 'nested', 'max_operations' => 50, 'allowed_models' => null],
            'rhino.requests.namespace' => __NAMESPACE__,
        ]);

        Route::prefix('api')->group(function () {
            require __DIR__ . '/../../routes/api.php';
        });
    }

    protected function authenticate(string $email = 'member@example.com'): \App\Models\User
    {
        // NOTE (harness trap): firstOrCreate(['id' => N]) does not honour the
        // id because it is not fillable — key on the email instead.
        $user = \App\Models\User::firstOrCreate(
            ['email' => $email],
            ['name' => 'Test User', 'password' => bcrypt('password')]
        );
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    // ------------------------------------------------------------------
    // Row 8 — route-group-dependent rules
    // ------------------------------------------------------------------

    public function test_rules_see_the_matched_route_group(): void
    {
        Gate::policy(RcCtxDoc::class, RcCtxPolicy::class);
        $this->registerRoutes(['rcctxdocs' => RcCtxDoc::class], withAdminGroup: true);
        $this->authenticate();

        // Default group: priority is optional.
        $this->postJson('/api/rcctxdocs', ['title' => 'From default'])->assertStatus(201);
        $this->assertSame('default', RcCtxDocStoreRequest::$seen[0]['route_group']);

        // Admin group, same model, same class: priority is required.
        $this->postJson('/api/admin/rcctxdocs', ['title' => 'From admin'])
            ->assertStatus(422)
            ->assertExactJson(['errors' => ['priority' => ['The priority field is required.']]]);
        $this->assertSame('admin', RcCtxDocStoreRequest::$seen[1]['route_group']);

        $this->postJson('/api/admin/rcctxdocs', ['title' => 'From admin', 'priority' => 3])
            ->assertStatus(201);

        $this->assertSame(2, RcCtxDoc::count());
    }

    // ------------------------------------------------------------------
    // Row 9 — user/role-dependent rules
    // ------------------------------------------------------------------

    public function test_rules_branch_on_the_authenticated_user(): void
    {
        Gate::policy(RcCtxDoc::class, RcCtxPolicy::class);
        $this->registerRoutes(['rcctxdocs' => RcCtxDoc::class]);

        $this->authenticate('member@example.com');
        $this->postJson('/api/rcctxdocs', ['title' => 'a', 'status' => 'archived'])
            ->assertStatus(422)
            ->assertJsonPath('errors.status.0', 'The selected status is invalid.');
        $this->assertSame('member@example.com', RcCtxDocStoreRequest::$seen[0]['user']);

        // Same class, same input, admin user: the rule set is a different one.
        $this->authenticate('admin@example.com');
        $this->postJson('/api/rcctxdocs', ['title' => 'a', 'status' => 'archived'])
            ->assertStatus(201);
        $this->assertSame('admin@example.com', RcCtxDocStoreRequest::$seen[1]['user']);
        $this->assertSame('archived', RcCtxDoc::first()->status);
    }

    // ------------------------------------------------------------------
    // Row 10 (non-tenant half) — organization is null outside tenant context
    // ------------------------------------------------------------------

    public function test_organization_is_null_outside_tenant_context(): void
    {
        Gate::policy(RcCtxDoc::class, RcCtxPolicy::class);
        $this->registerRoutes(['rcctxdocs' => RcCtxDoc::class]);
        $this->authenticate();

        $this->postJson('/api/rcctxdocs', ['title' => 'a'])->assertStatus(201);

        $this->assertNull(RcCtxDocStoreRequest::$seen[0]['organization']);
        $this->assertSame('store', RcCtxDocStoreRequest::$seen[0]['action']);
        // record() is meaningful only on update.
        $this->assertNull(RcCtxDocStoreRequest::$seen[0]['record']);
    }

    // ------------------------------------------------------------------
    // Row 2 — the write payload is validated(), not the input
    // ------------------------------------------------------------------

    public function test_update_does_not_write_a_field_the_request_class_has_no_rule_for(): void
    {
        Gate::policy(RcCtxDoc::class, RcCtxPolicy::class);
        $this->registerRoutes(['rcctxdocs' => RcCtxDoc::class]);
        $this->authenticate();

        $doc = RcCtxDoc::create(['title' => 'Before', 'notes' => 'untouched', 'status' => 'todo']);

        // 'notes' IS in permittedAttributesForUpdate — the policy would let it
        // through — but RcCtxDocUpdateRequest declares no rule for it, so it is
        // dropped from the write payload (Recommendation B, fails closed).
        $response = $this->putJson("/api/rcctxdocs/{$doc->id}", [
            'title' => 'After',
            'notes' => 'smuggled',
        ]);

        $response->assertStatus(200);
        $response->assertJsonPath('title', 'After');
        $response->assertJsonPath('notes', 'untouched');

        $doc->refresh();
        $this->assertSame('After', $doc->title);
        $this->assertSame('untouched', $doc->notes);

        $this->assertSame('update', RcCtxDocUpdateRequest::$seen[0]['action']);
        // record() is the PRE-update row.
        $this->assertSame('Before', RcCtxDocUpdateRequest::$seen[0]['record_title']);
    }

    /**
     * Row 6, update half: the forbidden-field gate runs on the raw client
     * input, before the request class exists at all, so neither prepare() nor
     * the rules can be used to launder a denied field on update either.
     */
    public function test_the_forbidden_field_403_precedes_the_request_class_on_update(): void
    {
        Gate::policy(RcCtxDoc::class, RcCtxNoNotesOnUpdatePolicy::class);
        $this->registerRoutes(['rcctxdocs' => RcCtxDoc::class]);
        $this->authenticate();

        $doc = RcCtxDoc::create(['title' => 'Before', 'notes' => 'untouched']);

        $response = $this->putJson("/api/rcctxdocs/{$doc->id}", [
            'title' => 'After',
            'notes' => 'denied',
        ]);

        $response->assertStatus(403);
        $response->assertExactJson([
            'message' => 'You are not allowed to set the following field(s): notes',
        ]);
        // The request class never ran.
        $this->assertSame([], RcCtxDocUpdateRequest::$seen);
        $this->assertSame('Before', $doc->fresh()->title);
    }

    // ------------------------------------------------------------------
    // §4.6 — an empty rule set drops everything
    // ------------------------------------------------------------------

    public function test_a_request_class_with_no_rules_persists_nothing(): void
    {
        Gate::policy(RcCtxEmpty::class, RcCtxPolicy::class);
        $this->registerRoutes(['rcctxempties' => RcCtxEmpty::class]);
        $this->authenticate();

        $this->postJson('/api/rcctxempties', ['title' => 'dropped', 'status' => 'todo'])
            ->assertStatus(201);

        $record = RcCtxEmpty::first();
        $this->assertNotNull($record);
        $this->assertNull($record->title);
        $this->assertNull($record->status);
    }

    // ------------------------------------------------------------------
    // H-6 — the authorize exception's message is never rendered
    // ------------------------------------------------------------------

    public function test_an_authorization_exception_message_is_not_leaked(): void
    {
        Gate::policy(RcCtxSecret::class, RcCtxPolicy::class);
        $this->registerRoutes(['rcctxsecrets' => RcCtxSecret::class]);
        $this->authenticate();

        $response = $this->postJson('/api/rcctxsecrets', ['title' => 'a']);

        $response->assertStatus(403);
        $response->assertExactJson(['message' => 'This action is unauthorized.']);
        $this->assertStringNotContainsString('project 7', $response->getContent());
        $this->assertSame(0, RcCtxSecret::count());
    }

    // ------------------------------------------------------------------
    // §4.2 step 5d — the stack's own validator hooks run
    // ------------------------------------------------------------------

    public function test_attributes_and_with_validator_hooks_are_honoured(): void
    {
        Gate::policy(RcCtxHooked::class, RcCtxPolicy::class);
        $this->registerRoutes(['rcctxhookeds' => RcCtxHooked::class]);
        $this->authenticate();

        $response = $this->postJson('/api/rcctxhookeds', ['status' => 'todo']);

        $response->assertStatus(422);
        // attributes() renamed the field in the message...
        $response->assertJsonPath('errors.title.0', 'The headline field is required.');
        // ...and the withValidator() after-hook added its own error.
        $response->assertJsonPath(
            'errors.status.0',
            'Status is decided by the workflow, not the client.'
        );
    }

    // ------------------------------------------------------------------
    // The request class never runs for an unauthenticated caller
    // ------------------------------------------------------------------

    public function test_an_unauthenticated_request_never_reaches_the_request_class(): void
    {
        Gate::policy(RcCtxDoc::class, RcCtxPolicy::class);
        $this->registerRoutes(['rcctxdocs' => RcCtxDoc::class]);

        $this->postJson('/api/rcctxdocs', ['title' => 'a'])->assertStatus(401);

        $this->assertSame([], RcCtxDocStoreRequest::$seen);
        $this->assertSame(0, RcCtxDoc::count());
    }
}
