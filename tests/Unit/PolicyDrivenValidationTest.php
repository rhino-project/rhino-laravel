<?php

namespace Rhino\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Http\Request;
use Rhino\Tests\TestCase;
use Rhino\Traits\HasValidation;

// --------------------------------------------------------------------------
// Test Models
// --------------------------------------------------------------------------

/**
 * Model with $validationRules but NO $validationRulesStore/$validationRulesUpdate.
 * This uses the new policy-driven path.
 */
class PolicyDrivenModel extends Model
{
    use HasValidation;

    protected $table = 'policy_driven_models';
    protected $fillable = ['title', 'content', 'status'];

    protected $validationRules = [
        'title' => 'string|max:255',
        'content' => 'string',
        'status' => 'string|in:draft,published',
    ];
}

/**
 * Model with legacy $validationRulesStore/$validationRulesUpdate.
 */
class LegacyValidationModel extends Model
{
    use HasValidation;

    protected $table = 'policy_driven_models';
    protected $fillable = ['title', 'content', 'status'];

    protected $validationRules = [
        'title' => 'string|max:255',
        'content' => 'string',
        'status' => 'string|in:draft,published',
    ];

    protected $validationRulesStore = ['title', 'content'];
    protected $validationRulesUpdate = ['title', 'content'];
}

/**
 * Model with empty $validationRulesStore/$validationRulesUpdate.
 */
class EmptyLegacyModel extends Model
{
    use HasValidation;

    protected $table = 'policy_driven_models';
    protected $fillable = ['title', 'content', 'status'];

    protected $validationRules = [
        'title' => 'string|max:255',
        'content' => 'string',
    ];

    protected $validationRulesStore = [];
    protected $validationRulesUpdate = [];
}

/**
 * Model with no $validationRules property at all.
 */
class NoValidationRulesModel extends Model
{
    use HasValidation;

    protected $table = 'policy_driven_models';
    protected $fillable = ['title', 'content'];
}

// --------------------------------------------------------------------------
// Tests
// --------------------------------------------------------------------------

class PolicyDrivenValidationTest extends TestCase
{
    // ------------------------------------------------------------------
    // findForbiddenFields()
    // ------------------------------------------------------------------

    public function test_find_forbidden_fields_returns_empty_for_wildcard(): void
    {
        $model = new PolicyDrivenModel();
        $request = Request::create('', 'POST', ['title' => 'Test', 'content' => 'Body', 'status' => 'draft']);

        $forbidden = $model->findForbiddenFields($request, ['*']);

        $this->assertSame([], $forbidden);
    }

    public function test_find_forbidden_fields_returns_forbidden_fields(): void
    {
        $model = new PolicyDrivenModel();
        $request = Request::create('', 'POST', ['title' => 'Test', 'content' => 'Body', 'status' => 'draft']);

        $forbidden = $model->findForbiddenFields($request, ['title', 'content']);

        $this->assertSame(['status'], $forbidden);
    }

    public function test_find_forbidden_fields_returns_empty_when_all_permitted(): void
    {
        $model = new PolicyDrivenModel();
        $request = Request::create('', 'POST', ['title' => 'Test']);

        $forbidden = $model->findForbiddenFields($request, ['title', 'content']);

        $this->assertSame([], $forbidden);
    }

    public function test_find_forbidden_fields_returns_multiple_forbidden(): void
    {
        $model = new PolicyDrivenModel();
        $request = Request::create('', 'POST', ['title' => 'Test', 'content' => 'Body', 'status' => 'draft']);

        $forbidden = $model->findForbiddenFields($request, ['title']);

        $this->assertCount(2, $forbidden);
        $this->assertContains('content', $forbidden);
        $this->assertContains('status', $forbidden);
    }

    // ------------------------------------------------------------------
    // validateForAction()
    // ------------------------------------------------------------------

    public function test_validate_for_action_uses_all_rules_with_wildcard(): void
    {
        $model = new PolicyDrivenModel();
        $request = Request::create('', 'POST', ['title' => 123, 'status' => 'invalid']);

        $validator = $model->validateForAction($request, ['*'], 'store');

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('status', $validator->errors()->toArray());
    }

    public function test_validate_for_action_validates_only_permitted_fields(): void
    {
        $model = new PolicyDrivenModel();
        // status is invalid but NOT in permitted fields, so it should not be validated
        $request = Request::create('', 'POST', ['title' => 'Valid Title', 'status' => 'invalid']);

        $validator = $model->validateForAction($request, ['title'], 'store');

        $this->assertFalse($validator->fails());
    }

    public function test_validate_for_action_returns_empty_rules_when_no_validation_rules(): void
    {
        $model = new NoValidationRulesModel();
        $request = Request::create('', 'POST', ['title' => 'Test', 'anything' => 'goes']);

        $validator = $model->validateForAction($request, ['*'], 'store');

        $this->assertFalse($validator->fails());
    }

    public function test_validate_for_action_catches_format_errors(): void
    {
        $model = new PolicyDrivenModel();
        $request = Request::create('', 'POST', ['title' => str_repeat('a', 300)]);

        $validator = $model->validateForAction($request, ['title'], 'store');

        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('title', $validator->errors()->toArray());
    }

    // ------------------------------------------------------------------
    // hasLegacyRulesConfig()
    // ------------------------------------------------------------------

    public function test_has_legacy_config_true_with_store_rules(): void
    {
        $model = new LegacyValidationModel();

        $this->assertTrue($model->hasLegacyRulesConfig());
    }

    public function test_has_legacy_config_false_with_empty_rules(): void
    {
        $model = new EmptyLegacyModel();

        $this->assertFalse($model->hasLegacyRulesConfig());
    }

    public function test_has_legacy_config_false_when_no_properties(): void
    {
        $model = new PolicyDrivenModel();

        $this->assertFalse($model->hasLegacyRulesConfig());
    }

    public function test_has_legacy_config_false_for_no_validation_rules_model(): void
    {
        $model = new NoValidationRulesModel();

        $this->assertFalse($model->hasLegacyRulesConfig());
    }
}
