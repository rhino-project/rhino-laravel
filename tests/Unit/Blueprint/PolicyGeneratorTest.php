<?php

namespace Rhino\Tests\Unit\Blueprint;

use Rhino\Blueprint\Generators\PolicyGenerator;
use PHPUnit\Framework\TestCase;

class PolicyGeneratorTest extends TestCase
{
    protected PolicyGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new PolicyGenerator();
    }

    // ---------------------------------------------------------------
    // groupRolesByFields() tests
    // ---------------------------------------------------------------

    public function test_groups_roles_with_identical_wildcard_fields(): void
    {
        $permissions = [
            'owner' => ['show_fields' => ['*']],
            'admin' => ['show_fields' => ['*']],
            'viewer' => ['show_fields' => ['id', 'title']],
        ];

        $groups = $this->generator->groupRolesByFields($permissions, 'show_fields');

        $this->assertCount(2, $groups);

        // First group: owner + admin (both wildcard)
        $this->assertEquals(['*'], $groups[0]['fields']);
        $this->assertEquals(['owner', 'admin'], $groups[0]['roles']);

        // Second group: viewer
        $this->assertEquals(['id', 'title'], $groups[1]['fields']);
        $this->assertEquals(['viewer'], $groups[1]['roles']);
    }

    public function test_groups_roles_with_identical_field_lists(): void
    {
        $permissions = [
            'admin' => ['create_fields' => ['title', 'status']],
            'manager' => ['create_fields' => ['title', 'status']],
            'analyst' => ['create_fields' => ['title']],
        ];

        $groups = $this->generator->groupRolesByFields($permissions, 'create_fields');

        $this->assertCount(2, $groups);
        $this->assertEquals(['admin', 'manager'], $groups[0]['roles']);
        $this->assertEquals(['analyst'], $groups[1]['roles']);
    }

    public function test_handles_single_role(): void
    {
        $permissions = [
            'admin' => ['show_fields' => ['*']],
        ];

        $groups = $this->generator->groupRolesByFields($permissions, 'show_fields');

        $this->assertCount(1, $groups);
        $this->assertEquals(['admin'], $groups[0]['roles']);
    }

    // ---------------------------------------------------------------
    // buildRoleCondition() tests
    // ---------------------------------------------------------------

    public function test_builds_single_role_condition(): void
    {
        $condition = $this->generator->buildRoleCondition(['admin']);

        $this->assertEquals("\$this->hasRole(\$user, 'admin')", $condition);
    }

    public function test_builds_two_role_condition(): void
    {
        $condition = $this->generator->buildRoleCondition(['admin', 'manager']);

        $this->assertStringContainsString("hasRole(\$user, 'admin')", $condition);
        $this->assertStringContainsString("hasRole(\$user, 'manager')", $condition);
        $this->assertStringContainsString('||', $condition);
    }

    public function test_builds_multi_line_condition_for_three_plus_roles(): void
    {
        $condition = $this->generator->buildRoleCondition(['owner', 'admin', 'manager']);

        $this->assertStringContainsString("\n", $condition);
        $this->assertStringContainsString("hasRole(\$user, 'owner')", $condition);
        $this->assertStringContainsString("hasRole(\$user, 'admin')", $condition);
        $this->assertStringContainsString("hasRole(\$user, 'manager')", $condition);
    }

    // ---------------------------------------------------------------
    // fieldsToPhpArray() tests
    // ---------------------------------------------------------------

    public function test_wildcard_fields_to_php_array(): void
    {
        $result = $this->generator->fieldsToPhpArray(['*']);

        $this->assertEquals("['*']", $result);
    }

    public function test_empty_fields_to_php_array(): void
    {
        $result = $this->generator->fieldsToPhpArray([]);

        $this->assertEquals('[]', $result);
    }

    public function test_short_fields_inline(): void
    {
        $result = $this->generator->fieldsToPhpArray(['id', 'title', 'status']);

        $this->assertEquals("['id', 'title', 'status']", $result);
        $this->assertStringNotContainsString("\n", $result);
    }

    public function test_long_fields_multiline(): void
    {
        $fields = ['id', 'title', 'status', 'type', 'department', 'counterparty_name', 'counterparty_cnpj'];
        $result = $this->generator->fieldsToPhpArray($fields);

        $this->assertStringContainsString("\n", $result);
        $this->assertStringContainsString("'id'", $result);
        $this->assertStringContainsString("'counterparty_cnpj'", $result);
    }

    // ---------------------------------------------------------------
    // buildPermittedAttributesMethod() tests
    // ---------------------------------------------------------------

    public function test_generates_wildcard_method_for_empty_permissions(): void
    {
        $result = $this->generator->buildPermittedAttributesMethod(
            'permittedAttributesForShow',
            [],
            'show_fields'
        );

        $this->assertStringContainsString('permittedAttributesForShow', $result);
        $this->assertStringContainsString("return ['*']", $result);
    }

    public function test_generates_method_with_wildcard_and_restricted_roles(): void
    {
        $permissions = [
            'owner' => ['show_fields' => ['*']],
            'admin' => ['show_fields' => ['*']],
            'viewer' => ['show_fields' => ['id', 'title']],
        ];

        $result = $this->generator->buildPermittedAttributesMethod(
            'permittedAttributesForShow',
            $permissions,
            'show_fields'
        );

        $this->assertStringContainsString('permittedAttributesForShow', $result);
        $this->assertStringContainsString("hasRole(\$user, 'owner')", $result);
        $this->assertStringContainsString("hasRole(\$user, 'admin')", $result);
        $this->assertStringContainsString("return ['*']", $result);
        $this->assertStringContainsString("hasRole(\$user, 'viewer')", $result);
        $this->assertStringContainsString("'id'", $result);
        $this->assertStringContainsString("'title'", $result);
        $this->assertStringContainsString('return []', $result); // Default fallback
    }

    public function test_generates_create_method_with_restricted_roles(): void
    {
        $permissions = [
            'owner' => ['create_fields' => ['*']],
            'admin' => ['create_fields' => ['title', 'status']],
            'viewer' => ['create_fields' => []], // No create access
        ];

        $result = $this->generator->buildPermittedAttributesMethod(
            'permittedAttributesForCreate',
            $permissions,
            'create_fields'
        );

        $this->assertStringContainsString('permittedAttributesForCreate', $result);
        $this->assertStringContainsString("hasRole(\$user, 'owner')", $result);
        $this->assertStringContainsString("return ['*']", $result);
        $this->assertStringContainsString("hasRole(\$user, 'admin')", $result);
        $this->assertStringContainsString("'title'", $result);
        // Viewer should NOT appear (empty fields are skipped)
        $this->assertStringNotContainsString("hasRole(\$user, 'viewer')", $result);
    }

    // ---------------------------------------------------------------
    // buildHiddenAttributesMethod() tests
    // ---------------------------------------------------------------

    public function test_generates_empty_hidden_method_when_no_hidden_fields(): void
    {
        $permissions = [
            'owner' => ['hidden_fields' => []],
            'admin' => ['hidden_fields' => []],
        ];

        $result = $this->generator->buildHiddenAttributesMethod($permissions);

        $this->assertStringContainsString('hiddenAttributesForShow', $result);
        $this->assertStringContainsString('return []', $result);
        $this->assertStringNotContainsString('hasRole', $result);
    }

    public function test_generates_hidden_method_with_role_specific_fields(): void
    {
        $permissions = [
            'owner' => ['hidden_fields' => []],
            'analyst' => ['hidden_fields' => ['total_value', 'risk_score']],
            'viewer' => ['hidden_fields' => ['total_value', 'risk_score', 'cnpj']],
        ];

        $result = $this->generator->buildHiddenAttributesMethod($permissions);

        $this->assertStringContainsString('hiddenAttributesForShow', $result);
        $this->assertStringContainsString("hasRole(\$user, 'analyst')", $result);
        $this->assertStringContainsString("'total_value'", $result);
        $this->assertStringContainsString("hasRole(\$user, 'viewer')", $result);
        $this->assertStringContainsString("'cnpj'", $result);
        // Owner should NOT appear (no hidden fields)
        $this->assertStringNotContainsString("hasRole(\$user, 'owner')", $result);
    }

    public function test_groups_roles_with_same_hidden_fields(): void
    {
        $permissions = [
            'analyst' => ['hidden_fields' => ['total_value']],
            'viewer' => ['hidden_fields' => ['total_value']], // Same as analyst
        ];

        $result = $this->generator->buildHiddenAttributesMethod($permissions);

        // Should group analyst and viewer together
        $this->assertStringContainsString("hasRole(\$user, 'analyst')", $result);
        $this->assertStringContainsString("hasRole(\$user, 'viewer')", $result);
        $this->assertStringContainsString('||', $result);
    }

    // ---------------------------------------------------------------
    // Full generate() tests
    // ---------------------------------------------------------------

    public function test_generates_complete_policy_file(): void
    {
        $blueprint = [
            'model' => 'Contract',
            'slug' => 'contracts',
            'permissions' => [
                'owner' => [
                    'actions' => ['index', 'show', 'store', 'update', 'destroy'],
                    'show_fields' => ['*'],
                    'create_fields' => ['*'],
                    'update_fields' => ['*'],
                    'hidden_fields' => [],
                ],
                'viewer' => [
                    'actions' => ['index', 'show'],
                    'show_fields' => ['id', 'title'],
                    'create_fields' => [],
                    'update_fields' => [],
                    'hidden_fields' => ['secret_field'],
                ],
            ],
        ];

        $result = $this->generator->generate($blueprint);

        // Check class structure
        $this->assertStringContainsString('namespace App\Policies', $result);
        $this->assertStringContainsString('class ContractPolicy extends ResourcePolicy', $result);
        $this->assertStringContainsString('use App\Models\Contract', $result);

        // Check all four methods are present
        $this->assertStringContainsString('permittedAttributesForShow', $result);
        $this->assertStringContainsString('hiddenAttributesForShow', $result);
        $this->assertStringContainsString('permittedAttributesForCreate', $result);
        $this->assertStringContainsString('permittedAttributesForUpdate', $result);

        // Check role conditions
        $this->assertStringContainsString("hasRole(\$user, 'owner')", $result);
        $this->assertStringContainsString("hasRole(\$user, 'viewer')", $result);

        // Check field arrays
        $this->assertStringContainsString("return ['*']", $result);
        $this->assertStringContainsString("'id'", $result);
        $this->assertStringContainsString("'title'", $result);
        $this->assertStringContainsString("'secret_field'", $result);

        // Generated by comment
        $this->assertStringContainsString('Rhino Blueprint', $result);
    }

    public function test_generates_valid_php_syntax(): void
    {
        $blueprint = [
            'model' => 'Post',
            'slug' => 'posts',
            'permissions' => [
                'admin' => [
                    'show_fields' => ['*'],
                    'create_fields' => ['title', 'content'],
                    'update_fields' => ['title', 'content'],
                    'hidden_fields' => [],
                ],
                'viewer' => [
                    'show_fields' => ['id', 'title'],
                    'create_fields' => [],
                    'update_fields' => [],
                    'hidden_fields' => ['content'],
                ],
            ],
        ];

        $result = $this->generator->generate($blueprint);

        // Write to temp file and check PHP syntax
        $tmpFile = tempnam(sys_get_temp_dir(), 'policy_') . '.php';
        file_put_contents($tmpFile, $result);

        $output = [];
        $exitCode = 0;
        exec("php -l {$tmpFile} 2>&1", $output, $exitCode);

        unlink($tmpFile);

        $this->assertEquals(0, $exitCode, "Generated policy has PHP syntax errors:\n" . implode("\n", $output));
    }
}
