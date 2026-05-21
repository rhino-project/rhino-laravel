<?php

namespace Rhino\Tests\Unit\Blueprint;

use Rhino\Blueprint\BlueprintParser;
use Rhino\Blueprint\BlueprintValidator;
use Rhino\Blueprint\ManifestManager;
use Rhino\Blueprint\Generators\PolicyGenerator;
use Rhino\Blueprint\Generators\SeederGenerator;
use Rhino\Blueprint\Generators\TestGenerator;
use PHPUnit\Framework\TestCase;

class CoverageBoostBlueprintTest extends TestCase
{
    protected string $fixturesDir;
    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->fixturesDir = __DIR__ . '/cb_fixtures';
        $this->tempDir = sys_get_temp_dir() . '/rhino_cb_test_' . uniqid();

        if (!is_dir($this->fixturesDir)) {
            mkdir($this->fixturesDir, 0755, true);
        }
        mkdir($this->tempDir, 0755, true);
    }

    protected function tearDown(): void
    {
        $files = glob($this->fixturesDir . '/*') ?: [];
        foreach ($files as $file) {
            if (is_file($file)) unlink($file);
        }
        if (is_dir($this->fixturesDir)) rmdir($this->fixturesDir);

        $files = array_merge(
            glob($this->tempDir . '/*') ?: [],
            glob($this->tempDir . '/.*') ?: []
        );
        foreach ($files as $file) {
            if (is_file($file)) unlink($file);
        }
        if (is_dir($this->tempDir)) rmdir($this->tempDir);

        parent::tearDown();
    }

    protected function createFixture(string $filename, string $content): string
    {
        $path = $this->fixturesDir . '/' . $filename;
        file_put_contents($path, $content);
        return $path;
    }

    // ======================================================================
    // BlueprintParser: line 28 - non-string role slug
    // ======================================================================

    public function test_parse_roles_throws_for_non_string_slug(): void
    {
        $parser = new BlueprintParser();
        $path = $this->createFixture('_roles.yaml', "roles:\n  0:\n    name: Zero Role\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Role slug must be a string');
        $parser->parseRoles($path);
    }

    // ======================================================================
    // BlueprintParser: line 81 - file not readable
    // ======================================================================

    public function test_load_yaml_throws_for_unreadable_file(): void
    {
        $parser = new BlueprintParser();
        $path = $this->createFixture('unreadable.yaml', "model: Test\n");
        chmod($path, 0000);

        // Skip if running as root (can always read)
        if (is_readable($path)) {
            chmod($path, 0644);
            $this->markTestSkipped('Cannot test unreadable file as root');
        }

        try {
            $this->expectException(\RuntimeException::class);
            $this->expectExceptionMessage('not readable');
            $parser->parseModel($path);
        } finally {
            chmod($path, 0644);
        }
    }

    // ======================================================================
    // BlueprintParser: line 97 - YAML parses to non-array
    // ======================================================================

    public function test_load_yaml_throws_for_non_array_content(): void
    {
        $parser = new BlueprintParser();
        $path = $this->createFixture('scalar.yaml', "just a string value\n");

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('must contain an associative array');
        $parser->parseModel($path);
    }

    // ======================================================================
    // BlueprintParser: line 129 - short column syntax (string definition)
    // Already covered by normalizeColumns. Let's verify single-string field list.
    // ======================================================================

    // ======================================================================
    // BlueprintParser: lines 182, 189 - normalizeFieldList with string and non-array
    // ======================================================================

    public function test_normalize_field_list_with_single_string(): void
    {
        $parser = new BlueprintParser();

        // Create a blueprint with permissions that have a single string for show_fields
        $yaml = <<<YAML
model: TestItem
permissions:
  admin:
    actions: [index, show]
    show_fields: title
    create_fields: 42
YAML;
        $path = $this->createFixture('string_field.yaml', $yaml);
        $result = $parser->parseModel($path);

        // show_fields: 'title' (string) => ['title']
        $this->assertEquals(['title'], $result['permissions']['admin']['show_fields']);
        // create_fields: 42 (non-string, non-array) => []
        $this->assertEquals([], $result['permissions']['admin']['create_fields']);
    }

    // ======================================================================
    // BlueprintParser: line 200 - computeFileHash with unreadable file
    // ======================================================================

    public function test_compute_file_hash_throws_for_unreadable(): void
    {
        $parser = new BlueprintParser();
        $path = $this->fixturesDir . '/nonexistent_for_hash.yaml';

        // file_get_contents returns false for non-existent
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Cannot read file');
        $parser->computeFileHash($path);
    }

    // ======================================================================
    // BlueprintValidator: lines 113-114 - empty column name
    // ======================================================================

    public function test_validates_empty_column_name(): void
    {
        $validator = new BlueprintValidator();
        $blueprint = [
            'model' => 'TestItem',
            'columns' => [
                ['name' => '', 'type' => 'string'],
            ],
            'permissions' => [],
            'options' => [],
            'relationships' => [],
        ];

        $result = $validator->validateModel($blueprint);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Column name cannot be empty', $result['errors'][0]);
    }

    // ======================================================================
    // BlueprintValidator: line 186 - update_fields without update action
    // ======================================================================

    public function test_warns_update_fields_without_update_action(): void
    {
        $validator = new BlueprintValidator();
        $blueprint = [
            'model' => 'TestItem',
            'columns' => [
                ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false,
                 'index' => false, 'default' => null, 'filterable' => false, 'sortable' => false,
                 'searchable' => false, 'precision' => null, 'scale' => null, 'foreignModel' => null],
            ],
            'permissions' => [
                'viewer' => [
                    'actions' => ['index', 'show'],
                    'show_fields' => ['*'],
                    'create_fields' => [],
                    'update_fields' => ['title'],
                    'hidden_fields' => [],
                ],
            ],
            'options' => [],
            'relationships' => [],
        ];

        $roles = ['viewer' => ['name' => 'Viewer', 'description' => '']];
        $result = $validator->validateModel($blueprint, $roles);

        $this->assertTrue(count($result['warnings']) > 0);
        $hasWarning = false;
        foreach ($result['warnings'] as $w) {
            if (str_contains($w, 'update_fields') && str_contains($w, 'update')) {
                $hasWarning = true;
            }
        }
        $this->assertTrue($hasWarning, 'Expected warning about update_fields without update action');
    }

    // ======================================================================
    // BlueprintValidator: lines 224-225 - relationship missing type
    // ======================================================================

    public function test_validates_relationship_missing_type(): void
    {
        $validator = new BlueprintValidator();
        $blueprint = [
            'model' => 'TestItem',
            'columns' => [],
            'permissions' => [],
            'options' => [],
            'relationships' => [
                ['model' => 'User'], // missing 'type'
            ],
        ];

        $result = $validator->validateModel($blueprint);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('missing type', $result['errors'][0]);
    }

    // ======================================================================
    // BlueprintValidator: line 233 - relationship missing model
    // ======================================================================

    public function test_validates_relationship_missing_model(): void
    {
        $validator = new BlueprintValidator();
        $blueprint = [
            'model' => 'TestItem',
            'columns' => [],
            'permissions' => [],
            'options' => [],
            'relationships' => [
                ['type' => 'belongsTo'], // missing 'model'
            ],
        ];

        $result = $validator->validateModel($blueprint);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('missing model', $result['errors'][0]);
    }

    // ======================================================================
    // ManifestManager: lines 110-114 - file_get_contents returns false
    // ======================================================================

    public function test_manifest_handles_corrupt_file(): void
    {
        // Write invalid JSON that json_decode returns null for
        file_put_contents($this->tempDir . '/.blueprint-manifest.json', '{invalid json');

        $manager = new ManifestManager($this->tempDir);
        $manifest = $manager->getManifest();

        $this->assertEquals(1, $manifest['version']);
        $this->assertEmpty($manifest['files']);
    }

    // ======================================================================
    // PolicyGenerator: line 241 - inline stub fallback (no stub file)
    // ======================================================================

    public function test_policy_generator_uses_inline_stub_when_file_missing(): void
    {
        $generator = new PolicyGenerator();

        // The stub file is at src/../../stubs/blueprint/policy.php.stub
        // Since it exists, we need to mock the getStub method.
        // Instead, use reflection to call getInlineStub directly.
        $reflection = new \ReflectionMethod($generator, 'getInlineStub');
        $reflection->setAccessible(true);
        $result = $reflection->invoke($generator);

        $this->assertStringContainsString('{{ modelName }}', $result);
        $this->assertStringContainsString('{{ policyName }}', $result);
        $this->assertStringContainsString('ResourcePolicy', $result);
    }

    // If the stub file doesn't exist, generate() should still work using inline stub
    public function test_policy_generator_generate_with_empty_permissions(): void
    {
        $generator = new PolicyGenerator();
        $blueprint = [
            'model' => 'Contract',
            'permissions' => [],
        ];

        $result = $generator->generate($blueprint);
        $this->assertStringContainsString('ContractPolicy', $result);
        $this->assertStringContainsString("return ['*']", $result);
    }

    // ======================================================================
    // SeederGenerator: line 252 - permissionsToPhpArray with ['*']
    // ======================================================================

    public function test_seeder_generator_wildcard_permissions(): void
    {
        $generator = new SeederGenerator();

        $roles = [
            'admin' => ['name' => 'Admin', 'description' => 'Administrator'],
        ];

        $aggregated = ['admin' => ['*']];

        $result = $generator->generateUserRoleSeeder($roles, $aggregated);
        $this->assertStringContainsString("['*']", $result);
    }

    // ======================================================================
    // SeederGenerator: lines 268-269 - multi-line permission array
    // ======================================================================

    public function test_seeder_generator_long_permission_array(): void
    {
        $generator = new SeederGenerator();

        $roles = [
            'manager' => ['name' => 'Manager', 'description' => 'Manager role'],
        ];

        // Create a very long permissions array that exceeds 80 chars
        $longPerms = [];
        for ($i = 0; $i < 20; $i++) {
            $longPerms[] = "very_long_model_name_{$i}.index";
        }
        $aggregated = ['manager' => $longPerms];

        $result = $generator->generateUserRoleSeeder($roles, $aggregated);
        $this->assertStringContainsString('very_long_model_name_0.index', $result);
    }

    // SeederGenerator: generateUserPermissionSeeder (non-tenant)
    public function test_seeder_generator_user_permission_seeder(): void
    {
        $generator = new SeederGenerator();

        $roles = [
            'admin' => ['name' => 'Admin', 'description' => ''],
            'viewer' => ['name' => 'Viewer', 'description' => ''],
        ];

        $aggregated = [
            'admin' => ['*'],
            'viewer' => ['posts.index', 'posts.show'],
        ];

        $result = $generator->generateUserPermissionSeeder($roles, $aggregated);
        $this->assertStringContainsString('UserPermissionSeeder', $result);
        $this->assertStringContainsString("['*']", $result);
        $this->assertStringContainsString('viewer@example.com', $result);
    }

    // ======================================================================
    // TestGenerator: line 182 - show_fields > 5 (comment about more fields)
    // ======================================================================

    public function test_test_generator_field_visibility_with_many_fields(): void
    {
        $generator = new TestGenerator();

        $permissions = [
            'viewer' => [
                'actions' => ['index', 'show'],
                'show_fields' => ['id', 'title', 'content', 'status', 'priority', 'category', 'extra'],
                'create_fields' => [],
                'update_fields' => [],
                'hidden_fields' => ['secret'],
            ],
        ];

        $result = $generator->buildFieldVisibilityTests('Post', 'posts', $permissions, false, 'slug', 'pest');
        // Should contain the "and X more" comment
        $this->assertStringContainsString('more permitted fields', $result);
    }

    // ======================================================================
    // TestGenerator: lines 270-271 - forbidden field test with phpunit format
    // ======================================================================

    public function test_test_generator_forbidden_field_tests_phpunit(): void
    {
        $generator = new TestGenerator();

        $permissions = [
            'admin' => [
                'actions' => ['index', 'show', 'store', 'update', 'destroy'],
                'show_fields' => ['*'],
                'create_fields' => ['title', 'content', 'status'],
                'update_fields' => ['*'],
                'hidden_fields' => [],
            ],
            'viewer' => [
                'actions' => ['index', 'show', 'store'],
                'show_fields' => ['*'],
                'create_fields' => ['title'],
                'update_fields' => [],
                'hidden_fields' => [],
            ],
        ];

        $result = $generator->buildForbiddenFieldTests('Post', 'posts', $permissions, true, 'slug', 'phpunit');
        $this->assertStringContainsString('restricted fields', $result);
        $this->assertStringContainsString('403', $result);
    }

    // ======================================================================
    // TestGenerator: line 396 - empty toPhpArray
    // ======================================================================

    public function test_test_generator_to_php_array_empty(): void
    {
        $generator = new TestGenerator();
        $result = $generator->toPhpArray([]);
        $this->assertEquals('[]', $result);
    }

    // ======================================================================
    // TestGenerator: non-tenant PHPUnit wrapper
    // ======================================================================

    public function test_test_generator_non_tenant_phpunit(): void
    {
        $generator = new TestGenerator();
        $blueprint = [
            'model' => 'Article',
            'slug' => 'articles',
            'permissions' => [
                'admin' => [
                    'actions' => ['index', 'show', 'store', 'update', 'destroy'],
                    'show_fields' => ['*'],
                    'create_fields' => ['*'],
                    'update_fields' => ['*'],
                    'hidden_fields' => [],
                ],
            ],
        ];

        $result = $generator->generate($blueprint, 'phpunit', false, 'slug');
        $this->assertStringContainsString('class ArticleTest', $result);
        $this->assertStringContainsString('createUserWithPermissions', $result);
    }

    // ======================================================================
    // TestGenerator: multi-tenant PHPUnit wrapper
    // ======================================================================

    public function test_test_generator_multi_tenant_phpunit(): void
    {
        $generator = new TestGenerator();
        $blueprint = [
            'model' => 'Contract',
            'slug' => 'contracts',
            'permissions' => [
                'admin' => [
                    'actions' => ['index', 'show'],
                    'show_fields' => ['*'],
                    'create_fields' => [],
                    'update_fields' => [],
                    'hidden_fields' => [],
                ],
            ],
        ];

        $result = $generator->generate($blueprint, 'phpunit', true, 'slug');
        $this->assertStringContainsString('class ContractTest', $result);
        $this->assertStringContainsString('Organization', $result);
        $this->assertStringContainsString('createUserWithRole', $result);
    }

    // ======================================================================
    // PolicyGenerator: fieldsToPhpArray long list (multi-line)
    // ======================================================================

    public function test_policy_generator_fields_to_php_array_long(): void
    {
        $generator = new PolicyGenerator();

        $longFields = [];
        for ($i = 0; $i < 20; $i++) {
            $longFields[] = "very_long_field_name_{$i}";
        }

        $result = $generator->fieldsToPhpArray($longFields);
        $this->assertStringContainsString("\n", $result);
    }

    // ======================================================================
    // PolicyGenerator: buildRoleCondition with 3+ roles (multi-line)
    // ======================================================================

    public function test_policy_generator_build_role_condition_many_roles(): void
    {
        $generator = new PolicyGenerator();
        $result = $generator->buildRoleCondition(['admin', 'manager', 'editor']);
        $this->assertStringContainsString('||', $result);
        // Should use newline format for 3+ roles
        $this->assertStringContainsString("\n", $result);
    }

    // ======================================================================
    // SeederGenerator: aggregatePermissions with all actions => wildcard
    // ======================================================================

    public function test_seeder_aggregate_permissions_all_actions_wildcard(): void
    {
        $generator = new SeederGenerator();

        $blueprints = [
            [
                'slug' => 'posts',
                'permissions' => [
                    'admin' => [
                        'actions' => ['index', 'show', 'store', 'update', 'destroy', 'trashed', 'restore', 'forceDelete'],
                    ],
                ],
            ],
        ];

        $result = $generator->aggregatePermissions($blueprints);
        $this->assertEquals(['*'], $result['admin']);
    }

    // ======================================================================
    // BlueprintValidator: duplicate column name
    // ======================================================================

    public function test_validates_duplicate_column_name(): void
    {
        $validator = new BlueprintValidator();
        $blueprint = [
            'model' => 'TestItem',
            'columns' => [
                ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false,
                 'index' => false, 'default' => null, 'filterable' => false, 'sortable' => false,
                 'searchable' => false, 'precision' => null, 'scale' => null, 'foreignModel' => null],
                ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false,
                 'index' => false, 'default' => null, 'filterable' => false, 'sortable' => false,
                 'searchable' => false, 'precision' => null, 'scale' => null, 'foreignModel' => null],
            ],
            'permissions' => [],
            'options' => [],
            'relationships' => [],
        ];

        $result = $validator->validateModel($blueprint);
        $this->assertFalse($result['valid']);
        $hasError = false;
        foreach ($result['errors'] as $e) {
            if (str_contains($e, 'Duplicate column')) $hasError = true;
        }
        $this->assertTrue($hasError);
    }

    // ======================================================================
    // BlueprintValidator: invalid relationship type
    // ======================================================================

    public function test_validates_invalid_relationship_type(): void
    {
        $validator = new BlueprintValidator();
        $blueprint = [
            'model' => 'TestItem',
            'columns' => [],
            'permissions' => [],
            'options' => [],
            'relationships' => [
                ['type' => 'invalidType', 'model' => 'User'],
            ],
        ];

        $result = $validator->validateModel($blueprint);
        $this->assertFalse($result['valid']);
        $this->assertStringContainsString('Invalid relationship type', $result['errors'][0]);
    }
}
