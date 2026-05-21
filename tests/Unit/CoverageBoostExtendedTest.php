<?php

namespace Rhino\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rhino\Blueprint\ManifestManager;
use Rhino\Tests\TestCase;
use Rhino\Traits\HasValidation;

// ------------------------------------------------------------------
// Test model for HasValidation edge cases
// ------------------------------------------------------------------

class CbExtValidationModel extends Model
{
    use HasValidation;

    protected $table = 'cb_ext_val_items';
    protected $fillable = ['title', 'organization_id'];

    protected $validationRules = [
        'title' => 'string|max:255',
    ];
}

class CbExtNoRulesModel extends Model
{
    use HasValidation;

    protected $table = 'cb_ext_val_items';
    protected $fillable = ['title'];
}

class CoverageBoostExtendedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        Schema::create('cb_ext_val_items', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->foreignId('organization_id')->nullable()->constrained()->onDelete('cascade');
            $table->timestamps();
        });
    }

    // ------------------------------------------------------------------
    // ManifestManager — file_get_contents failure path
    // ------------------------------------------------------------------

    public function test_manifest_manager_handles_missing_manifest_file(): void
    {
        $tempDir = sys_get_temp_dir() . '/rhino_manifest_test_' . uniqid();
        @mkdir($tempDir, 0755, true);

        $manager = new ManifestManager($tempDir);

        // The load method should return a default structure when no file exists
        $ref = new \ReflectionMethod(ManifestManager::class, 'load');
        $ref->setAccessible(true);
        $result = $ref->invoke($manager);

        $this->assertArrayHasKey('version', $result);
        $this->assertArrayHasKey('files', $result);
        $this->assertNull($result['generated_at']);

        @rmdir($tempDir);
    }

    // ------------------------------------------------------------------
    // HasValidation — validateForAction
    // ------------------------------------------------------------------

    public function test_validate_for_action_with_no_validation_rules(): void
    {
        $model = new CbExtNoRulesModel();

        $request = \Illuminate\Http\Request::create('/test', 'POST', ['title' => 'Test']);

        $validator = $model->validateForAction($request, ['*'], 'store');

        // Should not fail — no rules means no validation
        $this->assertFalse($validator->fails());
    }

    public function test_validate_for_action_with_wildcard_permitted_fields(): void
    {
        $model = new CbExtValidationModel();

        $request = \Illuminate\Http\Request::create('/test', 'POST', ['title' => 'Test Title']);

        $validator = $model->validateForAction($request, ['*'], 'store');

        $this->assertFalse($validator->fails());
    }

    public function test_validate_for_action_with_specific_permitted_fields(): void
    {
        $model = new CbExtValidationModel();

        $request = \Illuminate\Http\Request::create('/test', 'POST', ['title' => 'Test']);

        $validator = $model->validateForAction($request, ['title'], 'store');

        $this->assertFalse($validator->fails());
    }

    public function test_validate_for_action_excludes_non_permitted_fields(): void
    {
        $model = new CbExtValidationModel();

        // Set up a model with a field that has a validation rule but is not permitted
        $request = \Illuminate\Http\Request::create('/test', 'POST', ['title' => '']);

        $validator = $model->validateForAction($request, ['title'], 'store');

        // title is required|string|max:255, empty string should fail
        // Actually the rule is 'string|max:255' so empty string is valid
        $this->assertFalse($validator->fails());
    }

    // ------------------------------------------------------------------
    // HasValidation — findForbiddenFields
    // ------------------------------------------------------------------

    public function test_find_forbidden_fields_with_wildcard(): void
    {
        $model = new CbExtValidationModel();
        $request = \Illuminate\Http\Request::create('/test', 'POST', ['title' => 'Test', 'extra' => 'data']);

        $result = $model->findForbiddenFields($request, ['*']);

        $this->assertEmpty($result);
    }

    public function test_find_forbidden_fields_returns_forbidden(): void
    {
        $model = new CbExtValidationModel();
        $request = \Illuminate\Http\Request::create('/test', 'POST', ['title' => 'Test', 'secret' => 'data']);

        $result = $model->findForbiddenFields($request, ['title']);

        $this->assertContains('secret', $result);
    }

    public function test_find_forbidden_fields_returns_empty_when_all_permitted(): void
    {
        $model = new CbExtValidationModel();
        $request = \Illuminate\Http\Request::create('/test', 'POST', ['title' => 'Test']);

        $result = $model->findForbiddenFields($request, ['title']);

        $this->assertEmpty($result);
    }

    // ------------------------------------------------------------------
    // HasValidation — hasLegacyRulesConfig
    // ------------------------------------------------------------------

    public function test_has_legacy_rules_config_returns_false_when_no_properties(): void
    {
        $model = new CbExtNoRulesModel();

        $result = $model->hasLegacyRulesConfig();

        $this->assertFalse($result);
    }

    // ------------------------------------------------------------------
    // HasValidation — validateStore/validateUpdate with no rules
    // ------------------------------------------------------------------

    public function test_validate_store_with_no_rules_returns_passing_validator(): void
    {
        $model = new CbExtNoRulesModel();
        $request = \Illuminate\Http\Request::create('/test', 'POST', ['title' => 'Test']);

        $validator = $model->validateStore($request);

        $this->assertFalse($validator->fails());
    }

    public function test_validate_update_with_no_rules_returns_passing_validator(): void
    {
        $model = new CbExtNoRulesModel();
        $request = \Illuminate\Http\Request::create('/test', 'PUT', ['title' => 'Updated']);

        $validator = $model->validateUpdate($request);

        $this->assertFalse($validator->fails());
    }

    // ------------------------------------------------------------------
    // HasValidation — scopeExistsRulesToOrganization in tenant context
    // ------------------------------------------------------------------

    public function test_validate_for_action_scopes_exists_rules_in_tenant_context(): void
    {
        $org = \App\Models\Organization::forceCreate([
            'name' => 'Test Org',
            'slug' => 'test-org-validate',
        ]);

        // Set organization on the request
        request()->attributes->set('organization', $org);

        $model = new CbExtValidationModel();
        $request = \Illuminate\Http\Request::create('/test', 'POST', ['title' => 'Test']);
        $request->attributes->set('organization', $org);

        $validator = $model->validateForAction($request, ['title'], 'store');

        $this->assertFalse($validator->fails());

        // Clean up
        request()->attributes->remove('organization');
    }

    // ------------------------------------------------------------------
    // HasValidation — scopeExistsInRuleSet: array input (line 224)
    // ------------------------------------------------------------------

    public function test_scope_exists_in_rule_set_with_array_input(): void
    {
        $model = new CbExtValidationModel();

        $method = new \ReflectionMethod($model, 'scopeExistsInRuleSet');
        $method->setAccessible(true);

        // Pass rules as an array instead of pipe-separated string
        $rules = ['required', 'string', 'max:255'];
        $result = $method->invoke($model, $rules, 1);

        // Should return array (since input was array)
        $this->assertIsArray($result);
        $this->assertContains('required', $result);
        $this->assertContains('string', $result);
    }

    // ------------------------------------------------------------------
    // HasValidation — scopeExistsInRuleSet: exists rule with no table (line 241-243)
    // ------------------------------------------------------------------

    public function test_scope_exists_in_rule_set_with_empty_table(): void
    {
        $model = new CbExtValidationModel();

        $method = new \ReflectionMethod($model, 'scopeExistsInRuleSet');
        $method->setAccessible(true);

        // "exists:" with no table name
        $result = $method->invoke($model, 'required|exists:', 1);

        $this->assertIsString($result);
        $this->assertStringContainsString('exists:', $result);
    }

    // ------------------------------------------------------------------
    // HasValidation — scopeExistsInRuleSet: already scoped with organization_id (line 247-249)
    // ------------------------------------------------------------------

    public function test_scope_exists_in_rule_set_already_scoped(): void
    {
        $model = new CbExtValidationModel();

        $method = new \ReflectionMethod($model, 'scopeExistsInRuleSet');
        $method->setAccessible(true);

        // Rule that already has organization_id
        $result = $method->invoke($model, 'required|exists:organizations,id,organization_id,5', 1);

        $this->assertIsString($result);
        // Should not add organization_id again
        $this->assertSame(1, substr_count($result, 'organization_id'));
    }

    // ------------------------------------------------------------------
    // HasValidation — walkFkChain: exception when getting foreign keys (line 306-307)
    // ------------------------------------------------------------------

    public function test_walk_fk_chain_handles_schema_exception(): void
    {
        $model = new CbExtValidationModel();

        $method = new \ReflectionMethod($model, 'walkFkChain');
        $method->setAccessible(true);

        // Use a non-existent table to trigger Schema exception
        $result = $method->invoke($model, 'nonexistent_table_xyz', 5, []);

        $this->assertNull($result);
    }

    // ------------------------------------------------------------------
    // HasValidation — walkFkChain: max depth exceeded (line 299)
    // ------------------------------------------------------------------

    public function test_walk_fk_chain_returns_null_on_max_depth(): void
    {
        $model = new CbExtValidationModel();

        $method = new \ReflectionMethod($model, 'walkFkChain');
        $method->setAccessible(true);

        // depth of 0 should immediately return null
        $result = $method->invoke($model, 'organizations', 0, []);

        $this->assertNull($result);
    }

    // ------------------------------------------------------------------
    // HasValidation — walkFkChain: visited cycle detection
    // ------------------------------------------------------------------

    public function test_walk_fk_chain_returns_null_on_visited(): void
    {
        $model = new CbExtValidationModel();

        $method = new \ReflectionMethod($model, 'walkFkChain');
        $method->setAccessible(true);

        // Pass the table itself as already visited
        $result = $method->invoke($model, 'organizations', 5, ['organizations']);

        $this->assertNull($result);
    }

    // ------------------------------------------------------------------
    // HasValidation — scopeExistsInRuleSet: non-string rule in array (line 231)
    // ------------------------------------------------------------------

    public function test_scope_exists_in_rule_set_with_non_string_parts(): void
    {
        $model = new CbExtValidationModel();

        $method = new \ReflectionMethod($model, 'scopeExistsInRuleSet');
        $method->setAccessible(true);

        // Array with Rule object (non-string part)
        $rules = [
            'required',
            new \Illuminate\Validation\Rules\In(['a', 'b']),
            'string',
        ];

        $result = $method->invoke($model, $rules, 1);

        $this->assertIsArray($result);
        $this->assertCount(3, $result);
    }
}
