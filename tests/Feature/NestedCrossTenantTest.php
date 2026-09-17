<?php

namespace Rhino\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Rhino\Http\Requests\ResourceRequest;
use Rhino\Policies\ResourcePolicy;
use Rhino\Tests\TestCase;
use Rhino\Traits\BelongsToOrganization;
use Rhino\Traits\HasValidation;
use Rhino\Traits\HidableColumns;

/**
 * Cross-tenant writes through POST /{organization}/nested.
 *
 * The update operation's target row must be resolved through the SAME
 * organization-scoped query the member endpoints use, for every tenancy shape:
 * a column (`organization_id`), one relationship hop (note → project → org) and
 * two hops (comment → note → project → org). The same-org controls below are
 * what make the 404s meaningful — without them a broken scope that matched
 * nothing would look like a pass.
 *
 * Both validation paths are covered, because they load the record separately:
 * the legacy/policy path loads it only in authorizeNestedOperation(), while the
 * request-class path also loads one to hand to rules() as record().
 */

// --------------------------------------------------------------------------
// Models — one table per tenancy shape, two models per table so the legacy and
// the request-class paths can be exercised over identical data.
// --------------------------------------------------------------------------

/** The org-owning root of the chain. */
class NctProject extends Model
{
    use HasValidation, HidableColumns, BelongsToOrganization;

    protected $table = 'nct_projects';
    protected $fillable = ['organization_id', 'name'];
}

/** DIRECT tenancy, legacy rules config. */
class NctDirectLegacy extends Model
{
    use HasValidation, HidableColumns, BelongsToOrganization;

    protected $table = 'nct_direct_notes';
    protected $fillable = ['organization_id', 'body'];

    protected $validationRules = ['body' => 'string|max:255'];
    protected $validationRulesStore = ['body'];
    protected $validationRulesUpdate = ['body'];
}

/** DIRECT tenancy, request class. */
class NctDirectRc extends Model
{
    use HasValidation, HidableColumns, BelongsToOrganization;

    protected $table = 'nct_direct_notes';
    protected $fillable = ['organization_id', 'body'];

    // Store has no request class, so it runs the policy-driven path — which
    // needs a rule for 'body' or nothing would be persisted at all.
    protected $validationRules = ['body' => 'string|max:255'];
}

/** ONE HOP: reaches its organization through NctProject. Legacy rules config. */
class NctHopLegacy extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'nct_hop_notes';
    protected $fillable = ['project_id', 'body'];

    protected $validationRules = ['body' => 'string|max:255'];
    protected $validationRulesStore = ['body'];
    protected $validationRulesUpdate = ['body'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(NctProject::class, 'project_id');
    }
}

/** ONE HOP, request class. */
class NctHopRc extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'nct_hop_notes';
    protected $fillable = ['project_id', 'body'];

    public function project(): BelongsTo
    {
        return $this->belongsTo(NctProject::class, 'project_id');
    }
}

/** TWO HOPS: comment → note → project → organization. Legacy rules config. */
class NctDeepLegacy extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'nct_deep_comments';
    protected $fillable = ['note_id', 'body'];

    protected $validationRules = ['body' => 'string|max:255'];
    protected $validationRulesStore = ['body'];
    protected $validationRulesUpdate = ['body'];

    public function note(): BelongsTo
    {
        return $this->belongsTo(NctHopLegacy::class, 'note_id');
    }
}

/** TWO HOPS, request class. */
class NctDeepRc extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'nct_deep_comments';
    protected $fillable = ['note_id', 'body'];

    public function note(): BelongsTo
    {
        return $this->belongsTo(NctHopLegacy::class, 'note_id');
    }
}

// --------------------------------------------------------------------------
// Request classes — each records whether it was handed a record, so a leak on
// the validation side is visible even if the authorization step still 404s.
// --------------------------------------------------------------------------

trait NctRecordsItsRecord
{
    public static array $seenRecordIds = [];

    public function rules(): array
    {
        static::$seenRecordIds[] = $this->record()?->getKey();

        return ['body' => 'sometimes|string|max:255'];
    }
}

class NctDirectRcUpdateRequest extends ResourceRequest
{
    use NctRecordsItsRecord;
}

class NctHopRcUpdateRequest extends ResourceRequest
{
    use NctRecordsItsRecord;
}

class NctDeepRcUpdateRequest extends ResourceRequest
{
    use NctRecordsItsRecord;
}

// --------------------------------------------------------------------------
// Policy — deliberately permissive, so nothing but the organization scope can
// be what stops a cross-tenant write.
// --------------------------------------------------------------------------

class NctPolicy extends ResourcePolicy
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

// --------------------------------------------------------------------------
// Tests
// --------------------------------------------------------------------------

class NestedCrossTenantTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        Schema::create('nct_projects', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('nct_direct_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('body');
            $table->timestamps();
        });

        Schema::create('nct_hop_notes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('project_id')->constrained('nct_projects')->cascadeOnDelete();
            $table->string('body');
            $table->timestamps();
        });

        Schema::create('nct_deep_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('note_id')->constrained('nct_hop_notes')->cascadeOnDelete();
            $table->string('body');
            $table->timestamps();
        });

        // Harness trap: the auto-detected organization relationship paths are
        // cached statically on the controller and survive between tests.
        $this->flushOrganizationPathCache();

        NctDirectRcUpdateRequest::$seenRecordIds = [];
        NctHopRcUpdateRequest::$seenRecordIds = [];
        NctDeepRcUpdateRequest::$seenRecordIds = [];
    }

    protected function tearDown(): void
    {
        $this->flushOrganizationPathCache();

        parent::tearDown();
    }

    protected function flushOrganizationPathCache(): void
    {
        $property = new \ReflectionProperty(\Rhino\Controllers\GlobalController::class, 'organizationPathCache');
        $property->setAccessible(true);
        $property->setValue(null, []);
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
            'rhino.multi_tenant' => ['organization_identifier_column' => 'slug'],
            'rhino.nested' => ['path' => 'nested', 'max_operations' => 50, 'allowed_models' => null],
            'rhino.requests.namespace' => __NAMESPACE__,
        ]);

        foreach ($models as $modelClass) {
            Gate::policy($modelClass, NctPolicy::class);
        }
        Gate::policy(\App\Models\Organization::class, NctPolicy::class);

        Route::prefix('api')->group(function () {
            require __DIR__ . '/../../routes/api.php';
        });
    }

    /** The acting user must hold a UserRole in the org or the resolver 404s. */
    protected function actAsMemberOf(string $orgSlug): \App\Models\Organization
    {
        $user = \App\Models\User::firstOrCreate(
            ['email' => "nct-{$orgSlug}@example.com"],
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

        return $org;
    }

    protected function organizations(): array
    {
        $orgA = $this->actAsMemberOf('nct-org-a');
        $orgB = $this->actAsMemberOf('nct-org-b');

        return [$orgA, $orgB];
    }

    protected function nestedUpdate(string $orgSlug, string $slug, $id, array $data)
    {
        return $this->postJson("/api/{$orgSlug}/nested", [
            'operations' => [
                ['model' => $slug, 'action' => 'update', 'id' => $id, 'data' => $data],
            ],
        ]);
    }

    // ------------------------------------------------------------------
    // Direct tenancy (organization_id column)
    // ------------------------------------------------------------------

    public function test_a_directly_owned_row_cannot_be_updated_from_another_org_on_the_legacy_path(): void
    {
        $this->registerTenantRoutes(['nctdirectlegacies' => NctDirectLegacy::class]);
        [$orgA, $orgB] = $this->organizations();

        $noteA = NctDirectLegacy::withoutGlobalScopes()->forceCreate(['organization_id' => $orgA->id, 'body' => 'A original']);
        $noteB = NctDirectLegacy::withoutGlobalScopes()->forceCreate(['organization_id' => $orgB->id, 'body' => 'B original']);

        // Acting as org B, against org A's row.
        $this->assertTrue(NctDirectLegacy::withoutGlobalScopes()->whereKey($noteA->id)->exists());

        $this->actAsMemberOf('nct-org-b');
        $response = $this->nestedUpdate('nct-org-b', 'nctdirectlegacies', $noteA->id, ['body' => 'hijacked']);

        $response->assertStatus(404);
        $response->assertExactJson(['message' => 'Resource not found.']);
        $this->assertSame('A original', $noteA->fresh()->body);

        // Control: org B's OWN row updates fine through the same call, so the
        // 404 above is the organization scope and not a broken route.
        $this->nestedUpdate('nct-org-b', 'nctdirectlegacies', $noteB->id, ['body' => 'B updated'])
            ->assertStatus(200);
        $this->assertSame('B updated', $noteB->fresh()->body);
    }

    public function test_a_directly_owned_row_cannot_be_updated_from_another_org_on_the_request_class_path(): void
    {
        $this->registerTenantRoutes(['nctdirectrcs' => NctDirectRc::class]);
        [$orgA, $orgB] = $this->organizations();

        $noteA = NctDirectRc::withoutGlobalScopes()->forceCreate(['organization_id' => $orgA->id, 'body' => 'A original']);
        $noteB = NctDirectRc::withoutGlobalScopes()->forceCreate(['organization_id' => $orgB->id, 'body' => 'B original']);

        // Non-vacuity: the id IS a real, findable row — the only thing that may
        // stop org B reaching it is the organization scope.
        $this->assertTrue(NctDirectRc::withoutGlobalScopes()->whereKey($noteA->id)->exists());

        $this->actAsMemberOf('nct-org-b');
        $response = $this->nestedUpdate('nct-org-b', 'nctdirectrcs', $noteA->id, ['body' => 'hijacked']);

        $response->assertStatus(404);
        $response->assertExactJson(['message' => 'Resource not found.']);
        $this->assertSame('A original', $noteA->fresh()->body);
        // The request class must not have been handed the other org's row either.
        $this->assertSame([null], NctDirectRcUpdateRequest::$seenRecordIds);

        $this->nestedUpdate('nct-org-b', 'nctdirectrcs', $noteB->id, ['body' => 'B updated'])
            ->assertStatus(200);
        $this->assertSame('B updated', $noteB->fresh()->body);
        $this->assertSame([null, $noteB->id], NctDirectRcUpdateRequest::$seenRecordIds);
    }

    // ------------------------------------------------------------------
    // One relationship hop (note → project → organization)
    // ------------------------------------------------------------------

    public function test_an_indirectly_owned_row_cannot_be_updated_from_another_org_on_the_legacy_path(): void
    {
        $this->registerTenantRoutes(['ncthoplegacies' => NctHopLegacy::class]);
        [$orgA, $orgB] = $this->organizations();

        $projectA = NctProject::withoutGlobalScopes()->forceCreate(['organization_id' => $orgA->id, 'name' => 'A']);
        $projectB = NctProject::withoutGlobalScopes()->forceCreate(['organization_id' => $orgB->id, 'name' => 'B']);
        $noteA = NctHopLegacy::forceCreate(['project_id' => $projectA->id, 'body' => 'A original']);
        $noteB = NctHopLegacy::forceCreate(['project_id' => $projectB->id, 'body' => 'B original']);

        $this->assertTrue(NctHopLegacy::whereKey($noteA->id)->exists());

        $this->actAsMemberOf('nct-org-b');
        $response = $this->nestedUpdate('nct-org-b', 'ncthoplegacies', $noteA->id, ['body' => 'hijacked']);

        $response->assertStatus(404);
        $response->assertExactJson(['message' => 'Resource not found.']);
        // nct_hop_notes has no organization_id of its own: only the walked
        // relationship keeps org A's row out of reach.
        $this->assertSame('A original', $noteA->fresh()->body);

        $this->nestedUpdate('nct-org-b', 'ncthoplegacies', $noteB->id, ['body' => 'B updated'])
            ->assertStatus(200);
        $this->assertSame('B updated', $noteB->fresh()->body);
    }

    public function test_an_indirectly_owned_row_cannot_be_updated_from_another_org_on_the_request_class_path(): void
    {
        $this->registerTenantRoutes(['ncthoprcs' => NctHopRc::class]);
        [$orgA, $orgB] = $this->organizations();

        $projectA = NctProject::withoutGlobalScopes()->forceCreate(['organization_id' => $orgA->id, 'name' => 'A']);
        $projectB = NctProject::withoutGlobalScopes()->forceCreate(['organization_id' => $orgB->id, 'name' => 'B']);
        $noteA = NctHopRc::forceCreate(['project_id' => $projectA->id, 'body' => 'A original']);
        $noteB = NctHopRc::forceCreate(['project_id' => $projectB->id, 'body' => 'B original']);

        // Non-vacuity: nct_hop_notes has no global scope at all, so an
        // unscoped lookup finds org A's row — the 404 below is the walked
        // relationship doing its job, not a missing row.
        $this->assertTrue(NctHopRc::whereKey($noteA->id)->exists());

        $this->actAsMemberOf('nct-org-b');
        $response = $this->nestedUpdate('nct-org-b', 'ncthoprcs', $noteA->id, ['body' => 'hijacked']);

        $response->assertStatus(404);
        $response->assertExactJson(['message' => 'Resource not found.']);
        $this->assertSame('A original', $noteA->fresh()->body);
        $this->assertSame([null], NctHopRcUpdateRequest::$seenRecordIds);

        $this->nestedUpdate('nct-org-b', 'ncthoprcs', $noteB->id, ['body' => 'B updated'])
            ->assertStatus(200);
        $this->assertSame('B updated', $noteB->fresh()->body);
        $this->assertSame([null, $noteB->id], NctHopRcUpdateRequest::$seenRecordIds);
    }

    // ------------------------------------------------------------------
    // Two relationship hops (comment → note → project → organization)
    // ------------------------------------------------------------------

    public function test_a_two_hop_row_cannot_be_updated_from_another_org_on_the_legacy_path(): void
    {
        $this->registerTenantRoutes(['nctdeeplegacies' => NctDeepLegacy::class]);
        [$orgA, $orgB] = $this->organizations();

        [$commentA, $commentB] = $this->seedTwoHopRows($orgA, $orgB, NctDeepLegacy::class);

        $this->assertTrue(NctDeepLegacy::whereKey($commentA->id)->exists());

        $this->actAsMemberOf('nct-org-b');
        $response = $this->nestedUpdate('nct-org-b', 'nctdeeplegacies', $commentA->id, ['body' => 'hijacked']);

        $response->assertStatus(404);
        $response->assertExactJson(['message' => 'Resource not found.']);
        $this->assertSame('A original', $commentA->fresh()->body);

        $this->nestedUpdate('nct-org-b', 'nctdeeplegacies', $commentB->id, ['body' => 'B updated'])
            ->assertStatus(200);
        $this->assertSame('B updated', $commentB->fresh()->body);
    }

    public function test_a_two_hop_row_cannot_be_updated_from_another_org_on_the_request_class_path(): void
    {
        $this->registerTenantRoutes(['nctdeeprcs' => NctDeepRc::class]);
        [$orgA, $orgB] = $this->organizations();

        [$commentA, $commentB] = $this->seedTwoHopRows($orgA, $orgB, NctDeepRc::class);

        $this->assertTrue(NctDeepRc::whereKey($commentA->id)->exists());

        $this->actAsMemberOf('nct-org-b');
        $response = $this->nestedUpdate('nct-org-b', 'nctdeeprcs', $commentA->id, ['body' => 'hijacked']);

        $response->assertStatus(404);
        $response->assertExactJson(['message' => 'Resource not found.']);
        $this->assertSame('A original', $commentA->fresh()->body);
        $this->assertSame([null], NctDeepRcUpdateRequest::$seenRecordIds);

        $this->nestedUpdate('nct-org-b', 'nctdeeprcs', $commentB->id, ['body' => 'B updated'])
            ->assertStatus(200);
        $this->assertSame('B updated', $commentB->fresh()->body);
        $this->assertSame([null, $commentB->id], NctDeepRcUpdateRequest::$seenRecordIds);
    }

    /**
     * @return array{0: Model, 1: Model}
     */
    protected function seedTwoHopRows($orgA, $orgB, string $commentClass): array
    {
        $projectA = NctProject::withoutGlobalScopes()->forceCreate(['organization_id' => $orgA->id, 'name' => 'A']);
        $projectB = NctProject::withoutGlobalScopes()->forceCreate(['organization_id' => $orgB->id, 'name' => 'B']);
        $noteA = NctHopLegacy::forceCreate(['project_id' => $projectA->id, 'body' => 'A note']);
        $noteB = NctHopLegacy::forceCreate(['project_id' => $projectB->id, 'body' => 'B note']);

        return [
            $commentClass::forceCreate(['note_id' => $noteA->id, 'body' => 'A original']),
            $commentClass::forceCreate(['note_id' => $noteB->id, 'body' => 'B original']),
        ];
    }

    // ------------------------------------------------------------------
    // A create operation cannot plant a row in another organization either
    // ------------------------------------------------------------------

    public function test_a_nested_create_is_written_into_the_requesting_organization(): void
    {
        $this->registerTenantRoutes(['nctdirectrcs' => NctDirectRc::class]);
        [$orgA, $orgB] = $this->organizations();

        $this->actAsMemberOf('nct-org-b');
        $this->postJson('/api/nct-org-b/nested', [
            'operations' => [
                // The client names org A explicitly; the framework must overwrite it.
                ['model' => 'nctdirectrcs', 'action' => 'create', 'data' => [
                    'body' => 'created by B',
                    'organization_id' => $orgA->id,
                ]],
            ],
        ])->assertStatus(200);

        $row = NctDirectRc::withoutGlobalScopes()->where('body', 'created by B')->first();
        $this->assertNotNull($row);
        $this->assertSame($orgB->id, $row->organization_id);
    }
}
