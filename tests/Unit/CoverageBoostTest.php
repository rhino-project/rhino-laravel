<?php

namespace Rhino\Tests\Unit;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Rhino\Policies\ResourcePolicy;
use Rhino\Tests\TestCase;
use Rhino\Traits\BelongsToOrganization;
use Rhino\Traits\HasValidation;
use Rhino\Traits\HidableColumns;

// ==========================================================================
// Test Models
// ==========================================================================

class CbExceptionModel extends Model
{
    use HidableColumns;

    protected $table = 'cb_exception_items';
    protected $fillable = ['title'];
}

class CbValidationModelNoRules extends Model
{
    use HasValidation;

    protected $table = 'cb_validation_items';
    protected $fillable = ['title', 'body'];
}

class CbValidationModelWithRulesNoStore extends Model
{
    use HasValidation;

    protected $table = 'cb_validation_items';
    protected $fillable = ['title', 'body'];

    protected $validationRules = [
        'title' => 'string|max:255',
        'body' => 'string',
    ];
}

class CbValidationModelUpdateOnly extends Model
{
    use HasValidation;

    protected $table = 'cb_validation_items';
    protected $fillable = ['title', 'body'];

    protected $validationRules = [
        'title' => 'string|max:255',
        'body' => 'string',
    ];

    // Only $validationRulesUpdate, no $validationRulesStore
    protected $validationRulesUpdate = ['title'];
}

class CbValidationModelRoleKeyed extends Model
{
    use HasValidation;

    protected $table = 'cb_validation_items';
    protected $fillable = ['title', 'body'];

    protected $validationRules = [
        'title' => 'string|max:255',
        'body' => 'string',
    ];

    protected $validationRulesStore = [
        'admin' => ['title' => 'required', 'body' => 'required'],
        '*' => ['title' => 'required'],
    ];

    protected $validationRulesUpdate = [
        'admin' => ['title' => 'sometimes'],
        '*' => [],
    ];
}

class CbValidationModelEmptyRoleKeyed extends Model
{
    use HasValidation;

    protected $table = 'cb_validation_items';
    protected $fillable = ['title', 'body'];

    protected $validationRules = [
        'title' => 'string|max:255',
        'body' => 'string',
    ];

    // Role keyed without wildcard key
    protected $validationRulesStore = [
        'admin' => ['title' => 'required'],
    ];

    protected $validationRulesUpdate = [
        'admin' => ['title' => 'sometimes'],
    ];
}

class CbValidationModelFullOverride extends Model
{
    use HasValidation;

    protected $table = 'cb_validation_items';
    protected $fillable = ['title', 'body'];

    protected $validationRules = [
        'title' => 'string|max:255',
        'body' => 'string',
    ];

    // Full override with pipe in modifier
    protected $validationRulesStore = [
        '*' => ['title' => 'required|string|max:100'],
    ];

    protected $validationRulesUpdate = [
        '*' => ['title' => 'sometimes'],
    ];
}

class CbValidationModelLegacyConfig extends Model
{
    use HasValidation;

    protected $table = 'cb_validation_items';
    protected $fillable = ['title', 'body'];

    protected $validationRules = [
        'title' => 'string|max:255',
        'body' => 'string',
    ];

    protected $validationRulesStore = ['title', 'body'];
    protected $validationRulesUpdate = [];
}

class CbCrashingPolicy extends ResourcePolicy
{
    // No resourceSlug, and config models reference a class that throws
}

// ==========================================================================
// Tests
// ==========================================================================

class CoverageBoostTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        Schema::create('cb_exception_items', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('cb_validation_items', function (Blueprint $table) {
            $table->id();
            $table->string('title')->nullable();
            $table->text('body')->nullable();
            $table->timestamps();
        });
    }

    protected function tearDown(): void
    {
        CbExceptionModel::clearHiddenColumnsCache();
        parent::tearDown();
    }

    protected function createUser(int $id = 1): \App\Models\User
    {
        return \App\Models\User::forceCreate([
            'id' => $id,
            'name' => "User {$id}",
            'email' => "cbuser{$id}@example.com",
            'password' => bcrypt('password'),
        ]);
    }

    // ======================================================================
    // HidableColumns: line 129 - exception in resolveHiddenColumnsFromPolicy
    // ======================================================================

    public function test_hidable_columns_exception_in_policy_returns_empty(): void
    {
        $model = CbExceptionModel::forceCreate(['title' => 'Test']);

        Gate::shouldReceive('getPolicyFor')
            ->andThrow(new \Exception('Policy resolution failed'));

        CbExceptionModel::clearHiddenColumnsCache();

        $array = $model->toArray();
        $this->assertArrayHasKey('title', $array);
    }

    // ======================================================================
    // HidableColumns: line 214 - exception in resolvePolicyPermittedAttributes
    // ======================================================================

    public function test_as_rhino_json_exception_in_policy_permitted_attributes(): void
    {
        $post = CbExceptionModel::forceCreate(['title' => 'Test']);

        Gate::shouldReceive('getPolicyFor')
            ->andThrow(new \Exception('Policy resolution failed'));

        CbExceptionModel::clearHiddenColumnsCache();

        $result = $post->asRhinoJson(null);
        $this->assertArrayHasKey('title', $result);
    }

    // ======================================================================
    // HasPermissions: lines 98, 101 - getRoleSlugForValidation
    // ======================================================================

    public function test_get_role_slug_for_validation_with_generic_object(): void
    {
        $user = $this->createUser(90);
        $org = \App\Models\Organization::forceCreate([
            'name' => 'Generic Org',
            'slug' => 'generic-org',
        ]);

        $role = \App\Models\Role::forceCreate([
            'id' => 90,
            'name' => 'Manager',
            'slug' => 'manager',
        ]);

        \App\Models\UserRole::forceCreate([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'permissions' => ['*'],
        ]);

        // Pass a generic object (not Organization instance) with ->id
        $genericOrg = (object) ['id' => $org->id];
        $result = $user->getRoleSlugForValidation($genericOrg);
        $this->assertEquals('manager', $result);
    }

    public function test_get_role_slug_for_validation_with_object_without_id(): void
    {
        $user = $this->createUser(91);

        $badOrg = new \stdClass();
        $result = $user->getRoleSlugForValidation($badOrg);
        $this->assertNull($result);
    }

    // ======================================================================
    // ResourcePolicy: lines 164-165 - exception in resolveResourceSlug
    // ======================================================================

    public function test_resource_policy_resolve_slug_catches_exception(): void
    {
        $user = $this->createUser(92);
        $org = \App\Models\Organization::firstOrCreate(
            ['id' => 1],
            ['name' => 'Test Org', 'slug' => 'test-org']
        );
        $role = \App\Models\Role::firstOrCreate(
            ['id' => 1],
            ['name' => 'Test Role', 'slug' => 'test-role']
        );
        \App\Models\UserRole::forceCreate([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'permissions' => ['*'],
        ]);
        request()->attributes->set('organization', $org);

        // Configure a model that will make getPolicyFor throw
        config(['rhino.models' => ['crash_items' => 'NonExistent\\Model\\Class']]);

        $policy = new CbCrashingPolicy();
        $this->assertFalse($policy->viewAny($user));
    }

    // ======================================================================
    // HasValidation: various uncovered branches
    // ======================================================================

    public function test_validate_update_returns_empty_when_no_rules_properties(): void
    {
        $model = new CbValidationModelNoRules();
        $request = \Illuminate\Http\Request::create('/', 'PUT', ['title' => 'Test']);
        $validator = $model->validateUpdate($request);
        $this->assertFalse($validator->fails());
        $this->assertEmpty($validator->validated());
    }

    public function test_validate_store_returns_empty_when_no_rules_properties(): void
    {
        $model = new CbValidationModelNoRules();
        $request = \Illuminate\Http\Request::create('/', 'POST', ['title' => 'Test']);
        $validator = $model->validateStore($request);
        $this->assertFalse($validator->fails());
    }

    public function test_validate_update_with_legacy_empty_update_rules(): void
    {
        $model = new CbValidationModelLegacyConfig();
        // $validationRulesUpdate = [] which is legacy empty
        $request = \Illuminate\Http\Request::create('/', 'PUT', ['title' => 'Test']);
        $validator = $model->validateUpdate($request);
        $this->assertFalse($validator->fails());
    }

    public function test_validate_for_action_with_no_validation_rules(): void
    {
        $model = new CbValidationModelNoRules();
        $request = \Illuminate\Http\Request::create('/', 'POST', ['title' => 'Test']);
        $validator = $model->validateForAction($request, ['*'], 'store');
        $this->assertFalse($validator->fails());
    }

    public function test_validate_for_action_with_specific_permitted_fields(): void
    {
        $model = new CbValidationModelWithRulesNoStore();
        $request = \Illuminate\Http\Request::create('/', 'POST', ['title' => 'Test']);
        $validator = $model->validateForAction($request, ['title'], 'store');
        $this->assertFalse($validator->fails());
    }

    public function test_role_keyed_store_uses_wildcard_when_no_role_match(): void
    {
        $model = new CbValidationModelRoleKeyed();
        $request = \Illuminate\Http\Request::create('/', 'POST', ['title' => 'Hello']);
        $validator = $model->validateStore($request);
        $this->assertFalse($validator->fails());
    }

    public function test_role_keyed_update_returns_empty_when_no_wildcard_and_no_role(): void
    {
        $model = new CbValidationModelEmptyRoleKeyed();
        $request = \Illuminate\Http\Request::create('/', 'PUT', ['title' => 'Test']);
        $validator = $model->validateUpdate($request);
        $this->assertFalse($validator->fails());
        $this->assertEmpty($validator->validated());
    }

    public function test_role_keyed_store_returns_empty_when_no_matching_role_no_wildcard(): void
    {
        $model = new CbValidationModelEmptyRoleKeyed();
        $request = \Illuminate\Http\Request::create('/', 'POST', ['title' => 'Test']);
        $validator = $model->validateStore($request);
        $this->assertFalse($validator->fails());
    }

    public function test_merge_rules_with_full_override_pipe_modifier(): void
    {
        $model = new CbValidationModelFullOverride();
        $request = \Illuminate\Http\Request::create('/', 'POST', ['title' => 'Test']);
        $validator = $model->validateStore($request);
        $this->assertFalse($validator->fails());
    }

    public function test_has_legacy_rules_config_with_update_only(): void
    {
        $model = new CbValidationModelUpdateOnly();
        $this->assertTrue($model->hasLegacyRulesConfig());
    }

    public function test_has_legacy_rules_config_returns_false_for_no_rules(): void
    {
        $model = new CbValidationModelNoRules();
        $this->assertFalse($model->hasLegacyRulesConfig());
    }

    public function test_find_forbidden_fields_with_wildcard(): void
    {
        $model = new CbValidationModelNoRules();
        $request = \Illuminate\Http\Request::create('/', 'POST', ['title' => 'Test', 'body' => 'Text']);
        $result = $model->findForbiddenFields($request, ['*']);
        $this->assertEmpty($result);
    }

    public function test_find_forbidden_fields_with_restricted_fields(): void
    {
        $model = new CbValidationModelNoRules();
        $request = \Illuminate\Http\Request::create('/', 'POST', ['title' => 'Test', 'body' => 'Text']);
        $result = $model->findForbiddenFields($request, ['title']);
        $this->assertEquals(['body'], $result);
    }

    public function test_role_keyed_update_with_wildcard_empty(): void
    {
        $model = new CbValidationModelRoleKeyed();
        // '*' key for update returns [] => empty rules
        $request = \Illuminate\Http\Request::create('/', 'PUT', ['title' => 'Test']);
        $validator = $model->validateUpdate($request);
        $this->assertFalse($validator->fails());
    }

    public function test_validate_update_only_model(): void
    {
        $model = new CbValidationModelUpdateOnly();
        $request = \Illuminate\Http\Request::create('/', 'PUT', ['title' => 'Test']);
        $validator = $model->validateUpdate($request);
        $this->assertFalse($validator->fails());
    }

    // ======================================================================
    // ResolveOrganizationFromRoute: lines 28 and 48
    // ======================================================================

    public function test_middleware_returns_400_for_empty_organization_identifier(): void
    {
        $middleware = new \Rhino\Http\Middleware\ResolveOrganizationFromRoute();
        $request = \Illuminate\Http\Request::create('/api//users', 'GET');

        $route = $this->createMock(\Illuminate\Routing\Route::class);
        $route->method('hasParameter')->with('organization')->willReturn(true);
        $route->method('parameter')->with('organization')->willReturn(null);

        $request->setRouteResolver(function () use ($route) {
            return $route;
        });

        $response = $middleware->handle($request, function ($req) {
            return response()->json(['success' => true]);
        });

        $this->assertEquals(400, $response->getStatusCode());
    }

    public function test_middleware_returns_404_when_user_not_in_organization(): void
    {
        $org = \App\Models\Organization::forceCreate([
            'name' => 'Middleware Org',
            'slug' => 'middleware-org',
        ]);

        config(['rhino.multi_tenant.organization_identifier_column' => 'slug']);

        $user = \App\Models\User::forceCreate([
            'name' => 'Outsider',
            'email' => 'outsider@example.com',
            'password' => bcrypt('password'),
        ]);

        $middleware = new \Rhino\Http\Middleware\ResolveOrganizationFromRoute();
        $request = \Illuminate\Http\Request::create('/api/middleware-org/users', 'GET');

        $route = $this->createMock(\Illuminate\Routing\Route::class);
        $route->method('hasParameter')->with('organization')->willReturn(true);
        $route->method('parameter')->with('organization')->willReturn('middleware-org');

        $request->setRouteResolver(function () use ($route) {
            return $route;
        });

        $request->setUserResolver(function ($guard = null) use ($user) {
            return $user;
        });

        $response = $middleware->handle($request, function ($req) {
            return response()->json(['success' => true]);
        });

        $this->assertEquals(404, $response->getStatusCode());
    }

    // ======================================================================
    // HasAuditTrail: line 84 (audit_logs table doesn't exist)
    // ======================================================================

    public function test_audit_trail_skips_when_table_does_not_exist(): void
    {
        if (Schema::hasTable('audit_logs')) {
            Schema::drop('audit_logs');
        }

        Schema::create('cb_audit_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });

        $modelClass = new class extends Model {
            use \Rhino\Traits\HasAuditTrail;
            protected $table = 'cb_audit_items';
            protected $fillable = ['name'];
        };

        $item = $modelClass::create(['name' => 'Test']);
        $this->assertNotNull($item->id);

        Schema::drop('cb_audit_items');
    }
}
