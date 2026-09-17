<?php

namespace Rhino\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Foundation\Http\FormRequest;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Rhino\Http\Requests\ResourceRequest;
use Rhino\Policies\ResourcePolicy;
use Rhino\Support\TenantExistsRules;
use Rhino\Tests\TestCase;
use Rhino\Traits\BelongsToOrganization;
use Rhino\Traits\HasValidation;
use Rhino\Traits\HidableColumns;

/**
 * Multi-tenancy on the request-class path (test matrix rows 10, 11 and 20).
 *
 * RequestClassTenantTest covers the INDIRECT FK chain (article → blog → org).
 * This file covers the DIRECT case (the referenced table carries
 * organization_id), the documented plain-FormRequest limitation, a
 * non-tenant route group, and the end-to-end isolation of rows created
 * through a request class.
 */

// --------------------------------------------------------------------------
// Models
// --------------------------------------------------------------------------

/** Directly owned: the reference target for the exists: rules below. */
class RcTnProject extends Model
{
    use HasValidation, HidableColumns, BelongsToOrganization;

    protected $table = 'rc_tn_projects';
    protected $fillable = ['organization_id', 'name'];
}

class RcTnTask extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'rc_tn_tasks';
    protected $fillable = ['project_id', 'title'];
}

/** Same table, second slug: served by a PLAIN FormRequest through the map. */
class RcTnPlainTask extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'rc_tn_tasks';
    protected $fillable = ['project_id', 'title'];
}

/** Directly owned, used for the cross-tenant isolation assertions. */
class RcTnNote extends Model
{
    use HasValidation, HidableColumns, BelongsToOrganization;

    protected $table = 'rc_tn_notes';
    protected $fillable = ['organization_id', 'body'];
}

// --------------------------------------------------------------------------
// Request classes
// --------------------------------------------------------------------------

class RcTnTaskStoreRequest extends ResourceRequest
{
    public static $seenOrganization = false;

    public function rules(): array
    {
        static::$seenOrganization = $this->organization();

        return [
            'title' => 'required|string',
            // rc_tn_projects carries organization_id, so Rhino appends the
            // direct ",organization_id,{id}" scope to this rule.
            'project_id' => 'required|integer|exists:rc_tn_projects,id',
        ];
    }
}

/**
 * Row 16's documented limitation: a plain FormRequest is accepted, but it gets
 * none of the Rhino helpers and — crucially — its `exists:` rules are NOT
 * rewritten to the current organization.
 */
class RcTnPlainTaskFormRequest extends FormRequest
{
    public function authorize(): bool
    {
        return true;
    }

    public function rules(): array
    {
        return [
            'title' => 'required|string',
            'project_id' => 'required|integer|exists:rc_tn_projects,id',
        ];
    }
}

class RcTnNoteStoreRequest extends ResourceRequest
{
    /** Set to another organization's id to prove the framework value wins. */
    public static $smuggledOrganizationId = null;

    public function prepare(array $input): array
    {
        if (static::$smuggledOrganizationId !== null) {
            $input['organization_id'] = static::$smuggledOrganizationId;
        }

        return $input;
    }

    public function rules(): array
    {
        return [
            'body' => 'required|string',
            // In tenant context Rhino removes this rule entirely, because
            // organization_id is framework-managed. If it survived, every
            // request below would 422 on "required".
            'organization_id' => 'required|integer',
        ];
    }
}

class RcTnNoteUpdateRequest extends ResourceRequest
{
    public function rules(): array
    {
        return ['body' => 'sometimes|string'];
    }
}

// --------------------------------------------------------------------------
// Policy
// --------------------------------------------------------------------------

class RcTnPolicy extends ResourcePolicy
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

class RequestClassTenantScopingTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        Schema::create('rc_tn_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('rc_tn_tasks', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->nullable()->constrained('rc_tn_projects')->cascadeOnDelete();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('rc_tn_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->cascadeOnDelete();
            $table->string('body');
            $table->timestamps();
        });

        // The schema lookups are cached statically and describe a database that
        // is rebuilt for every test — flush them.
        TenantExistsRules::flushCaches();
        RcTnTaskStoreRequest::$seenOrganization = false;
        RcTnNoteStoreRequest::$smuggledOrganizationId = null;
    }

    protected function tearDown(): void
    {
        TenantExistsRules::flushCaches();

        parent::tearDown();
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
     * A tenant group at {organization}/… plus, optionally, a second group that
     * declares itself non-tenant.
     */
    protected function registerTenantRoutes(array $models, bool $withInternalGroup = false): void
    {
        $groups = [
            'tenant' => [
                'prefix' => '{organization}',
                'middleware' => [\Rhino\Http\Middleware\ResolveOrganizationFromRoute::class],
                'models' => '*',
            ],
        ];

        if ($withInternalGroup) {
            $groups['internal'] = [
                'prefix' => 'internal',
                'middleware' => [],
                'models' => '*',
                'tenant' => false,
            ];
        }

        config([
            'rhino.models' => $models,
            'rhino.route_groups' => $groups,
            'rhino.multi_tenant' => ['organization_identifier_column' => 'slug'],
            'rhino.nested' => ['path' => 'nested', 'max_operations' => 50, 'allowed_models' => null],
            'rhino.requests.namespace' => __NAMESPACE__,
        ]);

        Gate::policy(RcTnProject::class, RcTnPolicy::class);
        Gate::policy(RcTnTask::class, RcTnPolicy::class);
        Gate::policy(RcTnPlainTask::class, RcTnPolicy::class);
        Gate::policy(RcTnNote::class, RcTnPolicy::class);
        Gate::policy(\App\Models\Organization::class, RcTnPolicy::class);

        Route::prefix('api')->group(function () {
            require __DIR__ . '/../../routes/api.php';
        });
    }

    /**
     * Create an organization with a member, and act as that member.
     * The acting user MUST hold a UserRole in the org or the resolver 404s.
     */
    protected function actAsMemberOf(string $orgSlug): array
    {
        $user = \App\Models\User::firstOrCreate(
            ['email' => "rc-tn-{$orgSlug}@example.com"],
            ['name' => 'Test User', 'password' => bcrypt('password')]
        );

        $org = \App\Models\Organization::firstOrCreate(
            ['slug' => $orgSlug],
            ['name' => ucfirst($orgSlug), 'domain' => null]
        );

        $role = \App\Models\Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);

        \App\Models\UserRole::firstOrCreate([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'organization_id' => $org->id,
        ], ['permissions' => ['*']]);

        $this->actingAs($user, 'sanctum');

        return [$user, $org];
    }

    // ------------------------------------------------------------------
    // Row 11 — direct ownership
    // ------------------------------------------------------------------

    public function test_a_direct_exists_rule_in_a_request_class_is_scoped_to_the_organization(): void
    {
        $this->registerTenantRoutes(['rctntasks' => RcTnTask::class]);

        [, $orgA] = $this->actAsMemberOf('rc-tn-a');
        $orgB = \App\Models\Organization::forceCreate(['name' => 'Org B', 'slug' => 'rc-tn-b', 'domain' => null]);

        // Both organizations own a project: org B's row must be unreachable,
        // not merely absent.
        $projectA = RcTnProject::forceCreate(['organization_id' => $orgA->id, 'name' => 'A']);
        $projectB = RcTnProject::forceCreate(['organization_id' => $orgB->id, 'name' => 'B']);

        $response = $this->postJson('/api/rc-tn-a/rctntasks', [
            'title' => 'Cross tenant',
            'project_id' => $projectB->id,
        ]);
        $response->assertStatus(422);
        $response->assertExactJson([
            'errors' => ['project_id' => ['The selected project id is invalid.']],
        ]);

        $this->postJson('/api/rc-tn-a/rctntasks', [
            'title' => 'Fine',
            'project_id' => $projectA->id,
        ])->assertStatus(201);

        $this->assertSame(1, RcTnTask::count());
        $this->assertSame($projectA->id, RcTnTask::first()->project_id);
        $this->assertSame($orgA->id, RcTnTaskStoreRequest::$seenOrganization->id);
    }

    // ------------------------------------------------------------------
    // Row 16 — the plain-FormRequest limitation, pinned so it cannot regress
    // silently in either direction
    // ------------------------------------------------------------------

    public function test_a_plain_form_request_does_not_get_its_exists_rules_org_scoped(): void
    {
        $this->registerTenantRoutes(['rctnplaintasks' => RcTnPlainTask::class]);
        config(['rhino.requests.map.rctnplaintasks.store' => RcTnPlainTaskFormRequest::class]);

        [, $orgA] = $this->actAsMemberOf('rc-tn-a');
        $orgB = \App\Models\Organization::forceCreate(['name' => 'Org B', 'slug' => 'rc-tn-b', 'domain' => null]);

        $projectB = RcTnProject::forceCreate(['organization_id' => $orgB->id, 'name' => 'B']);

        // DOCUMENTED LIMITATION: a class that does not extend ResourceRequest
        // gets no context and no exists: rewriting, so this reference to
        // another organization's project is accepted. An app that needs the
        // scoping must extend ResourceRequest (or add the scope by hand).
        $this->postJson('/api/rc-tn-a/rctnplaintasks', [
            'title' => 'Unscoped',
            'project_id' => $projectB->id,
        ])->assertStatus(201);

        $this->assertSame($projectB->id, RcTnPlainTask::first()->project_id);

        // The same payload against the ResourceRequest-backed slug is rejected —
        // the difference is the base class, nothing else.
        $this->registerTenantRoutes(['rctntasks' => RcTnTask::class]);
        $this->postJson('/api/rc-tn-a/rctntasks', [
            'title' => 'Unscoped',
            'project_id' => $projectB->id,
        ])->assertStatus(422);
    }

    // ------------------------------------------------------------------
    // Row 11 — organization_id is framework-managed
    // ------------------------------------------------------------------

    public function test_the_organization_id_rule_is_dropped_and_the_framework_value_wins(): void
    {
        $this->registerTenantRoutes(['rctnnotes' => RcTnNote::class]);

        [, $orgA] = $this->actAsMemberOf('rc-tn-a');
        $orgB = \App\Models\Organization::forceCreate(['name' => 'Org B', 'slug' => 'rc-tn-b', 'domain' => null]);

        // prepare() is trusted server code, but it still cannot cross tenants:
        // the rule for organization_id is dropped in tenant context (so the key
        // never survives into validated()), and the framework applies the
        // request's own organization afterwards.
        RcTnNoteStoreRequest::$smuggledOrganizationId = $orgB->id;

        $this->postJson('/api/rc-tn-a/rctnnotes', ['body' => 'Hello'])
            ->assertStatus(201);

        $note = RcTnNote::withoutGlobalScopes()->first();
        $this->assertSame($orgA->id, $note->organization_id);
    }

    // ------------------------------------------------------------------
    // Row 10 — a non-tenant route group
    // ------------------------------------------------------------------

    public function test_organization_is_null_in_a_group_declared_non_tenant(): void
    {
        $this->registerTenantRoutes(['rctntasks' => RcTnTask::class], withInternalGroup: true);

        [, $orgA] = $this->actAsMemberOf('rc-tn-a');
        $orgB = \App\Models\Organization::forceCreate(['name' => 'Org B', 'slug' => 'rc-tn-b', 'domain' => null]);
        $projectB = RcTnProject::forceCreate(['organization_id' => $orgB->id, 'name' => 'B']);

        // Served by the 'internal' group: no organization is resolved, so
        // organization() is null and no exists: scoping is applied — any
        // project is reachable, which is what a non-tenant group means.
        $this->postJson('/api/internal/rctntasks', [
            'title' => 'Internal',
            'project_id' => $projectB->id,
        ])->assertStatus(201);

        $this->assertNull(RcTnTaskStoreRequest::$seenOrganization);
    }

    // ------------------------------------------------------------------
    // Row 20 — end-to-end isolation of rows created through a request class
    // ------------------------------------------------------------------

    public function test_rows_created_through_a_request_class_are_isolated_per_organization(): void
    {
        $this->registerTenantRoutes(['rctnnotes' => RcTnNote::class]);

        // Org A writes two notes...
        [, $orgA] = $this->actAsMemberOf('rc-tn-a');
        $this->postJson('/api/rc-tn-a/rctnnotes', ['body' => 'A one'])->assertStatus(201);
        $this->postJson('/api/rc-tn-a/rctnnotes', ['body' => 'A two'])->assertStatus(201);
        $noteA = RcTnNote::withoutGlobalScopes()->where('body', 'A one')->first();

        // ...and org B writes ONE, so a leak in either direction changes a
        // count rather than hiding behind an empty table.
        [, $orgB] = $this->actAsMemberOf('rc-tn-b');
        $this->postJson('/api/rc-tn-b/rctnnotes', ['body' => 'B one'])->assertStatus(201);

        $this->assertSame(3, RcTnNote::withoutGlobalScopes()->count());
        $this->assertSame($orgB->id, RcTnNote::withoutGlobalScopes()->where('body', 'B one')->first()->organization_id);

        // Collection path: each org sees only its own rows.
        $this->getJson('/api/rc-tn-b/rctnnotes')->assertStatus(200)->assertJsonCount(1, 'data');

        // Per-row path: org B cannot read or update org A's note.
        $this->getJson("/api/rc-tn-b/rctnnotes/{$noteA->id}")->assertStatus(404);
        $this->putJson("/api/rc-tn-b/rctnnotes/{$noteA->id}", ['body' => 'hijacked'])
            ->assertStatus(404);

        // ...and a cross-org URL is a 404 too (org A's member on org B's path).
        $this->actAsMemberOf('rc-tn-a');
        $this->getJson('/api/rc-tn-a/rctnnotes')->assertStatus(200)->assertJsonCount(2, 'data');
        $this->getJson("/api/rc-tn-b/rctnnotes/{$noteA->id}")->assertStatus(404);

        $this->assertSame('A one', $noteA->fresh()->body);
    }
}
