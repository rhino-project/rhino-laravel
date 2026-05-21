<?php

namespace Rhino\Tests\Unit\Blueprint;

use Rhino\Blueprint\Generators\SeederGenerator;
use PHPUnit\Framework\TestCase;

class SeederGeneratorTest extends TestCase
{
    protected SeederGenerator $generator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->generator = new SeederGenerator();
    }

    // ---------------------------------------------------------------
    // aggregatePermissions() tests
    // ---------------------------------------------------------------

    public function test_aggregates_permissions_from_multiple_blueprints(): void
    {
        $blueprints = [
            [
                'slug' => 'contracts',
                'permissions' => [
                    'admin' => ['actions' => ['index', 'show', 'store', 'update', 'destroy']],
                    'viewer' => ['actions' => ['index', 'show']],
                ],
            ],
            [
                'slug' => 'alerts',
                'permissions' => [
                    'admin' => ['actions' => ['index', 'show', 'update']],
                    'viewer' => ['actions' => ['index', 'show']],
                ],
            ],
        ];

        $aggregated = $this->generator->aggregatePermissions($blueprints);

        $this->assertArrayHasKey('admin', $aggregated);
        $this->assertArrayHasKey('viewer', $aggregated);

        // Admin should have all contract permissions and alert permissions
        $this->assertContains('contracts.index', $aggregated['admin']);
        $this->assertContains('contracts.destroy', $aggregated['admin']);
        $this->assertContains('alerts.index', $aggregated['admin']);
        $this->assertContains('alerts.update', $aggregated['admin']);

        // Viewer should have only index/show
        $this->assertContains('contracts.index', $aggregated['viewer']);
        $this->assertContains('contracts.show', $aggregated['viewer']);
        $this->assertContains('alerts.index', $aggregated['viewer']);
        $this->assertNotContains('contracts.store', $aggregated['viewer']);
    }

    public function test_uses_wildcard_when_all_actions_present(): void
    {
        $blueprints = [
            [
                'slug' => 'contracts',
                'permissions' => [
                    'admin' => [
                        'actions' => ['index', 'show', 'store', 'update', 'destroy', 'trashed', 'restore', 'forceDelete'],
                    ],
                ],
            ],
            [
                'slug' => 'alerts',
                'permissions' => [
                    'admin' => [
                        'actions' => ['index', 'show'],
                    ],
                ],
            ],
        ];

        $aggregated = $this->generator->aggregatePermissions($blueprints);

        // contracts gets per-model wildcard, alerts stays individual
        $this->assertContains('contracts.*', $aggregated['admin']);
        $this->assertContains('alerts.index', $aggregated['admin']);
        $this->assertContains('alerts.show', $aggregated['admin']);
        $this->assertNotContains('contracts.index', $aggregated['admin']);
    }

    public function test_simplifies_to_global_wildcard_when_all_models_wildcard(): void
    {
        $blueprints = [
            [
                'slug' => 'contracts',
                'permissions' => [
                    'owner' => [
                        'actions' => ['index', 'show', 'store', 'update', 'destroy', 'trashed', 'restore', 'forceDelete'],
                    ],
                ],
            ],
            [
                'slug' => 'alerts',
                'permissions' => [
                    'owner' => [
                        'actions' => ['index', 'show', 'store', 'update', 'destroy', 'trashed', 'restore', 'forceDelete'],
                    ],
                ],
            ],
        ];

        $aggregated = $this->generator->aggregatePermissions($blueprints);

        $this->assertEquals(['*'], $aggregated['owner']);
    }

    public function test_handles_empty_blueprints(): void
    {
        $aggregated = $this->generator->aggregatePermissions([]);

        $this->assertEmpty($aggregated);
    }

    public function test_deduplicates_permissions(): void
    {
        $blueprints = [
            [
                'slug' => 'contracts',
                'permissions' => [
                    'admin' => ['actions' => ['index', 'show']],
                ],
            ],
            [
                'slug' => 'contracts', // Same model slug, different blueprint
                'permissions' => [
                    'admin' => ['actions' => ['index', 'show']],
                ],
            ],
        ];

        $aggregated = $this->generator->aggregatePermissions($blueprints);

        // Should not have duplicates
        $adminPerms = $aggregated['admin'];
        $this->assertEquals(count($adminPerms), count(array_unique($adminPerms)));
    }

    // ---------------------------------------------------------------
    // generateRoleSeeder() tests
    // ---------------------------------------------------------------

    public function test_generates_role_seeder(): void
    {
        $roles = [
            'owner' => ['name' => 'Owner', 'description' => 'Full access owner'],
            'admin' => ['name' => 'Admin', 'description' => 'Operational administrator'],
            'viewer' => ['name' => 'Viewer', 'description' => 'Read-only access'],
        ];

        $result = $this->generator->generateRoleSeeder($roles);

        $this->assertStringContainsString('namespace Database\Seeders', $result);
        $this->assertStringContainsString('class RoleSeeder extends Seeder', $result);
        $this->assertStringContainsString("Role::firstOrCreate", $result);
        $this->assertStringContainsString("'slug' => 'owner'", $result);
        $this->assertStringContainsString("'name' => 'Owner'", $result);
        $this->assertStringContainsString("'slug' => 'admin'", $result);
        $this->assertStringContainsString("'slug' => 'viewer'", $result);
        $this->assertStringContainsString('Rhino Blueprint', $result);
    }

    public function test_role_seeder_has_valid_php_syntax(): void
    {
        $roles = [
            'owner' => ['name' => 'Owner', 'description' => 'Full access'],
            'admin' => ['name' => 'Admin', 'description' => 'Administrator'],
        ];

        $result = $this->generator->generateRoleSeeder($roles);

        $tmpFile = tempnam(sys_get_temp_dir(), 'seeder_') . '.php';
        file_put_contents($tmpFile, $result);

        $output = [];
        $exitCode = 0;
        exec("php -l {$tmpFile} 2>&1", $output, $exitCode);

        unlink($tmpFile);

        $this->assertEquals(0, $exitCode, "Generated seeder has PHP syntax errors:\n" . implode("\n", $output));
    }

    // ---------------------------------------------------------------
    // generateUserRoleSeeder() tests
    // ---------------------------------------------------------------

    public function test_generates_user_role_seeder(): void
    {
        $roles = [
            'owner' => ['name' => 'Owner', 'description' => ''],
            'admin' => ['name' => 'Admin', 'description' => ''],
        ];

        $aggregatedPermissions = [
            'owner' => ['*'],
            'admin' => ['contracts.*', 'alerts.index', 'alerts.show'],
        ];

        $result = $this->generator->generateUserRoleSeeder($roles, $aggregatedPermissions);

        $this->assertStringContainsString('namespace Database\Seeders', $result);
        $this->assertStringContainsString('class UserRoleSeeder extends Seeder', $result);
        $this->assertStringContainsString("Organization::firstOrCreate", $result);
        $this->assertStringContainsString("User::factory()->create", $result);
        $this->assertStringContainsString("UserRole::firstOrCreate", $result);

        // Owner gets wildcard
        $this->assertStringContainsString("['*']", $result);

        // Admin gets specific permissions
        $this->assertStringContainsString("'contracts.*'", $result);
        $this->assertStringContainsString("'alerts.index'", $result);
    }

    public function test_user_role_seeder_has_valid_php_syntax(): void
    {
        $roles = [
            'owner' => ['name' => 'Owner', 'description' => ''],
            'admin' => ['name' => 'Admin', 'description' => ''],
            'viewer' => ['name' => 'Viewer', 'description' => ''],
        ];

        $aggregatedPermissions = [
            'owner' => ['*'],
            'admin' => ['contracts.*', 'alerts.index', 'alerts.show', 'alerts.update'],
            'viewer' => ['contracts.index', 'contracts.show', 'alerts.index', 'alerts.show'],
        ];

        $result = $this->generator->generateUserRoleSeeder($roles, $aggregatedPermissions);

        $tmpFile = tempnam(sys_get_temp_dir(), 'userrole_') . '.php';
        file_put_contents($tmpFile, $result);

        $output = [];
        $exitCode = 0;
        exec("php -l {$tmpFile} 2>&1", $output, $exitCode);

        unlink($tmpFile);

        $this->assertEquals(0, $exitCode, "Generated UserRoleSeeder has PHP syntax errors:\n" . implode("\n", $output));
    }

    // ---------------------------------------------------------------
    // generateUserPermissionSeeder() tests (non-tenant)
    // ---------------------------------------------------------------

    public function test_generates_user_permission_seeder(): void
    {
        $roles = [
            'admin' => ['name' => 'Admin', 'description' => 'Full access'],
            'viewer' => ['name' => 'Viewer', 'description' => 'Read-only'],
        ];

        $aggregatedPermissions = [
            'admin' => ['contracts.*', 'alerts.index', 'alerts.show'],
            'viewer' => ['contracts.index', 'contracts.show'],
        ];

        $result = $this->generator->generateUserPermissionSeeder($roles, $aggregatedPermissions);

        // Check class structure
        $this->assertStringContainsString('namespace Database\Seeders', $result);
        $this->assertStringContainsString('class UserPermissionSeeder extends Seeder', $result);

        // Check NO multi-tenant models imported
        $this->assertStringNotContainsString('use App\Models\Organization', $result);
        $this->assertStringNotContainsString('use App\Models\Role', $result);
        $this->assertStringNotContainsString('use App\Models\UserRole', $result);

        // Check permissions stored directly on User
        $this->assertStringContainsString('use App\Models\User', $result);
        $this->assertStringContainsString("User::factory()->create", $result);
        $this->assertStringContainsString("'permissions' =>", $result);

        // Check specific user creation
        $this->assertStringContainsString("'name' => 'Admin User'", $result);
        $this->assertStringContainsString("'email' => 'admin@example.com'", $result);
        $this->assertStringContainsString("'contracts.*'", $result);

        $this->assertStringContainsString("'name' => 'Viewer User'", $result);
        $this->assertStringContainsString("'email' => 'viewer@example.com'", $result);
        $this->assertStringContainsString("'contracts.index'", $result);

        // Check NO Organization::firstOrCreate
        $this->assertStringNotContainsString('Organization::firstOrCreate', $result);
        $this->assertStringNotContainsString('UserRole::firstOrCreate', $result);
    }

    public function test_user_permission_seeder_has_valid_php_syntax(): void
    {
        $roles = [
            'owner' => ['name' => 'Owner', 'description' => ''],
            'admin' => ['name' => 'Admin', 'description' => ''],
            'viewer' => ['name' => 'Viewer', 'description' => ''],
        ];

        $aggregatedPermissions = [
            'owner' => ['*'],
            'admin' => ['contracts.*', 'alerts.index', 'alerts.show', 'alerts.update'],
            'viewer' => ['contracts.index', 'contracts.show', 'alerts.index', 'alerts.show'],
        ];

        $result = $this->generator->generateUserPermissionSeeder($roles, $aggregatedPermissions);

        $tmpFile = tempnam(sys_get_temp_dir(), 'userperm_') . '.php';
        file_put_contents($tmpFile, $result);

        $output = [];
        $exitCode = 0;
        exec("php -l {$tmpFile} 2>&1", $output, $exitCode);

        unlink($tmpFile);

        $this->assertEquals(0, $exitCode, "Generated UserPermissionSeeder has PHP syntax errors:\n" . implode("\n", $output));
    }
}
