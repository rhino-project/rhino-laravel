<?php

namespace Rhino\Tests\Unit\Blueprint;

use Rhino\Blueprint\Generators\TestGenerator;
use PHPUnit\Framework\TestCase;

class TestGeneratorTest extends TestCase
{
    protected TestGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new TestGenerator();
    }

    // ---------------------------------------------------------------
    // actionsToPermissions() tests
    // ---------------------------------------------------------------

    public function test_converts_all_actions_to_wildcard(): void
    {
        $actions = ['index', 'show', 'store', 'update', 'destroy', 'trashed', 'restore', 'forceDelete'];
        $result = $this->generator->actionsToPermissions('contracts', $actions);

        $this->assertEquals(['contracts.*'], $result);
    }

    public function test_converts_partial_actions_to_individual_permissions(): void
    {
        $actions = ['index', 'show'];
        $result = $this->generator->actionsToPermissions('contracts', $actions);

        $this->assertEquals(['contracts.index', 'contracts.show'], $result);
    }

    // ---------------------------------------------------------------
    // buildCrudAccessTests() tests
    // ---------------------------------------------------------------

    public function test_generates_crud_access_tests_for_allowed_endpoints(): void
    {
        $permissions = [
            'admin' => [
                'actions' => ['index', 'show', 'store', 'update', 'destroy'],
                'show_fields' => ['*'],
                'create_fields' => ['*'],
                'update_fields' => ['*'],
                'hidden_fields' => [],
            ],
        ];

        $result = $this->generator->buildCrudAccessTests(
            'Contract', 'contracts', $permissions,
            true, 'slug', 'pest'
        );

        $this->assertStringContainsString("it('allows admin", $result);
        $this->assertStringContainsString('assertStatus(200)', $result);
        $this->assertStringContainsString('getJson(', $result);
        $this->assertStringContainsString('deleteJson(', $result);
    }

    public function test_generates_blocked_endpoint_tests(): void
    {
        $permissions = [
            'viewer' => [
                'actions' => ['index', 'show'],
                'show_fields' => ['*'],
                'create_fields' => [],
                'update_fields' => [],
                'hidden_fields' => [],
            ],
        ];

        $result = $this->generator->buildCrudAccessTests(
            'Contract', 'contracts', $permissions,
            true, 'slug', 'pest'
        );

        $this->assertStringContainsString("it('blocks viewer", $result);
        $this->assertStringContainsString('assertStatus(403)', $result);
        $this->assertStringContainsString('postJson(', $result);
        $this->assertStringContainsString('putJson(', $result);
        $this->assertStringContainsString('deleteJson(', $result);
    }

    public function test_generates_multi_tenant_urls(): void
    {
        $permissions = [
            'admin' => [
                'actions' => ['index', 'show'],
                'show_fields' => ['*'],
                'create_fields' => [],
                'update_fields' => [],
                'hidden_fields' => [],
            ],
        ];

        $result = $this->generator->buildCrudAccessTests(
            'Contract', 'contracts', $permissions,
            true, 'slug', 'pest'
        );

        $this->assertStringContainsString("\$org->slug", $result);
        $this->assertStringContainsString("'/api/' . \$org->slug . '/contracts'", $result);
    }

    public function test_generates_non_tenant_urls(): void
    {
        $permissions = [
            'admin' => [
                'actions' => ['index'],
                'show_fields' => ['*'],
                'create_fields' => [],
                'update_fields' => [],
                'hidden_fields' => [],
            ],
        ];

        $result = $this->generator->buildCrudAccessTests(
            'Post', 'posts', $permissions,
            false, 'id', 'pest'
        );

        $this->assertStringContainsString("'/api/posts'", $result);
        $this->assertStringNotContainsString('$org->slug', $result);
    }

    public function test_generates_phpunit_format_multi_tenant(): void
    {
        $permissions = [
            'admin' => [
                'actions' => ['index', 'show'],
                'show_fields' => ['*'],
                'create_fields' => [],
                'update_fields' => [],
                'hidden_fields' => [],
            ],
        ];

        $result = $this->generator->buildCrudAccessTests(
            'Post', 'posts', $permissions,
            true, 'slug', 'phpunit'
        );

        $this->assertStringContainsString('public function test_', $result);
        $this->assertStringContainsString('$this->createUserWithRole', $result);
    }

    public function test_generates_phpunit_format_non_tenant(): void
    {
        $permissions = [
            'admin' => [
                'actions' => ['index', 'show'],
                'show_fields' => ['*'],
                'create_fields' => [],
                'update_fields' => [],
                'hidden_fields' => [],
            ],
        ];

        $result = $this->generator->buildCrudAccessTests(
            'Post', 'posts', $permissions,
            false, 'id', 'phpunit'
        );

        $this->assertStringContainsString('public function test_', $result);
        $this->assertStringContainsString('$this->createUserWithPermissions', $result);
        $this->assertStringNotContainsString('createUserWithRole', $result);
    }

    public function test_returns_empty_for_no_permissions(): void
    {
        $result = $this->generator->buildCrudAccessTests(
            'Post', 'posts', [],
            false, 'id', 'pest'
        );

        $this->assertEmpty($result);
    }

    // ---------------------------------------------------------------
    // buildFieldVisibilityTests() tests
    // ---------------------------------------------------------------

    public function test_generates_field_visibility_test_for_restricted_role(): void
    {
        $permissions = [
            'viewer' => [
                'actions' => ['index', 'show'],
                'show_fields' => ['id', 'title'],
                'create_fields' => [],
                'update_fields' => [],
                'hidden_fields' => ['secret', 'internal_notes'],
            ],
        ];

        $result = $this->generator->buildFieldVisibilityTests(
            'Contract', 'contracts', $permissions,
            true, 'slug', 'pest'
        );

        $this->assertStringContainsString("it('shows only permitted fields for viewer", $result);
        $this->assertStringContainsString("assertArrayHasKey('id'", $result);
        $this->assertStringContainsString("assertArrayHasKey('title'", $result);
        $this->assertStringContainsString("assertArrayNotHasKey('secret'", $result);
        $this->assertStringContainsString("assertArrayNotHasKey('internal_notes'", $result);
    }

    public function test_skips_visibility_test_for_wildcard_roles(): void
    {
        $permissions = [
            'admin' => [
                'actions' => ['index', 'show'],
                'show_fields' => ['*'],
                'create_fields' => ['*'],
                'update_fields' => ['*'],
                'hidden_fields' => [],
            ],
        ];

        $result = $this->generator->buildFieldVisibilityTests(
            'Post', 'posts', $permissions,
            false, 'id', 'pest'
        );

        $this->assertEmpty($result);
    }

    public function test_skips_visibility_test_for_roles_without_show_action(): void
    {
        $permissions = [
            'restricted' => [
                'actions' => ['store'], // No 'show' action
                'show_fields' => ['title'],
                'create_fields' => ['title'],
                'update_fields' => [],
                'hidden_fields' => [],
            ],
        ];

        $result = $this->generator->buildFieldVisibilityTests(
            'Post', 'posts', $permissions,
            false, 'id', 'pest'
        );

        $this->assertEmpty($result);
    }

    // ---------------------------------------------------------------
    // buildForbiddenFieldTests() tests
    // ---------------------------------------------------------------

    public function test_generates_forbidden_field_test(): void
    {
        $permissions = [
            'admin' => [
                'actions' => ['index', 'show', 'store', 'update'],
                'show_fields' => ['*'],
                'create_fields' => ['title', 'status', 'total_value'],
                'update_fields' => ['title', 'status', 'total_value'],
                'hidden_fields' => [],
            ],
            'analyst' => [
                'actions' => ['index', 'show', 'store', 'update'],
                'show_fields' => ['*'],
                'create_fields' => ['title'], // Can't set status or total_value
                'update_fields' => ['title'],
                'hidden_fields' => [],
            ],
        ];

        $result = $this->generator->buildForbiddenFieldTests(
            'Contract', 'contracts', $permissions,
            true, 'slug', 'pest'
        );

        $this->assertStringContainsString("it('returns 403 when analyst tries to set restricted fields", $result);
        $this->assertStringContainsString('postJson(', $result);
        $this->assertStringContainsString('assertStatus(403)', $result);
        // Should reference a field analyst can't set
        $this->assertStringContainsString("restricted for analyst", $result);
    }

    public function test_skips_forbidden_test_for_wildcard_create_roles(): void
    {
        $permissions = [
            'admin' => [
                'actions' => ['store'],
                'show_fields' => ['*'],
                'create_fields' => ['*'], // Wildcard — no restrictions
                'update_fields' => ['*'],
                'hidden_fields' => [],
            ],
        ];

        $result = $this->generator->buildForbiddenFieldTests(
            'Post', 'posts', $permissions,
            false, 'id', 'pest'
        );

        $this->assertEmpty($result);
    }

    public function test_skips_forbidden_test_for_roles_without_store_action(): void
    {
        $permissions = [
            'viewer' => [
                'actions' => ['index', 'show'], // No 'store'
                'show_fields' => ['id', 'title'],
                'create_fields' => [],
                'update_fields' => [],
                'hidden_fields' => [],
            ],
        ];

        $result = $this->generator->buildForbiddenFieldTests(
            'Post', 'posts', $permissions,
            false, 'id', 'pest'
        );

        $this->assertEmpty($result);
    }

    // ---------------------------------------------------------------
    // Full generate() tests — Multi-tenant
    // ---------------------------------------------------------------

    public function test_generates_complete_pest_test_file_multi_tenant(): void
    {
        $blueprint = [
            'model' => 'Contract',
            'slug' => 'contracts',
            'permissions' => [
                'admin' => [
                    'actions' => ['index', 'show', 'store', 'update', 'destroy'],
                    'show_fields' => ['*'],
                    'create_fields' => ['title', 'status'],
                    'update_fields' => ['title', 'status'],
                    'hidden_fields' => [],
                ],
                'viewer' => [
                    'actions' => ['index', 'show'],
                    'show_fields' => ['id', 'title'],
                    'create_fields' => [],
                    'update_fields' => [],
                    'hidden_fields' => ['status'],
                ],
            ],
        ];

        $result = $this->generator->generate($blueprint, 'pest', true, 'slug');

        // Check Pest structure — multi-tenant imports
        $this->assertStringContainsString('use App\Models\Contract', $result);
        $this->assertStringContainsString('use App\Models\Role', $result);
        $this->assertStringContainsString('use App\Models\Organization', $result);
        $this->assertStringContainsString('use App\Models\UserRole', $result);
        $this->assertStringContainsString('uses(RefreshDatabase::class)', $result);
        $this->assertStringContainsString('function createUserWithRole', $result);

        // Check CRUD tests
        $this->assertStringContainsString("it('allows admin", $result);
        $this->assertStringContainsString("it('blocks viewer", $result);

        // Check field visibility tests
        $this->assertStringContainsString("it('shows only permitted fields for viewer", $result);
        $this->assertStringContainsString("assertArrayNotHasKey('status'", $result);

        // Check forbidden field tests
        $this->assertStringContainsString("403", $result);

        // Check multi-tenant user setup
        $this->assertStringContainsString('$org = Organization::factory()->create()', $result);
        $this->assertStringContainsString("createUserWithRole('admin', \$org", $result);
    }

    public function test_generates_complete_phpunit_test_file_multi_tenant(): void
    {
        $blueprint = [
            'model' => 'Post',
            'slug' => 'posts',
            'permissions' => [
                'admin' => [
                    'actions' => ['index', 'show', 'store'],
                    'show_fields' => ['*'],
                    'create_fields' => ['*'],
                    'update_fields' => ['*'],
                    'hidden_fields' => [],
                ],
            ],
        ];

        $result = $this->generator->generate($blueprint, 'phpunit', true, 'slug');

        // Check PHPUnit structure — multi-tenant
        $this->assertStringContainsString('namespace Tests\Model', $result);
        $this->assertStringContainsString('class PostTest extends TestCase', $result);
        $this->assertStringContainsString('use RefreshDatabase', $result);
        $this->assertStringContainsString('protected function createUserWithRole', $result);
        $this->assertStringContainsString('use App\Models\Role', $result);
        $this->assertStringContainsString('use App\Models\Organization', $result);
        $this->assertStringContainsString('use App\Models\UserRole', $result);
        $this->assertStringContainsString('public function test_', $result);
    }

    // ---------------------------------------------------------------
    // Full generate() tests — Non-tenant
    // ---------------------------------------------------------------

    public function test_generates_complete_pest_test_file_non_tenant(): void
    {
        $blueprint = [
            'model' => 'Post',
            'slug' => 'posts',
            'permissions' => [
                'admin' => [
                    'actions' => ['index', 'show', 'store', 'update', 'destroy'],
                    'show_fields' => ['*'],
                    'create_fields' => ['title', 'status'],
                    'update_fields' => ['title', 'status'],
                    'hidden_fields' => [],
                ],
                'viewer' => [
                    'actions' => ['index', 'show'],
                    'show_fields' => ['id', 'title'],
                    'create_fields' => [],
                    'update_fields' => [],
                    'hidden_fields' => ['status'],
                ],
            ],
        ];

        $result = $this->generator->generate($blueprint, 'pest', false, 'id');

        // Check Pest structure — non-tenant imports (NO Role, Organization, UserRole)
        $this->assertStringContainsString('use App\Models\User', $result);
        $this->assertStringContainsString('use App\Models\Post', $result);
        $this->assertStringNotContainsString('use App\Models\Role', $result);
        $this->assertStringNotContainsString('use App\Models\Organization', $result);
        $this->assertStringNotContainsString('use App\Models\UserRole', $result);
        $this->assertStringContainsString('uses(RefreshDatabase::class)', $result);

        // Check helper — createUserWithPermissions (NOT createUserWithRole)
        $this->assertStringContainsString('function createUserWithPermissions', $result);
        $this->assertStringNotContainsString('createUserWithRole', $result);

        // Check that permissions are stored directly on User
        $this->assertStringContainsString("User::factory()->create(['permissions' =>", $result);

        // Check CRUD tests
        $this->assertStringContainsString("it('allows admin", $result);
        $this->assertStringContainsString("it('blocks viewer", $result);

        // Check non-tenant user setup (no $org)
        $this->assertStringNotContainsString('Organization::factory()->create()', $result);
        $this->assertStringContainsString("createUserWithPermissions([", $result);

        // Check non-tenant URLs
        $this->assertStringContainsString("'/api/posts'", $result);
        $this->assertStringNotContainsString('$org->slug', $result);
    }

    public function test_generates_complete_phpunit_test_file_non_tenant(): void
    {
        $blueprint = [
            'model' => 'Post',
            'slug' => 'posts',
            'permissions' => [
                'admin' => [
                    'actions' => ['index', 'show', 'store'],
                    'show_fields' => ['*'],
                    'create_fields' => ['*'],
                    'update_fields' => ['*'],
                    'hidden_fields' => [],
                ],
            ],
        ];

        $result = $this->generator->generate($blueprint, 'phpunit', false, 'id');

        // Check PHPUnit structure — non-tenant (NO Role, Organization, UserRole)
        $this->assertStringContainsString('namespace Tests\Model', $result);
        $this->assertStringContainsString('class PostTest extends TestCase', $result);
        $this->assertStringContainsString('use RefreshDatabase', $result);
        $this->assertStringNotContainsString('use App\Models\Role', $result);
        $this->assertStringNotContainsString('use App\Models\Organization', $result);
        $this->assertStringNotContainsString('use App\Models\UserRole', $result);

        // Check helper — createUserWithPermissions (NOT createUserWithRole)
        $this->assertStringContainsString('protected function createUserWithPermissions', $result);
        $this->assertStringNotContainsString('createUserWithRole', $result);
        $this->assertStringContainsString('public function test_', $result);
    }

    // ---------------------------------------------------------------
    // PHP syntax validation
    // ---------------------------------------------------------------

    public function test_generates_valid_php_syntax_pest_multi_tenant(): void
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
                'analyst' => [
                    'actions' => ['index', 'show', 'store'],
                    'show_fields' => ['id', 'title'],
                    'create_fields' => ['title'],
                    'update_fields' => [],
                    'hidden_fields' => ['secret'],
                ],
                'viewer' => [
                    'actions' => ['index', 'show'],
                    'show_fields' => ['id', 'title'],
                    'create_fields' => [],
                    'update_fields' => [],
                    'hidden_fields' => ['secret', 'internal'],
                ],
            ],
        ];

        $result = $this->generator->generate($blueprint, 'pest', true, 'slug');

        $tmpFile = tempnam(sys_get_temp_dir(), 'test_') . '.php';
        file_put_contents($tmpFile, $result);

        $output = [];
        $exitCode = 0;
        exec("php -l {$tmpFile} 2>&1", $output, $exitCode);

        unlink($tmpFile);

        $this->assertEquals(0, $exitCode, "Generated multi-tenant Pest test has PHP syntax errors:\n" . implode("\n", $output));
    }

    public function test_generates_valid_php_syntax_pest_non_tenant(): void
    {
        $blueprint = [
            'model' => 'Post',
            'slug' => 'posts',
            'permissions' => [
                'admin' => [
                    'actions' => ['index', 'show', 'store', 'update', 'destroy'],
                    'show_fields' => ['*'],
                    'create_fields' => ['title', 'status', 'total_value'],
                    'update_fields' => ['title', 'status'],
                    'hidden_fields' => [],
                ],
                'analyst' => [
                    'actions' => ['index', 'show', 'store'],
                    'show_fields' => ['id', 'title'],
                    'create_fields' => ['title'],
                    'update_fields' => [],
                    'hidden_fields' => ['total_value', 'secret'],
                ],
                'viewer' => [
                    'actions' => ['index', 'show'],
                    'show_fields' => ['id', 'title'],
                    'create_fields' => [],
                    'update_fields' => [],
                    'hidden_fields' => ['total_value', 'secret', 'internal'],
                ],
            ],
        ];

        $result = $this->generator->generate($blueprint, 'pest', false, 'id');

        $tmpFile = tempnam(sys_get_temp_dir(), 'test_') . '.php';
        file_put_contents($tmpFile, $result);

        $output = [];
        $exitCode = 0;
        exec("php -l {$tmpFile} 2>&1", $output, $exitCode);

        unlink($tmpFile);

        $this->assertEquals(0, $exitCode, "Generated non-tenant Pest test has PHP syntax errors:\n" . implode("\n", $output));
    }

    public function test_generates_valid_php_syntax_phpunit_non_tenant(): void
    {
        $blueprint = [
            'model' => 'Post',
            'slug' => 'posts',
            'permissions' => [
                'admin' => [
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
                    'hidden_fields' => ['secret'],
                ],
            ],
        ];

        $result = $this->generator->generate($blueprint, 'phpunit', false, 'id');

        $tmpFile = tempnam(sys_get_temp_dir(), 'test_') . '.php';
        file_put_contents($tmpFile, $result);

        $output = [];
        $exitCode = 0;
        exec("php -l {$tmpFile} 2>&1", $output, $exitCode);

        unlink($tmpFile);

        $this->assertEquals(0, $exitCode, "Generated non-tenant PHPUnit test has PHP syntax errors:\n" . implode("\n", $output));
    }

    // ---------------------------------------------------------------
    // Non-tenant user setup in test bodies
    // ---------------------------------------------------------------

    public function test_non_tenant_crud_tests_use_createUserWithPermissions(): void
    {
        $permissions = [
            'admin' => [
                'actions' => ['index', 'show'],
                'show_fields' => ['*'],
                'create_fields' => [],
                'update_fields' => [],
                'hidden_fields' => [],
            ],
        ];

        $result = $this->generator->buildCrudAccessTests(
            'Post', 'posts', $permissions,
            false, 'id', 'pest'
        );

        $this->assertStringContainsString('createUserWithPermissions(', $result);
        $this->assertStringNotContainsString('createUserWithRole', $result);
        $this->assertStringNotContainsString('Organization::factory()', $result);
    }

    public function test_non_tenant_field_visibility_tests_use_createUserWithPermissions(): void
    {
        $permissions = [
            'viewer' => [
                'actions' => ['index', 'show'],
                'show_fields' => ['id', 'title'],
                'create_fields' => [],
                'update_fields' => [],
                'hidden_fields' => ['secret'],
            ],
        ];

        $result = $this->generator->buildFieldVisibilityTests(
            'Post', 'posts', $permissions,
            false, 'id', 'pest'
        );

        $this->assertStringContainsString('createUserWithPermissions(', $result);
        $this->assertStringNotContainsString('createUserWithRole', $result);
        $this->assertStringNotContainsString('Organization::factory()', $result);
        // Non-tenant model factory should NOT pass organization_id
        $this->assertStringNotContainsString('organization_id', $result);
    }

    public function test_non_tenant_forbidden_field_tests_use_createUserWithPermissions(): void
    {
        $permissions = [
            'admin' => [
                'actions' => ['index', 'show', 'store'],
                'show_fields' => ['*'],
                'create_fields' => ['title', 'status'],
                'update_fields' => ['title', 'status'],
                'hidden_fields' => [],
            ],
            'analyst' => [
                'actions' => ['index', 'show', 'store'],
                'show_fields' => ['*'],
                'create_fields' => ['title'],
                'update_fields' => ['title'],
                'hidden_fields' => [],
            ],
        ];

        $result = $this->generator->buildForbiddenFieldTests(
            'Post', 'posts', $permissions,
            false, 'id', 'pest'
        );

        $this->assertStringContainsString('createUserWithPermissions(', $result);
        $this->assertStringNotContainsString('createUserWithRole', $result);
        $this->assertStringNotContainsString('Organization::factory()', $result);
    }

    public function test_multi_tenant_crud_tests_use_createUserWithRole(): void
    {
        $permissions = [
            'admin' => [
                'actions' => ['index', 'show'],
                'show_fields' => ['*'],
                'create_fields' => [],
                'update_fields' => [],
                'hidden_fields' => [],
            ],
        ];

        $result = $this->generator->buildCrudAccessTests(
            'Contract', 'contracts', $permissions,
            true, 'slug', 'pest'
        );

        $this->assertStringContainsString('createUserWithRole(', $result);
        $this->assertStringContainsString('Organization::factory()->create()', $result);
        $this->assertStringNotContainsString('createUserWithPermissions', $result);
    }
}
