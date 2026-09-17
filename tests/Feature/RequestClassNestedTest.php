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
 * Request classes inside POST /nested (test matrix row 12).
 *
 * The nested endpoint validates every operation before authorizing any of
 * them, so these tests also pin the ordering hazard H-7: loading `record` for
 * an update operation must not turn a would-be 404 into a 422.
 */

// --------------------------------------------------------------------------
// Models
// --------------------------------------------------------------------------

class RcNestProject extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'rc_nest_projects';
    protected $fillable = ['name'];
}

class RcNestTask extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'rc_nest_tasks';
    protected $fillable = ['project_id', 'title', 'status'];
}

// --------------------------------------------------------------------------
// Request classes
// --------------------------------------------------------------------------

class RcNestProjectStoreRequest extends ResourceRequest
{
    public function rules(): array
    {
        return ['name' => 'required|string|max:10'];
    }
}

class RcNestTaskStoreRequest extends ResourceRequest
{
    public static bool $deny = false;
    public static array $seenKeys = [];
    public static array $seenContext = [];

    public function authorize(): bool
    {
        return ! static::$deny;
    }

    public function rules(): array
    {
        static::$seenKeys = array_keys($this->all());
        static::$seenContext = [
            'action' => $this->action(),
            'record' => $this->record(),
            'route_group' => $this->routeGroup(),
            // Regression guard: the nested sub-request must carry a STRING (or
            // null) body. An array body makes Request::createFrom() fatal on
            // Laravel 13 — "trim(): Argument #1 must be of type string" — while
            // older versions tolerate it, so assert the type, not the symptom.
            'content_type' => gettype($this->getContent()),
        ];

        return [
            'title' => 'required|string|max:20',
            // Required — and satisfied by a "$0.id" reference that the request
            // class never sees. Rhino excludes reference fields from the rules
            // exactly as it does on the legacy path.
            'project_id' => 'required|integer',
        ];
    }
}

class RcNestTaskUpdateRequest extends ResourceRequest
{
    public static array $seen = [];

    public function rules(): array
    {
        static::$seen = [
            'action' => $this->action(),
            'record_title' => $this->record()?->title,
            'record_is_null' => $this->record() === null,
        ];

        return ['title' => 'sometimes|string|max:20'];
    }
}

// --------------------------------------------------------------------------
// Policies
// --------------------------------------------------------------------------

class RcNestPolicy extends ResourcePolicy
{
    public function viewAny(?Authenticatable $user): bool { return true; }
    public function view(?Authenticatable $user, $model): bool { return true; }
    public function create(?Authenticatable $user): bool { return true; }
    public function update(?Authenticatable $user, $model): bool { return true; }
    public function delete(?Authenticatable $user, $model): bool { return true; }

    public function permittedAttributesForCreate(?Authenticatable $user): array
    {
        // 'status' is deliberately absent: the forbidden-field gate must fire
        // before the request class ever runs.
        return ['name', 'title', 'project_id'];
    }

    public function permittedAttributesForUpdate(?Authenticatable $user): array
    {
        return ['name', 'title', 'project_id'];
    }
}

// --------------------------------------------------------------------------
// Tests
// --------------------------------------------------------------------------

class RequestClassNestedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('rc_nest_projects', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('rc_nest_tasks', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('project_id')->nullable();
            $table->string('title');
            $table->string('status')->nullable();
            $table->timestamps();
        });

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        RcNestTaskStoreRequest::$deny = false;
        RcNestTaskStoreRequest::$seenKeys = [];
        RcNestTaskStoreRequest::$seenContext = [];
        RcNestTaskUpdateRequest::$seen = [];
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

    protected function registerRoutes(): void
    {
        config([
            'rhino.models' => [
                'rcnestprojects' => RcNestProject::class,
                'rcnesttasks' => RcNestTask::class,
            ],
            'rhino.route_groups' => [
                'default' => ['prefix' => '', 'middleware' => [], 'models' => '*'],
            ],
            'rhino.multi_tenant' => ['organization_identifier_column' => 'id'],
            'rhino.nested' => ['path' => 'nested', 'max_operations' => 50, 'allowed_models' => null],
            'rhino.requests.namespace' => __NAMESPACE__,
        ]);

        Gate::policy(RcNestProject::class, RcNestPolicy::class);
        Gate::policy(RcNestTask::class, RcNestPolicy::class);

        Route::prefix('api')->group(function () {
            require __DIR__ . '/../../routes/api.php';
        });
    }

    protected function authenticate(): \App\Models\User
    {
        $user = \App\Models\User::firstOrCreate(
            ['email' => 'nested@example.com'],
            ['name' => 'Test User', 'password' => bcrypt('password')]
        );
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    // ------------------------------------------------------------------

    public function test_each_operation_is_validated_by_its_own_request_class_and_references_are_preserved(): void
    {
        $this->registerRoutes();
        $this->authenticate();

        $response = $this->postJson('/api/nested', [
            'operations' => [
                ['model' => 'rcnestprojects', 'action' => 'create', 'data' => ['name' => 'Alpha']],
                ['model' => 'rcnesttasks', 'action' => 'create', 'data' => [
                    'title' => 'First task',
                    'project_id' => '$0.id',
                ]],
            ],
        ]);

        $response->assertStatus(200);

        $project = RcNestProject::first();
        $task = RcNestTask::first();
        $this->assertNotNull($project);
        $this->assertNotNull($task);
        // The reference was stripped before validation and merged back into the
        // write payload afterwards.
        $this->assertSame($project->id, $task->project_id);

        // The request class never saw the "$0.id" placeholder...
        $this->assertSame(['title'], RcNestTaskStoreRequest::$seenKeys);
        // ...and it ran as a store with no record.
        $this->assertSame('store', RcNestTaskStoreRequest::$seenContext['action']);
        $this->assertNull(RcNestTaskStoreRequest::$seenContext['record']);
        $this->assertSame('string', RcNestTaskStoreRequest::$seenContext['content_type']);
    }

    public function test_a_nested_update_operation_receives_the_pre_update_record(): void
    {
        $this->registerRoutes();
        $this->authenticate();

        $task = RcNestTask::create(['title' => 'Before', 'project_id' => null]);

        $response = $this->postJson('/api/nested', [
            'operations' => [
                ['model' => 'rcnesttasks', 'action' => 'update', 'id' => $task->id, 'data' => [
                    'title' => 'After',
                ]],
            ],
        ]);

        $response->assertStatus(200);
        $this->assertSame('update', RcNestTaskUpdateRequest::$seen['action']);
        $this->assertSame('Before', RcNestTaskUpdateRequest::$seen['record_title']);
        $this->assertSame('After', $task->fresh()->title);
    }

    public function test_a_nested_request_class_failure_uses_the_nested_envelope_and_writes_nothing(): void
    {
        $this->registerRoutes();
        $this->authenticate();

        $response = $this->postJson('/api/nested', [
            'operations' => [
                ['model' => 'rcnestprojects', 'action' => 'create', 'data' => ['name' => 'Alpha']],
                ['model' => 'rcnesttasks', 'action' => 'create', 'data' => [
                    'title' => 'A title that is far too long for the rule',
                    'project_id' => '$0.id',
                ]],
            ],
        ]);

        $response->assertStatus(422);
        $response->assertExactJson([
            'message' => 'Validation failed.',
            'errors' => [
                'operations.1.data.title' => ['The title field must not be greater than 20 characters.'],
            ],
        ]);

        // Nothing at all was written — not even the valid first operation.
        $this->assertSame(0, RcNestProject::count());
        $this->assertSame(0, RcNestTask::count());
    }

    public function test_a_nested_request_class_denial_returns_the_shared_403(): void
    {
        $this->registerRoutes();
        $this->authenticate();

        RcNestTaskStoreRequest::$deny = true;

        $response = $this->postJson('/api/nested', [
            'operations' => [
                ['model' => 'rcnesttasks', 'action' => 'create', 'data' => ['title' => 'Nope']],
            ],
        ]);

        $response->assertStatus(403);
        $response->assertExactJson(['message' => 'This action is unauthorized.']);
        $this->assertSame(0, RcNestTask::count());
    }

    public function test_the_forbidden_field_403_still_precedes_the_nested_request_class(): void
    {
        $this->registerRoutes();
        $this->authenticate();

        $response = $this->postJson('/api/nested', [
            'operations' => [
                ['model' => 'rcnesttasks', 'action' => 'create', 'data' => [
                    'title' => 'ok',
                    'status' => 'done',
                ]],
            ],
        ]);

        $response->assertStatus(403);
        $response->assertExactJson([
            'message' => 'You are not allowed to set the following field(s): status',
        ]);
        // The request class never ran, so it could not have laundered 'status'.
        $this->assertSame([], RcNestTaskStoreRequest::$seenKeys);
        $this->assertSame(0, RcNestTask::count());
    }

    /**
     * H-7: the request class's `record` lookup is deliberately non-failing, so
     * a nested update for a row that does not exist still produces today's 404
     * from the authorization step rather than a validation error.
     */
    public function test_a_nested_update_for_a_missing_row_still_404s_and_the_record_is_null(): void
    {
        $this->registerRoutes();
        $this->authenticate();

        $response = $this->postJson('/api/nested', [
            'operations' => [
                ['model' => 'rcnesttasks', 'action' => 'update', 'id' => 9999, 'data' => ['title' => 'x']],
            ],
        ]);

        $response->assertStatus(404);
        $response->assertExactJson(['message' => 'Resource not found.']);
        // The rules still ran, with a null record.
        $this->assertTrue(RcNestTaskUpdateRequest::$seen['record_is_null']);
    }

    /**
     * Documents what a request class sees for `routeGroup()` inside /nested:
     * the Laravel nested route is registered once, outside the per-group loop,
     * and carries no `route_group` default — so the group is null there even
     * though it is populated on the per-model routes.
     */
    public function test_nested_operations_report_a_null_route_group(): void
    {
        $this->registerRoutes();
        $this->authenticate();

        $this->postJson('/api/nested', [
            'operations' => [
                ['model' => 'rcnesttasks', 'action' => 'create', 'data' => ['title' => 'ok', 'project_id' => 1]],
            ],
        ])->assertStatus(200);

        $this->assertNull(RcNestTaskStoreRequest::$seenContext['route_group']);
    }
}
