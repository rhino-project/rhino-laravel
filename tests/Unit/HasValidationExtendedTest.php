<?php

namespace Rhino\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Rhino\Tests\TestCase;
use Rhino\Traits\HasValidation;
use Rhino\Traits\HidableColumns;

// --------------------------------------------------------------------------
// Test Models
// --------------------------------------------------------------------------

class HasValidationTestModel extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'hv_test_items';
    protected $fillable = ['name', 'email', 'status', 'organization_id'];

    protected $validationRules = [
        'name' => 'string|max:255',
        'email' => 'email',
        'status' => 'string|in:active,inactive',
    ];
}

class HasValidationLegacyModel extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'hv_test_items';
    protected $fillable = ['name', 'email', 'status'];

    protected $validationRules = [
        'name' => 'string|max:255',
        'email' => 'email',
        'status' => 'string|in:active,inactive',
    ];

    protected $validationRulesStore = ['name', 'email'];
    protected $validationRulesUpdate = ['name'];
}

class HasValidationRoleKeyedModel extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'hv_test_items';
    protected $fillable = ['name', 'email', 'status'];

    protected $validationRules = [
        'name' => 'string|max:255',
        'email' => 'email',
        'status' => 'string|in:active,inactive',
    ];

    protected $validationRulesStore = [
        'admin' => ['name' => 'required', 'email' => 'required', 'status' => 'nullable'],
        '*' => ['name' => 'required'],
    ];

    protected $validationRulesUpdate = [
        'admin' => ['name' => 'sometimes', 'email' => 'sometimes'],
        '*' => ['name' => 'sometimes'],
    ];
}

class HasValidationNoRulesModel extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'hv_test_items';
    protected $fillable = ['name', 'email', 'status'];
}

class HasValidationMessagesModel extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'hv_test_items';
    protected $fillable = ['name', 'email'];

    protected $validationRules = [
        'name' => 'required|string|max:255',
        'email' => 'required|email',
    ];

    protected $validationRulesStore = ['name', 'email'];
    protected $validationRulesUpdate = ['name'];

    protected $validationRulesMessages = [
        'name.required' => 'The name field is mandatory.',
    ];
}

class HasValidationPipeOverrideModel extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'hv_test_items';
    protected $fillable = ['name', 'email', 'status'];

    protected $validationRules = [
        'name' => 'string|max:255',
        'email' => 'email',
        'status' => 'string|in:active,inactive',
    ];

    protected $validationRulesStore = [
        '*' => ['name' => 'required|string|max:100', 'email' => 'required'],
    ];

    protected $validationRulesUpdate = [
        '*' => ['name' => 'sometimes'],
    ];
}

// --------------------------------------------------------------------------
// Tests
// --------------------------------------------------------------------------

class HasValidationExtendedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        Schema::create('hv_test_items', function (Blueprint $table) {
            $table->id();
            $table->string('name')->nullable();
            $table->string('email')->nullable();
            $table->string('status')->nullable();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->timestamps();
        });
    }

    // ------------------------------------------------------------------
    // hasLegacyRulesConfig
    // ------------------------------------------------------------------

    public function test_has_legacy_rules_config_true_for_legacy_model(): void
    {
        $model = new HasValidationLegacyModel();
        $this->assertTrue($model->hasLegacyRulesConfig());
    }

    public function test_has_legacy_rules_config_false_for_no_rules_model(): void
    {
        $model = new HasValidationNoRulesModel();
        $this->assertFalse($model->hasLegacyRulesConfig());
    }

    public function test_has_legacy_rules_config_false_for_policy_driven_model(): void
    {
        $model = new HasValidationTestModel();
        $this->assertFalse($model->hasLegacyRulesConfig());
    }

    public function test_has_legacy_rules_config_true_for_role_keyed_model(): void
    {
        $model = new HasValidationRoleKeyedModel();
        $this->assertTrue($model->hasLegacyRulesConfig());
    }

    // ------------------------------------------------------------------
    // findForbiddenFields
    // ------------------------------------------------------------------

    public function test_find_forbidden_fields_returns_empty_for_wildcard(): void
    {
        $model = new HasValidationTestModel();
        $request = Request::create('', 'POST', ['name' => 'Test', 'email' => 'test@example.com']);
        $result = $model->findForbiddenFields($request, ['*']);
        $this->assertSame([], $result);
    }

    public function test_find_forbidden_fields_returns_forbidden_fields(): void
    {
        $model = new HasValidationTestModel();
        $request = Request::create('', 'POST', ['name' => 'Test', 'email' => 'test@example.com', 'status' => 'active']);
        $result = $model->findForbiddenFields($request, ['name', 'email']);
        $this->assertSame(['status'], $result);
    }

    public function test_find_forbidden_fields_returns_empty_when_all_permitted(): void
    {
        $model = new HasValidationTestModel();
        $request = Request::create('', 'POST', ['name' => 'Test']);
        $result = $model->findForbiddenFields($request, ['name', 'email', 'status']);
        $this->assertSame([], $result);
    }

    // ------------------------------------------------------------------
    // validateForAction
    // ------------------------------------------------------------------

    public function test_validate_for_action_store_with_wildcard_fields(): void
    {
        $model = new HasValidationTestModel();
        $request = Request::create('', 'POST', ['name' => 'Test', 'email' => 'test@example.com']);
        $validator = $model->validateForAction($request, ['*'], 'store');
        $this->assertFalse($validator->fails());
    }

    public function test_validate_for_action_store_with_limited_fields(): void
    {
        $model = new HasValidationTestModel();
        $request = Request::create('', 'POST', ['name' => 'Test']);
        $validator = $model->validateForAction($request, ['name'], 'store');
        $this->assertFalse($validator->fails());
    }

    public function test_validate_for_action_with_no_validation_rules(): void
    {
        $model = new HasValidationNoRulesModel();
        $request = Request::create('', 'POST', ['anything' => 'goes']);
        $validator = $model->validateForAction($request, ['*'], 'store');
        $this->assertFalse($validator->fails());
    }

    public function test_validate_for_action_fails_with_invalid_data(): void
    {
        $model = new HasValidationTestModel();
        $request = Request::create('', 'POST', ['email' => 'not-an-email']);
        $validator = $model->validateForAction($request, ['*'], 'store');
        $this->assertTrue($validator->fails());
    }

    // ------------------------------------------------------------------
    // validateStore / validateUpdate (legacy path)
    // ------------------------------------------------------------------

    public function test_validate_store_legacy_passes_with_valid_data(): void
    {
        $model = new HasValidationLegacyModel();
        $request = Request::create('', 'POST', ['name' => 'Test', 'email' => 'test@example.com']);
        $validator = $model->validateStore($request);
        $this->assertFalse($validator->fails());
    }

    public function test_validate_update_legacy_passes_with_valid_data(): void
    {
        $model = new HasValidationLegacyModel();
        $request = Request::create('', 'PUT', ['name' => 'Updated']);
        $validator = $model->validateUpdate($request);
        $this->assertFalse($validator->fails());
    }

    public function test_validate_store_with_no_rules_returns_empty_validator(): void
    {
        $model = new HasValidationNoRulesModel();
        $request = Request::create('', 'POST', ['name' => 'Test']);
        $validator = $model->validateStore($request);
        $this->assertFalse($validator->fails());
    }

    public function test_validate_update_with_no_rules_returns_empty_validator(): void
    {
        $model = new HasValidationNoRulesModel();
        $request = Request::create('', 'PUT', ['name' => 'Test']);
        $validator = $model->validateUpdate($request);
        $this->assertFalse($validator->fails());
    }

    // ------------------------------------------------------------------
    // Role-keyed validation (resolveFieldsForRole / mergeRulesWithPresence)
    // ------------------------------------------------------------------

    public function test_role_keyed_store_uses_wildcard_rules_for_unknown_role(): void
    {
        $model = new HasValidationRoleKeyedModel();
        $request = Request::create('', 'POST', ['name' => 'Test']);
        $validator = $model->validateStore($request);
        $this->assertFalse($validator->fails());
    }

    public function test_role_keyed_update_uses_wildcard_rules_for_unknown_role(): void
    {
        $model = new HasValidationRoleKeyedModel();
        $request = Request::create('', 'PUT', ['name' => 'Updated']);
        $validator = $model->validateUpdate($request);
        $this->assertFalse($validator->fails());
    }

    // ------------------------------------------------------------------
    // Pipe override in mergeRulesWithPresence
    // ------------------------------------------------------------------

    public function test_pipe_override_replaces_base_rule(): void
    {
        $model = new HasValidationPipeOverrideModel();
        $request = Request::create('', 'POST', ['name' => str_repeat('a', 200), 'email' => 'test@example.com']);
        $validator = $model->validateStore($request);
        // The override sets max:100, so a 200-char string should fail
        $this->assertTrue($validator->fails());
    }

    public function test_pipe_override_valid_data_passes(): void
    {
        $model = new HasValidationPipeOverrideModel();
        $request = Request::create('', 'POST', ['name' => 'Short', 'email' => 'test@example.com']);
        $validator = $model->validateStore($request);
        $this->assertFalse($validator->fails());
    }

    // ------------------------------------------------------------------
    // Custom messages
    // ------------------------------------------------------------------

    public function test_validation_uses_custom_messages(): void
    {
        $model = new HasValidationMessagesModel();
        $request = Request::create('', 'POST', ['email' => 'test@example.com']);
        $validator = $model->validateStore($request);
        $this->assertTrue($validator->fails());
        $errors = $validator->errors();
        $this->assertStringContainsString('mandatory', $errors->first('name'));
    }
}
