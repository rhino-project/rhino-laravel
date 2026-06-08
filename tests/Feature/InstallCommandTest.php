<?php

namespace Rhino\Tests\Feature;

use Illuminate\Support\Facades\File;
use Rhino\Commands\InstallCommand;
use Rhino\Tests\TestCase;
use ReflectionMethod;

class InstallCommandTest extends TestCase
{
    protected InstallCommand $command;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->command = new InstallCommand();
        $this->tempDir = sys_get_temp_dir() . '/rhino_install_test_' . uniqid();
        File::ensureDirectoryExists($this->tempDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    /**
     * Invoke a protected/private method on the command instance.
     */
    protected function invokeMethod(string $method, array $args = []): mixed
    {
        $ref = new ReflectionMethod(InstallCommand::class, $method);
        $ref->setAccessible(true);

        return $ref->invoke($this->command, ...$args);
    }

    /**
     * Set a protected property on the command instance.
     */
    protected function setProperty(string $property, mixed $value): void
    {
        $ref = new \ReflectionProperty(InstallCommand::class, $property);
        $ref->setAccessible(true);
        $ref->setValue($this->command, $value);
    }

    // ------------------------------------------------------------------
    // arrayToShortSyntax
    // ------------------------------------------------------------------

    public function test_array_to_short_syntax_with_simple_array(): void
    {
        $input = [
            'key1' => 'value1',
            'key2' => 'value2',
        ];

        $result = $this->invokeMethod('arrayToShortSyntax', [$input]);

        $this->assertStringContainsString("'key1' => 'value1',", $result);
        $this->assertStringContainsString("'key2' => 'value2',", $result);
        $this->assertStringStartsWith('[', $result);
        $this->assertStringEndsWith(']', $result);
    }

    public function test_array_to_short_syntax_with_nested_array(): void
    {
        $input = [
            'parent' => [
                'child1' => 'a',
                'child2' => 'b',
            ],
        ];

        $result = $this->invokeMethod('arrayToShortSyntax', [$input]);

        $this->assertStringContainsString("'parent' => [", $result);
        $this->assertStringContainsString("'child1' => 'a',", $result);
        $this->assertStringContainsString("'child2' => 'b',", $result);

        // Verify proper indentation: child keys should be indented deeper than parent
        $lines = explode("\n", $result);
        $parentIndent = null;
        $childIndent = null;

        foreach ($lines as $line) {
            if (str_contains($line, "'parent'")) {
                $parentIndent = strlen($line) - strlen(ltrim($line));
            }
            if (str_contains($line, "'child1'")) {
                $childIndent = strlen($line) - strlen(ltrim($line));
            }
        }

        $this->assertNotNull($parentIndent);
        $this->assertNotNull($childIndent);
        $this->assertGreaterThan($parentIndent, $childIndent);
    }

    public function test_array_to_short_syntax_with_class_references(): void
    {
        $input = [
            'models' => [
                'organizations' => 'App\Models\Organization',
                'roles' => 'App\Models\Role',
            ],
        ];

        $result = $this->invokeMethod('arrayToShortSyntax', [$input]);

        // Class-like strings (PascalCase with backslashes) should be rendered as ::class
        $this->assertStringContainsString('\App\Models\Organization::class', $result);
        $this->assertStringContainsString('\App\Models\Role::class', $result);

        // They should NOT be wrapped in quotes
        $this->assertStringNotContainsString("'App\\Models\\Organization'", $result);
        $this->assertStringNotContainsString("'App\\Models\\Role'", $result);
    }

    public function test_array_to_short_syntax_with_boolean_and_null_values(): void
    {
        $input = [
            'enabled' => true,
            'disabled' => false,
            'nothing' => null,
        ];

        $result = $this->invokeMethod('arrayToShortSyntax', [$input]);

        $this->assertStringContainsString("'enabled' => true,", $result);
        $this->assertStringContainsString("'disabled' => false,", $result);
        $this->assertStringContainsString("'nothing' => null,", $result);
    }

    public function test_array_to_short_syntax_with_empty_array(): void
    {
        $result = $this->invokeMethod('arrayToShortSyntax', [[]]);

        $this->assertSame('[]', $result);
    }

    // ------------------------------------------------------------------
    // generateRoleSeeder
    // ------------------------------------------------------------------

    public function test_generate_role_seeder_creates_correct_content(): void
    {
        $roles = ['admin', 'editor', 'viewer'];

        $this->invokeMethod('generateRoleSeeder', [$this->tempDir, $roles]);

        $seederPath = $this->tempDir . '/RoleSeeder.php';
        $this->assertFileExists($seederPath);

        $content = File::get($seederPath);

        // Verify PHP namespace and class structure
        $this->assertStringContainsString('namespace Database\Seeders;', $content);
        $this->assertStringContainsString('use App\Models\Role;', $content);
        $this->assertStringContainsString('use Illuminate\Database\Seeder;', $content);
        $this->assertStringContainsString('class RoleSeeder extends Seeder', $content);
        $this->assertStringContainsString('public function run(): void', $content);

        // Verify each role is seeded with firstOrCreate
        $this->assertStringContainsString("['slug' => 'admin']", $content);
        $this->assertStringContainsString("'name' => 'Admin'", $content);
        $this->assertStringContainsString("'description' => 'Administrator role with full access'", $content);

        $this->assertStringContainsString("['slug' => 'editor']", $content);
        $this->assertStringContainsString("'name' => 'Editor'", $content);
        $this->assertStringContainsString("'description' => 'Editor role with create, read, and update access'", $content);

        $this->assertStringContainsString("['slug' => 'viewer']", $content);
        $this->assertStringContainsString("'name' => 'Viewer'", $content);
        $this->assertStringContainsString("'description' => 'Viewer role with read-only access'", $content);
    }

    // ------------------------------------------------------------------
    // createMigrations
    // ------------------------------------------------------------------

    public function test_create_migrations_copies_stub_files(): void
    {
        $stubPath = realpath(__DIR__ . '/../../stubs/multi-tenant');
        $this->setProperty('stubPath', $stubPath);

        // Point database_path to our temp dir so migrations go there
        $migrationsDir = $this->tempDir . '/migrations';
        File::ensureDirectoryExists($migrationsDir);

        $this->app->useStoragePath($this->tempDir);
        $this->app->useDatabasePath($this->tempDir);

        $this->invokeMethod('createMigrations', []);

        // List files in the migrations dir
        $files = File::files($migrationsDir);
        $fileNames = array_map(fn ($f) => $f->getFilename(), $files);

        // There should be four migration files
        $this->assertCount(4, $files);

        // Check file naming pattern (timestamp prefix + descriptive suffix)
        $orgMigration = collect($fileNames)->first(fn ($n) => str_contains($n, 'create_organizations_table'));
        $roleMigration = collect($fileNames)->first(fn ($n) => str_contains($n, 'create_roles_table'));
        $userRoleMigration = collect($fileNames)->first(fn ($n) => str_contains($n, 'create_user_roles_table'));
        $orgRolePermMigration = collect($fileNames)->first(fn ($n) => str_contains($n, 'create_org_role_permissions_table'));

        $this->assertNotNull($orgMigration, 'Organizations migration file should exist');
        $this->assertNotNull($roleMigration, 'Roles migration file should exist');
        $this->assertNotNull($userRoleMigration, 'User roles migration file should exist');
        $this->assertNotNull($orgRolePermMigration, 'Org role permissions migration file should exist');

        // Verify the content is copied from the stubs (not empty)
        foreach ($files as $file) {
            $this->assertNotEmpty(File::get($file->getPathname()));
        }
    }

    // ------------------------------------------------------------------
    // createModels
    // ------------------------------------------------------------------

    public function test_create_models_creates_organization_role_userrole(): void
    {
        $stubPath = realpath(__DIR__ . '/../../stubs/multi-tenant');
        $this->setProperty('stubPath', $stubPath);

        $modelsDir = $this->tempDir . '/Models';
        File::ensureDirectoryExists($modelsDir);

        // Override app_path so the command writes into our temp directory
        $this->app->useAppPath($this->tempDir);

        $roles = ['admin', 'editor'];
        $this->invokeMethod('createModels', [$roles]);

        // Verify Organization.php was copied
        $this->assertFileExists($modelsDir . '/Organization.php');
        $orgContent = File::get($modelsDir . '/Organization.php');
        $this->assertNotEmpty($orgContent);

        // Verify Role.php was created with the roles array injected
        $this->assertFileExists($modelsDir . '/Role.php');
        $roleContent = File::get($modelsDir . '/Role.php');
        $this->assertStringContainsString("'admin'", $roleContent);
        $this->assertStringContainsString("'editor'", $roleContent);

        // Verify UserRole.php was copied (now with grant/deny delta columns)
        $this->assertFileExists($modelsDir . '/UserRole.php');
        $userRoleContent = File::get($modelsDir . '/UserRole.php');
        $this->assertNotEmpty($userRoleContent);
        $this->assertStringContainsString('granted_permissions', $userRoleContent);
        $this->assertStringContainsString('denied_permissions', $userRoleContent);

        // Verify OrgRolePermission.php (the shared role layer) was copied
        $this->assertFileExists($modelsDir . '/OrgRolePermission.php');
        $this->assertNotEmpty(File::get($modelsDir . '/OrgRolePermission.php'));
    }

    // ------------------------------------------------------------------
    // createFactories
    // ------------------------------------------------------------------

    public function test_create_factories_copies_factory_files(): void
    {
        $stubPath = realpath(__DIR__ . '/../../stubs/multi-tenant');
        $this->setProperty('stubPath', $stubPath);

        $factoriesDir = $this->tempDir . '/factories';
        File::ensureDirectoryExists($factoriesDir);

        $this->app->useDatabasePath($this->tempDir);

        $this->invokeMethod('createFactories', []);

        $this->assertFileExists($factoriesDir . '/OrganizationFactory.php');
        $this->assertFileExists($factoriesDir . '/RoleFactory.php');
        $this->assertFileExists($factoriesDir . '/UserRoleFactory.php');

        // Verify content is not empty
        $this->assertNotEmpty(File::get($factoriesDir . '/OrganizationFactory.php'));
        $this->assertNotEmpty(File::get($factoriesDir . '/RoleFactory.php'));
        $this->assertNotEmpty(File::get($factoriesDir . '/UserRoleFactory.php'));
    }

    // ------------------------------------------------------------------
    // createPolicies
    // ------------------------------------------------------------------

    public function test_create_policies_copies_policy_files(): void
    {
        $stubPath = realpath(__DIR__ . '/../../stubs/multi-tenant');
        $this->setProperty('stubPath', $stubPath);

        $policiesDir = $this->tempDir . '/Policies';
        File::ensureDirectoryExists($policiesDir);

        $this->app->useAppPath($this->tempDir);

        $this->invokeMethod('createPolicies', []);

        $this->assertFileExists($policiesDir . '/OrganizationPolicy.php');
        $this->assertFileExists($policiesDir . '/RolePolicy.php');
        $this->assertFileExists($policiesDir . '/UserPolicy.php');

        // Verify content is not empty
        $this->assertNotEmpty(File::get($policiesDir . '/OrganizationPolicy.php'));
        $this->assertNotEmpty(File::get($policiesDir . '/RolePolicy.php'));
        $this->assertNotEmpty(File::get($policiesDir . '/UserPolicy.php'));

        // UserPolicy must declare the 'users' slug so include-authorization
        // (?include=assignee/author/owner pointing at User) can resolve.
        $this->assertStringContainsString(
            "\$resourceSlug = 'users'",
            File::get($policiesDir . '/UserPolicy.php')
        );
    }
}
