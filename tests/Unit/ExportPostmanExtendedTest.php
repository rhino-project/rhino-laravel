<?php

namespace Rhino\Tests\Unit;

use Rhino\Commands\ExportPostmanCommand;
use Rhino\Tests\TestCase;
use ReflectionMethod;

class ExportPostmanExtendedTest extends TestCase
{
    protected ExportPostmanCommand $command;

    protected function setUp(): void
    {
        parent::setUp();
        $this->command = new ExportPostmanCommand();
    }

    protected function invokeMethod(string $method, array $args = []): mixed
    {
        $ref = new ReflectionMethod(ExportPostmanCommand::class, $method);
        $ref->setAccessible(true);
        return $ref->invoke($this->command, ...$args);
    }

    // ------------------------------------------------------------------
    // buildCollectionVariables
    // ------------------------------------------------------------------

    public function test_build_collection_variables_without_org_prefix(): void
    {
        $vars = $this->invokeMethod('buildCollectionVariables', ['http://localhost:8000/api', false]);

        $keys = array_column($vars, 'key');
        $this->assertContains('baseUrl', $keys);
        $this->assertContains('modelId', $keys);
        $this->assertContains('token', $keys);
        $this->assertNotContains('organization', $keys);
    }

    public function test_build_collection_variables_with_org_prefix(): void
    {
        $vars = $this->invokeMethod('buildCollectionVariables', ['http://localhost:8000/api', true]);

        $keys = array_column($vars, 'key');
        $this->assertContains('organization', $keys);
    }

    // ------------------------------------------------------------------
    // basePath
    // ------------------------------------------------------------------

    public function test_base_path_without_prefix(): void
    {
        $result = $this->invokeMethod('basePath', ['articles', '']);
        $this->assertEquals('{{baseUrl}}/articles', $result);
    }

    public function test_base_path_with_org_prefix(): void
    {
        $result = $this->invokeMethod('basePath', ['articles', '{organization}']);
        $this->assertEquals('{{baseUrl}}/{{organization}}/articles', $result);
    }

    public function test_base_path_with_literal_prefix(): void
    {
        $result = $this->invokeMethod('basePath', ['articles', 'admin']);
        $this->assertEquals('{{baseUrl}}/admin/articles', $result);
    }

    // ------------------------------------------------------------------
    // exampleFilterValue
    // ------------------------------------------------------------------

    public function test_example_filter_value_for_boolean_field(): void
    {
        $this->assertEquals('1', $this->invokeMethod('exampleFilterValue', ['is_published']));
        $this->assertEquals('1', $this->invokeMethod('exampleFilterValue', ['is_active']));
        $this->assertEquals('1', $this->invokeMethod('exampleFilterValue', ['published']));
    }

    public function test_example_filter_value_for_regular_field(): void
    {
        $this->assertEquals('example', $this->invokeMethod('exampleFilterValue', ['title']));
        $this->assertEquals('example', $this->invokeMethod('exampleFilterValue', ['name']));
    }

    // ------------------------------------------------------------------
    // exampleValueForRule
    // ------------------------------------------------------------------

    public function test_example_value_for_boolean_rule(): void
    {
        $this->assertTrue($this->invokeMethod('exampleValueForRule', ['is_active', 'boolean']));
    }

    public function test_example_value_for_integer_rule(): void
    {
        $this->assertEquals(1, $this->invokeMethod('exampleValueForRule', ['count', 'integer']));
    }

    public function test_example_value_for_exists_rule(): void
    {
        $this->assertEquals(1, $this->invokeMethod('exampleValueForRule', ['blog_id', 'exists:blogs,id']));
    }

    public function test_example_value_for_numeric_rule(): void
    {
        $this->assertEquals(1, $this->invokeMethod('exampleValueForRule', ['price', 'numeric']));
    }

    public function test_example_value_for_max_rule(): void
    {
        $result = $this->invokeMethod('exampleValueForRule', ['title', 'string|max:5']);
        $this->assertEquals('aaaaa', $result);
    }

    public function test_example_value_for_max_rule_with_large_max(): void
    {
        $result = $this->invokeMethod('exampleValueForRule', ['title', 'string|max:255']);
        $this->assertEquals('aaaaaaaaaa', $result); // min(10, 255) = 10
    }

    public function test_example_value_for_string_rule_default(): void
    {
        $result = $this->invokeMethod('exampleValueForRule', ['title', 'string']);
        $this->assertEquals('Example title', $result);
    }

    // ------------------------------------------------------------------
    // resolveRoleFields
    // ------------------------------------------------------------------

    public function test_resolve_role_fields_empty_array(): void
    {
        $this->assertNull($this->invokeMethod('resolveRoleFields', [[], '*']));
    }

    public function test_resolve_role_fields_legacy_flat_array(): void
    {
        // Array of strings => legacy format, return null
        $this->assertNull($this->invokeMethod('resolveRoleFields', [['name', 'email'], '*']));
    }

    public function test_resolve_role_fields_role_keyed_matching(): void
    {
        $config = [
            'admin' => ['name' => 'required', 'email' => 'required'],
            '*' => ['name' => 'required'],
        ];
        $result = $this->invokeMethod('resolveRoleFields', [$config, 'admin']);
        $this->assertEquals(['name' => 'required', 'email' => 'required'], $result);
    }

    public function test_resolve_role_fields_role_keyed_wildcard_fallback(): void
    {
        $config = [
            'admin' => ['name' => 'required', 'email' => 'required'],
            '*' => ['name' => 'required'],
        ];
        $result = $this->invokeMethod('resolveRoleFields', [$config, 'viewer']);
        $this->assertEquals(['name' => 'required'], $result);
    }

    public function test_resolve_role_fields_role_keyed_first_fallback(): void
    {
        $config = [
            'admin' => ['name' => 'required', 'email' => 'required'],
        ];
        $result = $this->invokeMethod('resolveRoleFields', [$config, 'viewer']);
        $this->assertEquals(['name' => 'required', 'email' => 'required'], $result);
    }

    // ------------------------------------------------------------------
    // defaultHeaders
    // ------------------------------------------------------------------

    public function test_default_headers_has_accept_and_auth(): void
    {
        $headers = $this->invokeMethod('defaultHeaders', []);

        $keys = array_column($headers, 'key');
        $this->assertContains('Accept', $keys);
        $this->assertContains('Authorization', $keys);
    }

    // ------------------------------------------------------------------
    // requestItem
    // ------------------------------------------------------------------

    public function test_request_item_basic_get(): void
    {
        $item = $this->invokeMethod('requestItem', [
            'List all', 'GET', '{{baseUrl}}/articles', [], [['key' => 'Accept', 'value' => 'application/json']], null, null,
        ]);

        $this->assertEquals('List all', $item['name']);
        $this->assertEquals('GET', $item['request']['method']);
    }

    public function test_request_item_with_query_params(): void
    {
        $item = $this->invokeMethod('requestItem', [
            'Paginate', 'GET', '{{baseUrl}}/articles', ['per_page' => '5', 'page' => '1'],
            [['key' => 'Accept', 'value' => 'application/json']], null, null,
        ]);

        $this->assertArrayHasKey('query', $item['request']['url']);
        $this->assertCount(2, $item['request']['url']['query']);
    }

    public function test_request_item_with_body(): void
    {
        $item = $this->invokeMethod('requestItem', [
            'Create', 'POST', '{{baseUrl}}/articles', [],
            [['key' => 'Accept', 'value' => 'application/json']], ['title' => 'Test'], null,
        ]);

        $this->assertArrayHasKey('body', $item['request']);
        $this->assertEquals('raw', $item['request']['body']['mode']);
    }

    public function test_request_item_with_test_script(): void
    {
        $item = $this->invokeMethod('requestItem', [
            'Login', 'POST', '{{baseUrl}}/login', [],
            [['key' => 'Accept', 'value' => 'application/json']],
            ['email' => 'user@example.com'],
            'console.log("test")',
        ]);

        $this->assertArrayHasKey('event', $item);
        $this->assertEquals('test', $item['event'][0]['listen']);
    }

    public function test_request_item_get_no_body_even_with_data(): void
    {
        $item = $this->invokeMethod('requestItem', [
            'Get', 'GET', '{{baseUrl}}/articles', [],
            [['key' => 'Accept', 'value' => 'application/json']], ['title' => 'Test'], null,
        ]);

        // GET requests should not have body
        $this->assertArrayNotHasKey('body', $item['request']);
    }

    // ------------------------------------------------------------------
    // buildAuthFolder
    // ------------------------------------------------------------------

    public function test_build_auth_folder_structure(): void
    {
        $folder = $this->invokeMethod('buildAuthFolder', ['http://localhost:8000/api']);

        $this->assertEquals('Authentication', $folder['name']);
        $this->assertCount(6, $folder['item']);

        $names = array_column($folder['item'], 'name');
        $this->assertContains('Login', $names);
        $this->assertContains('Logout', $names);
        $this->assertContains('Password recover', $names);
        $this->assertContains('Password reset', $names);
        $this->assertContains('Register (with invitation)', $names);
        $this->assertContains('Accept invitation', $names);
    }

    // ------------------------------------------------------------------
    // exampleBodyFromRules
    // ------------------------------------------------------------------

    public function test_example_body_from_rules_generates_body(): void
    {
        $roleFields = ['title' => 'required', 'count' => 'required'];
        $baseRules = ['title' => 'string|max:255', 'count' => 'integer'];
        $body = $this->invokeMethod('exampleBodyFromRules', [$roleFields, $baseRules]);

        $this->assertArrayHasKey('title', $body);
        $this->assertArrayHasKey('count', $body);
        $this->assertEquals(1, $body['count']); // integer rule
    }

    public function test_example_body_from_rules_uses_field_as_fallback(): void
    {
        $roleFields = ['custom_field' => 'required'];
        $baseRules = [];
        $body = $this->invokeMethod('exampleBodyFromRules', [$roleFields, $baseRules]);

        $this->assertArrayHasKey('custom_field', $body);
    }

    // ------------------------------------------------------------------
    // getModelProperty — exception catch (lines 159-160)
    // ------------------------------------------------------------------

    public function test_get_model_property_returns_default_on_exception(): void
    {
        // Call with a model class that has a property that throws on access
        $result = $this->invokeMethod('getModelProperty', [
            \Illuminate\Database\Eloquent\Model::class,
            'nonExistentProperty',
            'default_value',
        ]);

        $this->assertSame('default_value', $result);
    }

    // ------------------------------------------------------------------
    // buildActionFolders — model with multiple includes (lines 306-313)
    // ------------------------------------------------------------------

    public function test_build_action_folders_includes_include_all_when_multiple_includes(): void
    {
        $modelMeta = [
            'exceptActions' => [],
            'usesSoftDeletes' => false,
            'allowedFilters' => [],
            'allowedSorts' => [],
            'allowedFields' => [],
            'allowedIncludes' => ['user', 'comments', 'tags'],
            'allowedSearch' => [],
            'defaultSort' => null,
            'paginationEnabled' => false,
            'validationRules' => [],
            'validationRulesStore' => [],
            'validationRulesUpdate' => [],
        ];

        $result = $this->invokeMethod('buildActionFolders', [
            'posts', $modelMeta, \Illuminate\Database\Eloquent\Model::class, '', 'http://localhost/api',
        ]);

        // Find the 'Index' folder which should contain the include items
        $indexFolder = collect($result)->firstWhere('name', 'Index');
        $this->assertNotNull($indexFolder);

        // Check that there is an "Include all" request item
        $requestNames = array_column($indexFolder['item'], 'name');
        $this->assertContains('Include all', $requestNames);
    }

    // ------------------------------------------------------------------
    // buildStoreBodies — role-based store rules (line 388)
    // ------------------------------------------------------------------

    public function test_build_store_bodies_with_role_based_rules(): void
    {
        $modelMeta = [
            'validationRulesStore' => [
                'admin' => ['title', 'slug', 'content'],
                'editor' => ['title', 'content'],
            ],
            'validationRules' => [
                'title' => 'required|string|max:255',
                'slug' => 'string|max:100',
                'content' => 'string',
            ],
        ];

        $result = $this->invokeMethod('buildStoreBodies', [
            \Illuminate\Database\Eloquent\Model::class,
            $modelMeta,
        ]);

        // Should return an array of body fields
        $this->assertIsArray($result);
    }

    // ------------------------------------------------------------------
    // buildUpdateBodies — role-based update rules (line 410)
    // ------------------------------------------------------------------

    public function test_build_update_bodies_with_role_based_rules(): void
    {
        $modelMeta = [
            'validationRulesUpdate' => [
                'admin' => ['title', 'content'],
            ],
            'validationRules' => [
                'title' => 'string|max:255',
                'content' => 'string',
            ],
        ];

        $result = $this->invokeMethod('buildUpdateBodies', [
            \Illuminate\Database\Eloquent\Model::class,
            $modelMeta,
        ]);

        $this->assertIsArray($result);
        $this->assertArrayHasKey('full', $result);
        $this->assertArrayHasKey('partial', $result);
    }

    // ------------------------------------------------------------------
    // buildStoreBodies — empty store rules fallback
    // ------------------------------------------------------------------

    public function test_build_store_bodies_with_empty_rules(): void
    {
        $modelMeta = [
            'validationRulesStore' => [],
            'validationRules' => [
                'title' => 'required|string|max:255',
            ],
        ];

        $result = $this->invokeMethod('buildStoreBodies', [
            \Illuminate\Database\Eloquent\Model::class,
            $modelMeta,
        ]);

        $this->assertIsArray($result);
    }

    // ------------------------------------------------------------------
    // introspectModel — model without class existence
    // ------------------------------------------------------------------

    public function test_introspect_model_returns_metadata(): void
    {
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        $result = $this->invokeMethod('introspectModel', [
            \App\Models\Organization::class,
            'organizations',
        ]);

        $this->assertArrayHasKey('exceptActions', $result);
        $this->assertArrayHasKey('usesSoftDeletes', $result);
        $this->assertArrayHasKey('allowedFilters', $result);
    }
}
