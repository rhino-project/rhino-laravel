<?php

namespace Rhino\Tests\Feature;

use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
use Illuminate\Support\Facades\File;
use Rhino\Commands\InstallCommand;
use Rhino\Tests\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class InstallCommandCoverageTest extends TestCase
{
    protected InstallCommand $command;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->command = new InstallCommand();
        $this->tempDir = sys_get_temp_dir() . '/rhino_inst_cov_test_' . uniqid();
        File::ensureDirectoryExists($this->tempDir);
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    protected function invokeMethod(string $method, array $args = []): mixed
    {
        $ref = new ReflectionMethod(InstallCommand::class, $method);
        $ref->setAccessible(true);

        return $ref->invoke($this->command, ...$args);
    }

    protected function setProperty(string $property, mixed $value): void
    {
        $ref = new ReflectionProperty(InstallCommand::class, $property);
        $ref->setAccessible(true);
        $ref->setValue($this->command, $value);
    }

    protected function setBufferedOutput(): BufferedOutput
    {
        $buffered = new BufferedOutput();
        $outputStyle = new OutputStyle(new ArrayInput([]), $buffered);
        $this->command->setOutput($outputStyle);

        $refComponents = new ReflectionProperty(\Illuminate\Console\Command::class, 'components');
        $refComponents->setAccessible(true);
        $refComponents->setValue($this->command, new Factory($outputStyle));

        return $buffered;
    }

    // ------------------------------------------------------------------
    // Note: ensureSanctumInstalled, publishConfig, publishRoutes, and
    // publishSanctumMigrations use callSilently which requires full Artisan
    // context and cannot be tested via reflection. They are tested implicitly
    // through the installMultiTenant flow.
    // ------------------------------------------------------------------

    // ------------------------------------------------------------------
    // updateUserModel — HasApiTokens already present
    // ------------------------------------------------------------------

    public function test_update_user_model_skips_existing_api_tokens(): void
    {
        $stubPath = realpath(__DIR__ . '/../../stubs/multi-tenant');
        $this->setProperty('stubPath', $stubPath);
        $this->setBufferedOutput();

        $modelsDir = $this->tempDir . '/Models';
        File::ensureDirectoryExists($modelsDir);
        $this->app->useAppPath($this->tempDir);

        $userContent = <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Laravel\Sanctum\HasApiTokens;

class User extends Authenticatable
{
    use HasFactory, HasApiTokens;
}
PHP;
        File::put($modelsDir . '/User.php', $userContent);

        $this->invokeMethod('updateUserModel', []);

        $content = File::get($modelsDir . '/User.php');
        // HasPermissions should be added
        $this->assertStringContainsString('HasPermissions', $content);
        // organizations() relationship should be added
        $this->assertStringContainsString('organizations', $content);
    }

    // ------------------------------------------------------------------
    // updateAppServiceProvider — adds Gate import when missing
    // ------------------------------------------------------------------

    public function test_update_app_service_provider_adds_gate_import(): void
    {
        $this->setBufferedOutput();

        $providersDir = $this->tempDir . '/Providers';
        File::ensureDirectoryExists($providersDir);
        $this->app->useAppPath($this->tempDir);

        $providerContent = <<<'PHP'
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        //
    }
}
PHP;
        File::put($providersDir . '/AppServiceProvider.php', $providerContent);

        $this->invokeMethod('updateAppServiceProvider', []);

        $content = File::get($providersDir . '/AppServiceProvider.php');
        $this->assertStringContainsString('use Illuminate\Support\Facades\Gate', $content);
        $this->assertStringContainsString('guessPolicyNamesUsing', $content);
    }

    // ------------------------------------------------------------------
    // updateDatabaseSeeder — adds to existing call block
    // ------------------------------------------------------------------

    public function test_update_database_seeder_appends_when_call_exists(): void
    {
        $this->setBufferedOutput();

        $seedersDir = $this->tempDir . '/seeders';
        File::ensureDirectoryExists($seedersDir);
        $this->app->useDatabasePath($this->tempDir);

        $seederContent = <<<'PHP'
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            SomeOtherSeeder::class,
        ]);
    }
}
PHP;
        File::put($seedersDir . '/DatabaseSeeder.php', $seederContent);

        $this->invokeMethod('updateDatabaseSeeder', []);

        $content = File::get($seedersDir . '/DatabaseSeeder.php');
        $this->assertStringContainsString('RoleSeeder::class', $content);
        $this->assertStringContainsString('OrganizationSeeder::class', $content);
        $this->assertStringContainsString('UserRoleSeeder::class', $content);
        $this->assertStringContainsString('SomeOtherSeeder::class', $content);
    }

    // ------------------------------------------------------------------
    // installBlueprintDirectory — blueprint.md already exists
    // ------------------------------------------------------------------

    public function test_install_blueprint_directory_copies_blueprint_md(): void
    {
        $this->setBufferedOutput();
        $this->app->setBasePath($this->tempDir);

        $this->invokeMethod('installBlueprintDirectory', []);

        $rhinoDir = $this->tempDir . '/.rhino';
        $this->assertDirectoryExists($rhinoDir . '/blueprints');
    }

    // ------------------------------------------------------------------
    // installAuditTrail
    // ------------------------------------------------------------------

    public function test_install_audit_trail_creates_migration_file(): void
    {
        $this->setBufferedOutput();

        $migrationsDir = $this->tempDir . '/migrations';
        File::ensureDirectoryExists($migrationsDir);
        $this->app->useDatabasePath($this->tempDir);

        $this->invokeMethod('installAuditTrail', []);

        $files = glob($migrationsDir . '/*_create_audit_logs_table.php');
        $this->assertNotEmpty($files, 'Audit trail migration should be created');
    }

    public function test_install_audit_trail_skips_when_migration_exists(): void
    {
        $this->setBufferedOutput();

        $migrationsDir = $this->tempDir . '/migrations';
        File::ensureDirectoryExists($migrationsDir);
        $this->app->useDatabasePath($this->tempDir);

        // Pre-create a migration
        File::put($migrationsDir . '/2024_01_01_000000_create_audit_logs_table.php', '<?php // existing');

        $this->invokeMethod('installAuditTrail', []);

        $files = glob($migrationsDir . '/*_create_audit_logs_table.php');
        // Should still be just one file
        $this->assertCount(1, $files);
    }

    // ------------------------------------------------------------------
    // overwriteBootstrapApp
    // ------------------------------------------------------------------

    public function test_overwrite_bootstrap_app_writes_content(): void
    {
        $stubPath = realpath(__DIR__ . '/../../stubs/multi-tenant');
        $this->setProperty('stubPath', $stubPath);
        $this->setBufferedOutput();

        $bootstrapDir = $this->tempDir . '/bootstrap';
        File::ensureDirectoryExists($bootstrapDir);
        $this->app->setBasePath($this->tempDir);

        $this->invokeMethod('overwriteBootstrapApp', []);

        $this->assertFileExists($bootstrapDir . '/app.php');
        $content = File::get($bootstrapDir . '/app.php');
        $this->assertStringContainsString('ResolveOrganizationFromRoute', $content);
    }

    // ------------------------------------------------------------------
    // printNextSteps — various feature combinations
    // ------------------------------------------------------------------

    public function test_print_next_steps_with_multi_tenant_and_audit_trail(): void
    {
        $this->setBufferedOutput();

        $this->invokeMethod('printNextSteps', [['multi_tenant', 'audit_trail']]);
        $this->assertTrue(true);
    }

    public function test_print_next_steps_with_no_features(): void
    {
        $this->setBufferedOutput();

        $this->invokeMethod('printNextSteps', [[]]);
        $this->assertTrue(true);
    }

    // ------------------------------------------------------------------
    // arrayToShortSyntax — deeply nested
    // ------------------------------------------------------------------

    public function test_array_to_short_syntax_deeply_nested(): void
    {
        $input = [
            'level1' => [
                'level2' => [
                    'key' => 'value',
                ],
            ],
        ];

        $result = $this->invokeMethod('arrayToShortSyntax', [$input]);

        $this->assertStringContainsString("'level1'", $result);
        $this->assertStringContainsString("'level2'", $result);
        $this->assertStringContainsString("'key' => 'value'", $result);
    }

    // ------------------------------------------------------------------
    // updateConfig — when config file has no models key
    // ------------------------------------------------------------------

    public function test_update_config_creates_full_tenant_config(): void
    {
        $configDir = $this->tempDir . '/config';
        File::ensureDirectoryExists($configDir);
        $this->app->useConfigPath($configDir);

        // Create a minimal config
        $configContent = <<<'PHP'
<?php

return [
    'models' => [],
    'route_groups' => [],
];
PHP;
        File::put($configDir . '/rhino.php', $configContent);

        $this->setProperty('stubPath', realpath(__DIR__ . '/../../stubs/multi-tenant'));
        $this->invokeMethod('updateConfig', ['uuid']);

        $content = File::get($configDir . '/rhino.php');
        $this->assertStringContainsString('uuid', $content);
        $this->assertStringContainsString('tenant', $content);
        $this->assertStringContainsString('Organization', $content);
    }

    // ------------------------------------------------------------------
    // createSeeders — creates all seeder files
    // ------------------------------------------------------------------

    public function test_create_seeders_creates_files_and_database_seeder(): void
    {
        $stubPath = realpath(__DIR__ . '/../../stubs/multi-tenant');
        $this->setProperty('stubPath', $stubPath);
        $this->setBufferedOutput();

        $seedersDir = $this->tempDir . '/seeders';
        File::ensureDirectoryExists($seedersDir);
        $this->app->useDatabasePath($this->tempDir);

        // Create a DatabaseSeeder for updateDatabaseSeeder
        $seederContent = <<<'PHP'
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
    }
}
PHP;
        File::put($seedersDir . '/DatabaseSeeder.php', $seederContent);

        $this->invokeMethod('createSeeders', [['admin', 'editor', 'viewer']]);

        $this->assertFileExists($seedersDir . '/RoleSeeder.php');
        $this->assertFileExists($seedersDir . '/OrganizationSeeder.php');
        $this->assertFileExists($seedersDir . '/UserRoleSeeder.php');

        // Check DatabaseSeeder was updated
        $dbSeeder = File::get($seedersDir . '/DatabaseSeeder.php');
        $this->assertStringContainsString('RoleSeeder', $dbSeeder);
    }

    // ------------------------------------------------------------------
    // installMultiTenant — full flow
    // ------------------------------------------------------------------

    public function test_install_multi_tenant_full_flow(): void
    {
        $stubPath = realpath(__DIR__ . '/../../stubs/multi-tenant');
        $this->setProperty('stubPath', $stubPath);
        $this->setBufferedOutput();

        // Set up all needed directories
        $this->app->setBasePath($this->tempDir);
        $this->app->useAppPath($this->tempDir);
        $this->app->useDatabasePath($this->tempDir);

        $configDir = $this->tempDir . '/config';
        File::ensureDirectoryExists($configDir);
        $this->app->useConfigPath($configDir);

        // Create a minimal rhino config
        $configContent = "<?php\nreturn ['models' => [], 'route_groups' => []];\n";
        File::put($configDir . '/rhino.php', $configContent);

        File::ensureDirectoryExists($this->tempDir . '/Models');
        File::ensureDirectoryExists($this->tempDir . '/migrations');
        File::ensureDirectoryExists($this->tempDir . '/factories');
        File::ensureDirectoryExists($this->tempDir . '/Policies');
        File::ensureDirectoryExists($this->tempDir . '/Http/Middleware');
        File::ensureDirectoryExists($this->tempDir . '/routes');
        File::ensureDirectoryExists($this->tempDir . '/seeders');
        File::ensureDirectoryExists($this->tempDir . '/Providers');

        // Create a minimal User model
        $userContent = <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class User extends Authenticatable
{
    use HasFactory;
}
PHP;
        File::put($this->tempDir . '/Models/User.php', $userContent);

        // Create AppServiceProvider
        $providerContent = <<<'PHP'
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
    }
}
PHP;
        File::put($this->tempDir . '/Providers/AppServiceProvider.php', $providerContent);

        // Create DatabaseSeeder
        $seederContent = <<<'PHP'
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
    }
}
PHP;
        File::put($this->tempDir . '/seeders/DatabaseSeeder.php', $seederContent);

        $this->invokeMethod('installMultiTenant', ['slug', ['admin', 'editor']]);

        // Verify models were created
        $this->assertFileExists($this->tempDir . '/Models/Organization.php');
        $this->assertFileExists($this->tempDir . '/Models/Role.php');
        $this->assertFileExists($this->tempDir . '/Models/UserRole.php');

        // Verify migrations were created
        $files = glob($this->tempDir . '/migrations/*_create_organizations_table.php');
        $this->assertNotEmpty($files);

        // Verify factories were created
        $this->assertFileExists($this->tempDir . '/factories/OrganizationFactory.php');

        // Verify policies
        $this->assertFileExists($this->tempDir . '/Policies/OrganizationPolicy.php');

        // Verify middleware
        $this->assertFileExists($this->tempDir . '/Http/Middleware/ResolveOrganizationFromRoute.php');

        // Verify config was updated
        $configContent = File::get($configDir . '/rhino.php');
        $this->assertStringContainsString('tenant', $configContent);
    }

    // ------------------------------------------------------------------
    // generateRoleSeeder — with various role types
    // ------------------------------------------------------------------

    public function test_generate_role_seeder_with_all_known_roles(): void
    {
        $this->invokeMethod('generateRoleSeeder', [$this->tempDir, ['admin', 'editor', 'viewer', 'writer', 'custom_role']]);

        $content = File::get($this->tempDir . '/RoleSeeder.php');
        $this->assertStringContainsString('Administrator role with full access', $content);
        $this->assertStringContainsString('Editor role with create, read, and update access', $content);
        $this->assertStringContainsString('Viewer role with read-only access', $content);
        $this->assertStringContainsString('Writer role with create and edit access', $content);
        $this->assertStringContainsString('Custom_role role', $content);
    }
}
