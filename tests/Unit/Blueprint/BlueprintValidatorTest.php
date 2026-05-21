<?php

namespace Rhino\Tests\Unit\Blueprint;

use Rhino\Blueprint\BlueprintValidator;
use PHPUnit\Framework\TestCase;

class BlueprintValidatorTest extends TestCase
{
    protected BlueprintValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new BlueprintValidator();
    }

    // ---------------------------------------------------------------
    // validateRoles() tests
    // ---------------------------------------------------------------

    public function test_validates_valid_roles(): void
    {
        $roles = [
            'admin' => ['name' => 'Admin', 'description' => 'Administrator'],
            'viewer' => ['name' => 'Viewer', 'description' => 'Read-only'],
        ];

        $result = $this->validator->validateRoles($roles);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function test_rejects_empty_roles(): void
    {
        $result = $this->validator->validateRoles([]);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('At least one role', $result['errors'][0]);
    }

    public function test_rejects_invalid_role_slug(): void
    {
        $roles = [
            'Admin' => ['name' => 'Admin', 'description' => 'Bad slug'],
        ];

        $result = $this->validator->validateRoles($roles);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('lowercase', $result['errors'][0]);
    }

    public function test_rejects_role_missing_name(): void
    {
        $roles = [
            'admin' => ['description' => 'No name'],
        ];

        $result = $this->validator->validateRoles($roles);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('missing a name', $result['errors'][0]);
    }

    // ---------------------------------------------------------------
    // validateModel() tests
    // ---------------------------------------------------------------

    public function test_validates_valid_minimal_blueprint(): void
    {
        $blueprint = [
            'model' => 'Post',
            'slug' => 'posts',
            'columns' => [
                ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false, 'index' => false, 'default' => null, 'foreignModel' => null],
            ],
            'options' => ['except_actions' => []],
            'permissions' => [],
            'relationships' => [],
        ];

        $result = $this->validator->validateModel($blueprint);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }

    public function test_rejects_missing_model_name(): void
    {
        $blueprint = [
            'model' => '',
            'columns' => [],
            'options' => ['except_actions' => []],
            'permissions' => [],
            'relationships' => [],
        ];

        $result = $this->validator->validateModel($blueprint);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Model name is required', $result['errors'][0]);
    }

    public function test_rejects_non_pascal_case_model_name(): void
    {
        $blueprint = [
            'model' => 'blog_post',
            'columns' => [],
            'options' => ['except_actions' => []],
            'permissions' => [],
            'relationships' => [],
        ];

        $result = $this->validator->validateModel($blueprint);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('PascalCase', $result['errors'][0]);
    }

    public function test_rejects_invalid_column_type(): void
    {
        $blueprint = [
            'model' => 'Post',
            'columns' => [
                ['name' => 'title', 'type' => 'varchar', 'nullable' => false, 'unique' => false, 'index' => false, 'default' => null, 'foreignModel' => null],
            ],
            'options' => ['except_actions' => []],
            'permissions' => [],
            'relationships' => [],
        ];

        $result = $this->validator->validateModel($blueprint);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString("invalid type 'varchar'", $result['errors'][0]);
    }

    public function test_rejects_duplicate_column_names(): void
    {
        $blueprint = [
            'model' => 'Post',
            'columns' => [
                ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false, 'index' => false, 'default' => null, 'foreignModel' => null],
                ['name' => 'title', 'type' => 'text', 'nullable' => true, 'unique' => false, 'index' => false, 'default' => null, 'foreignModel' => null],
            ],
            'options' => ['except_actions' => []],
            'permissions' => [],
            'relationships' => [],
        ];

        $result = $this->validator->validateModel($blueprint);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Duplicate column', $result['errors'][0]);
    }

    public function test_rejects_foreign_id_without_foreign_model(): void
    {
        $blueprint = [
            'model' => 'Post',
            'columns' => [
                ['name' => 'user_id', 'type' => 'foreignId', 'nullable' => false, 'unique' => false, 'index' => false, 'default' => null, 'foreignModel' => null],
            ],
            'options' => ['except_actions' => []],
            'permissions' => [],
            'relationships' => [],
        ];

        $result = $this->validator->validateModel($blueprint);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString("missing 'foreign_model'", $result['errors'][0]);
    }

    public function test_rejects_unknown_role_in_permissions(): void
    {
        $validRoles = [
            'admin' => ['name' => 'Admin', 'description' => ''],
        ];

        $blueprint = [
            'model' => 'Post',
            'columns' => [
                ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false, 'index' => false, 'default' => null, 'foreignModel' => null],
            ],
            'options' => ['except_actions' => []],
            'permissions' => [
                'nonexistent_role' => [
                    'actions' => ['index'],
                    'show_fields' => ['*'],
                    'create_fields' => [],
                    'update_fields' => [],
                    'hidden_fields' => [],
                ],
            ],
            'relationships' => [],
        ];

        $result = $this->validator->validateModel($blueprint, $validRoles);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString("unknown role 'nonexistent_role'", $result['errors'][0]);
    }

    public function test_rejects_invalid_action_name(): void
    {
        $blueprint = [
            'model' => 'Post',
            'columns' => [
                ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false, 'index' => false, 'default' => null, 'foreignModel' => null],
            ],
            'options' => ['except_actions' => []],
            'permissions' => [
                'admin' => [
                    'actions' => ['index', 'delete'], // 'delete' is invalid, should be 'destroy'
                    'show_fields' => ['*'],
                    'create_fields' => [],
                    'update_fields' => [],
                    'hidden_fields' => [],
                ],
            ],
            'relationships' => [],
        ];

        $result = $this->validator->validateModel($blueprint);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString("invalid action 'delete'", $result['errors'][0]);
    }

    public function test_warns_on_unknown_field_in_show_fields(): void
    {
        $blueprint = [
            'model' => 'Post',
            'columns' => [
                ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false, 'index' => false, 'default' => null, 'foreignModel' => null],
            ],
            'options' => ['except_actions' => []],
            'permissions' => [
                'admin' => [
                    'actions' => ['index', 'show'],
                    'show_fields' => ['title', 'nonexistent_field'],
                    'create_fields' => [],
                    'update_fields' => [],
                    'hidden_fields' => [],
                ],
            ],
            'relationships' => [],
        ];

        $result = $this->validator->validateModel($blueprint);

        $this->assertTrue($result['valid']); // Warnings don't fail validation
        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('nonexistent_field', $result['warnings'][0]);
    }

    public function test_warns_on_show_hidden_field_conflict(): void
    {
        $blueprint = [
            'model' => 'Post',
            'columns' => [
                ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false, 'index' => false, 'default' => null, 'foreignModel' => null],
                ['name' => 'secret', 'type' => 'string', 'nullable' => true, 'unique' => false, 'index' => false, 'default' => null, 'foreignModel' => null],
            ],
            'options' => ['except_actions' => []],
            'permissions' => [
                'viewer' => [
                    'actions' => ['index', 'show'],
                    'show_fields' => ['title', 'secret'],
                    'create_fields' => [],
                    'update_fields' => [],
                    'hidden_fields' => ['secret'], // Conflict: in both show and hidden
                ],
            ],
            'relationships' => [],
        ];

        $result = $this->validator->validateModel($blueprint);

        $this->assertTrue($result['valid']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString('both show_fields and hidden_fields', $result['warnings'][0]);
    }

    public function test_warns_on_create_fields_without_store_action(): void
    {
        $blueprint = [
            'model' => 'Post',
            'columns' => [
                ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false, 'index' => false, 'default' => null, 'foreignModel' => null],
            ],
            'options' => ['except_actions' => []],
            'permissions' => [
                'viewer' => [
                    'actions' => ['index', 'show'], // No 'store' action
                    'show_fields' => ['*'],
                    'create_fields' => ['title'], // Has create fields but no store
                    'update_fields' => [],
                    'hidden_fields' => [],
                ],
            ],
            'relationships' => [],
        ];

        $result = $this->validator->validateModel($blueprint);

        $this->assertTrue($result['valid']);
        $this->assertNotEmpty($result['warnings']);
        $this->assertStringContainsString("create_fields but no 'store'", $result['warnings'][0]);
    }

    public function test_rejects_invalid_except_action(): void
    {
        $blueprint = [
            'model' => 'Post',
            'columns' => [],
            'options' => ['except_actions' => ['invalid_action']],
            'permissions' => [],
            'relationships' => [],
        ];

        $result = $this->validator->validateModel($blueprint);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString("Invalid except_action: 'invalid_action'", $result['errors'][0]);
    }

    public function test_rejects_invalid_relationship_type(): void
    {
        $blueprint = [
            'model' => 'Post',
            'columns' => [],
            'options' => ['except_actions' => []],
            'permissions' => [],
            'relationships' => [
                ['type' => 'manyToMany', 'model' => 'Tag'],
            ],
        ];

        $result = $this->validator->validateModel($blueprint);

        $this->assertFalse($result['valid']);
        $this->assertStringContainsString("Invalid relationship type 'manyToMany'", $result['errors'][0]);
    }

    public function test_validates_full_valid_blueprint(): void
    {
        $validRoles = [
            'owner' => ['name' => 'Owner', 'description' => ''],
            'admin' => ['name' => 'Admin', 'description' => ''],
            'viewer' => ['name' => 'Viewer', 'description' => ''],
        ];

        $blueprint = [
            'model' => 'Contract',
            'slug' => 'contracts',
            'columns' => [
                ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false, 'index' => false, 'default' => null, 'foreignModel' => null],
                ['name' => 'total_value', 'type' => 'decimal', 'nullable' => true, 'unique' => false, 'index' => false, 'default' => null, 'foreignModel' => null],
                ['name' => 'status', 'type' => 'string', 'nullable' => false, 'unique' => false, 'index' => false, 'default' => 'draft', 'foreignModel' => null],
                ['name' => 'uploaded_by', 'type' => 'foreignId', 'nullable' => false, 'unique' => false, 'index' => false, 'default' => null, 'foreignModel' => 'User'],
            ],
            'options' => [
                'belongs_to_organization' => true,
                'soft_deletes' => true,
                'audit_trail' => true,
                'except_actions' => [],
            ],
            'permissions' => [
                'owner' => [
                    'actions' => ['index', 'show', 'store', 'update', 'destroy', 'trashed', 'restore', 'forceDelete'],
                    'show_fields' => ['*'],
                    'create_fields' => ['*'],
                    'update_fields' => ['*'],
                    'hidden_fields' => [],
                ],
                'admin' => [
                    'actions' => ['index', 'show', 'store', 'update', 'destroy'],
                    'show_fields' => ['*'],
                    'create_fields' => ['title', 'status', 'total_value'],
                    'update_fields' => ['title', 'status', 'total_value'],
                    'hidden_fields' => [],
                ],
                'viewer' => [
                    'actions' => ['index', 'show'],
                    'show_fields' => ['id', 'title', 'status'],
                    'create_fields' => [],
                    'update_fields' => [],
                    'hidden_fields' => ['total_value'],
                ],
            ],
            'relationships' => [
                ['type' => 'belongsTo', 'model' => 'User', 'foreign_key' => 'uploaded_by'],
            ],
        ];

        $result = $this->validator->validateModel($blueprint, $validRoles);

        $this->assertTrue($result['valid']);
        $this->assertEmpty($result['errors']);
    }
}
