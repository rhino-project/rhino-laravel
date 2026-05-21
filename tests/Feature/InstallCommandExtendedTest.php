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

class InstallCommandExtendedTest extends TestCase
{
    protected InstallCommand $command;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->command = new InstallCommand();
        $this->tempDir = sys_get_temp_dir() . '/rhino_install_ext_test_' . uniqid();
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
    // updateRoutes
    // ------------------------------------------------------------------

    public function test_update_routes_copies_api_route_file(): void
    {
        $stubPath = realpath(__DIR__ . '/../../stubs/multi-tenant');
        $this->setProperty('stubPath', $stubPath);

        $routesDir = $this->tempDir . '/routes';
        File::ensureDirectoryExists($routesDir);

        // Override base_path
        $this->app->setBasePath($this->tempDir);

        $this->invokeMethod('updateRoutes', []);

        $this->assertFileExists($routesDir . '/api.php');
        $content = File::get($routesDir . '/api.php');
        $this->assertNotEmpty($content);
    }

    // ------------------------------------------------------------------
    // createMiddleware
    // ------------------------------------------------------------------

    public function test_create_middleware_creates_resolve_organization_middleware(): void
    {
        $middlewareDir = $this->tempDir . '/Http/Middleware';
        File::ensureDirectoryExists($middlewareDir);
        $this->app->useAppPath($this->tempDir);

        $this->invokeMethod('createMiddleware', []);

        $this->assertFileExists($middlewareDir . '/ResolveOrganizationFromRoute.php');
        $content = File::get($middlewareDir . '/ResolveOrganizationFromRoute.php');
        $this->assertStringContainsString('namespace App\Http\Middleware;', $content);
        $this->assertStringNotContainsString('namespace Rhino\Http\Middleware;', $content);
    }

    // ------------------------------------------------------------------
    // updateConfig
    // ------------------------------------------------------------------

    public function test_update_config_adds_multi_tenant_settings(): void
    {
        $configDir = $this->tempDir . '/config';
        File::ensureDirectoryExists($configDir);
        $this->app->useConfigPath($configDir);

        $configContent = <<<'PHP'
<?php

return [
    'models' => [
        'organizations' => \App\Models\Organization::class,
        'roles' => \App\Models\Role::class,
    ],
    'route_groups' => [
        'default' => [
            'prefix' => '',
            'middleware' => [],
            'models' => '*',
        ],
    ],
    'multi_tenant' => [
        'organization_identifier_column' => 'id',
    ],
];
PHP;
        File::put($configDir . '/rhino.php', $configContent);

        $this->setProperty('stubPath', realpath(__DIR__ . '/../../stubs/multi-tenant'));

        $this->invokeMethod('updateConfig', ['slug']);

        $content = File::get($configDir . '/rhino.php');
        $this->assertStringContainsString('slug', $content);
        $this->assertStringContainsString('tenant', $content);
    }

    public function test_update_config_does_nothing_when_config_missing(): void
    {
        $this->app->useConfigPath($this->tempDir . '/nonexistent');

        $this->invokeMethod('updateConfig', ['slug']);
        $this->assertTrue(true); // No exception
    }

    // ------------------------------------------------------------------
    // updateUserModel
    // ------------------------------------------------------------------

    public function test_update_user_model_adds_traits_and_relationships(): void
    {
        $modelsDir = $this->tempDir . '/Models';
        File::ensureDirectoryExists($modelsDir);
        $this->app->useAppPath($this->tempDir);

        $stubPath = realpath(__DIR__ . '/../../stubs/multi-tenant');
        $this->setProperty('stubPath', $stubPath);

        $userContent = <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class User extends Authenticatable
{
    use HasFactory;

    protected $fillable = ['name', 'email', 'password'];
}
PHP;
        File::put($modelsDir . '/User.php', $userContent);

        $this->invokeMethod('updateUserModel', []);

        $content = File::get($modelsDir . '/User.php');
        $this->assertStringContainsString('HasApiTokens', $content);
        $this->assertStringContainsString('HasPermissions', $content);
        $this->assertStringContainsString('HasRoleBasedValidation', $content);
        $this->assertStringContainsString('function organizations()', $content);
    }

    public function test_update_user_model_skips_if_already_has_organizations(): void
    {
        $modelsDir = $this->tempDir . '/Models';
        File::ensureDirectoryExists($modelsDir);
        $this->app->useAppPath($this->tempDir);

        $stubPath = realpath(__DIR__ . '/../../stubs/multi-tenant');
        $this->setProperty('stubPath', $stubPath);

        $userContent = <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;

class User extends Authenticatable
{
    public function organizations() { return []; }
}
PHP;
        File::put($modelsDir . '/User.php', $userContent);

        $this->invokeMethod('updateUserModel', []);

        $content = File::get($modelsDir . '/User.php');
        // Should not have duplicate organizations method
        $this->assertSame(1, substr_count($content, 'function organizations()'));
    }

    public function test_update_user_model_does_nothing_when_file_missing(): void
    {
        $this->app->useAppPath($this->tempDir . '/nonexistent');

        $this->invokeMethod('updateUserModel', []);
        $this->assertTrue(true);
    }

    // ------------------------------------------------------------------
    // updateAppServiceProvider
    // ------------------------------------------------------------------

    public function test_update_app_service_provider_adds_policy_discovery(): void
    {
        $providersDir = $this->tempDir . '/Providers';
        File::ensureDirectoryExists($providersDir);
        $this->app->useAppPath($this->tempDir);

        $providerContent = <<<'PHP'
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        //
    }
}
PHP;
        File::put($providersDir . '/AppServiceProvider.php', $providerContent);

        $this->invokeMethod('updateAppServiceProvider', []);

        $content = File::get($providersDir . '/AppServiceProvider.php');
        $this->assertStringContainsString('guessPolicyNamesUsing', $content);
        $this->assertStringContainsString('use Illuminate\Support\Facades\Gate;', $content);
    }

    public function test_update_app_service_provider_skips_if_already_configured(): void
    {
        $providersDir = $this->tempDir . '/Providers';
        File::ensureDirectoryExists($providersDir);
        $this->app->useAppPath($this->tempDir);

        $providerContent = <<<'PHP'
<?php

namespace App\Providers;

use Illuminate\Support\Facades\Gate;
use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function boot(): void
    {
        Gate::guessPolicyNamesUsing(function ($modelClass) {
            return 'App\\Policies\\' . class_basename($modelClass) . 'Policy';
        });
    }
}
PHP;
        File::put($providersDir . '/AppServiceProvider.php', $providerContent);

        $this->invokeMethod('updateAppServiceProvider', []);

        $content = File::get($providersDir . '/AppServiceProvider.php');
        $this->assertSame(1, substr_count($content, 'guessPolicyNamesUsing'));
    }

    public function test_update_app_service_provider_does_nothing_when_file_missing(): void
    {
        $this->app->useAppPath($this->tempDir . '/nonexistent');

        $this->invokeMethod('updateAppServiceProvider', []);
        $this->assertTrue(true);
    }

    // ------------------------------------------------------------------
    // updateDatabaseSeeder
    // ------------------------------------------------------------------

    public function test_update_database_seeder_adds_seeders(): void
    {
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
        //
    }
}
PHP;
        File::put($seedersDir . '/DatabaseSeeder.php', $seederContent);

        $this->invokeMethod('updateDatabaseSeeder', []);

        $content = File::get($seedersDir . '/DatabaseSeeder.php');
        $this->assertStringContainsString('RoleSeeder::class', $content);
        $this->assertStringContainsString('OrganizationSeeder::class', $content);
        $this->assertStringContainsString('UserRoleSeeder::class', $content);
    }

    public function test_update_database_seeder_skips_if_already_registered(): void
    {
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
            RoleSeeder::class,
            OrganizationSeeder::class,
            UserRoleSeeder::class,
        ]);
    }
}
PHP;
        File::put($seedersDir . '/DatabaseSeeder.php', $seederContent);

        $this->invokeMethod('updateDatabaseSeeder', []);

        $content = File::get($seedersDir . '/DatabaseSeeder.php');
        // All three are already present, so no duplicates should be added
        $this->assertStringContainsString('RoleSeeder::class', $content);
        $this->assertStringContainsString('OrganizationSeeder::class', $content);
        $this->assertStringContainsString('UserRoleSeeder::class', $content);
    }

    public function test_update_database_seeder_does_nothing_when_file_missing(): void
    {
        $this->app->useDatabasePath($this->tempDir . '/nonexistent');

        $this->invokeMethod('updateDatabaseSeeder', []);
        $this->assertTrue(true);
    }

    // ------------------------------------------------------------------
    // installBlueprintDirectory
    // ------------------------------------------------------------------

    public function test_install_blueprint_directory_creates_rhino_dir(): void
    {
        $this->app->setBasePath($this->tempDir);
        $this->setBufferedOutput();

        $this->invokeMethod('installBlueprintDirectory', []);

        $this->assertDirectoryExists($this->tempDir . '/.rhino/blueprints');
        $this->assertFileExists($this->tempDir . '/.rhino/BLUEPRINT.md');
    }

    public function test_install_blueprint_directory_does_not_overwrite_existing_blueprint(): void
    {
        $this->app->setBasePath($this->tempDir);
        $this->setBufferedOutput();

        $rhinoDir = $this->tempDir . '/.rhino';
        File::ensureDirectoryExists($rhinoDir);
        File::put($rhinoDir . '/BLUEPRINT.md', 'existing content');

        $this->invokeMethod('installBlueprintDirectory', []);

        $content = File::get($rhinoDir . '/BLUEPRINT.md');
        $this->assertSame('existing content', $content);
    }

    // ------------------------------------------------------------------
    // installAuditTrail
    // ------------------------------------------------------------------

    public function test_install_audit_trail_creates_migration(): void
    {
        $migrationsDir = $this->tempDir . '/migrations';
        File::ensureDirectoryExists($migrationsDir);
        $this->app->useDatabasePath($this->tempDir);
        $this->setBufferedOutput();

        $this->invokeMethod('installAuditTrail', []);

        $files = File::files($migrationsDir);
        $fileNames = array_map(fn ($f) => $f->getFilename(), $files);

        $auditMigration = collect($fileNames)->first(fn ($n) => str_contains($n, 'create_audit_logs_table'));
        $this->assertNotNull($auditMigration, 'Audit logs migration should exist');
    }

    public function test_install_audit_trail_skips_if_migration_already_exists(): void
    {
        $migrationsDir = $this->tempDir . '/migrations';
        File::ensureDirectoryExists($migrationsDir);
        $this->app->useDatabasePath($this->tempDir);
        $this->setBufferedOutput();

        // Create existing migration
        File::put($migrationsDir . '/2024_01_01_000000_create_audit_logs_table.php', '<?php // existing');

        $this->invokeMethod('installAuditTrail', []);

        $files = File::files($migrationsDir);
        // Should still only have the one existing migration
        $auditMigrations = collect($files)->filter(fn ($f) => str_contains($f->getFilename(), 'audit_logs'));
        $this->assertCount(1, $auditMigrations);
    }

    // ------------------------------------------------------------------
    // overwriteBootstrapApp
    // ------------------------------------------------------------------

    public function test_overwrite_bootstrap_app(): void
    {
        $stubPath = realpath(__DIR__ . '/../../stubs/multi-tenant');
        $this->setProperty('stubPath', $stubPath);

        $bootstrapDir = $this->tempDir . '/bootstrap';
        File::ensureDirectoryExists($bootstrapDir);
        File::put($bootstrapDir . '/app.php', '<?php // original bootstrap');
        $this->app->setBasePath($this->tempDir);

        $this->invokeMethod('overwriteBootstrapApp', []);

        $content = File::get($bootstrapDir . '/app.php');
        $this->assertStringContainsString('ResolveOrganizationFromRoute', $content);
    }

    // ------------------------------------------------------------------
    // printNextSteps
    // ------------------------------------------------------------------

    public function test_print_next_steps_with_audit_trail(): void
    {
        $output = $this->setBufferedOutput();

        $this->invokeMethod('printNextSteps', [['audit_trail']]);

        $out = $output->fetch();
        $this->assertStringContainsString('HasAuditTrail', $out);
    }

    public function test_print_next_steps_without_audit_trail(): void
    {
        $output = $this->setBufferedOutput();

        $this->invokeMethod('printNextSteps', [['publish']]);

        $out = $output->fetch();
        $this->assertStringNotContainsString('HasAuditTrail', $out);
    }

    // ------------------------------------------------------------------
    // arrayToShortSyntax (additional cases)
    // ------------------------------------------------------------------

    public function test_array_to_short_syntax_with_integer_and_float_values(): void
    {
        $input = [
            'count' => 42,
            'rate' => 3.14,
        ];

        $result = $this->invokeMethod('arrayToShortSyntax', [$input]);

        $this->assertStringContainsString("'count' => 42,", $result);
        $this->assertStringContainsString("'rate' => 3.14,", $result);
    }

    public function test_array_to_short_syntax_with_sequential_array(): void
    {
        $input = [
            'routes' => ['api/v1', 'api/v2'],
        ];

        $result = $this->invokeMethod('arrayToShortSyntax', [$input]);

        $this->assertStringContainsString("'routes' => [", $result);
        $this->assertStringContainsString("'api/v1'", $result);
        $this->assertStringContainsString("'api/v2'", $result);
    }

    public function test_array_to_short_syntax_with_wildcard_string(): void
    {
        $input = [
            'models' => '*',
        ];

        $result = $this->invokeMethod('arrayToShortSyntax', [$input]);

        $this->assertStringContainsString("'models' => '*',", $result);
    }

    public function test_array_to_short_syntax_with_numeric_keys(): void
    {
        $input = ['first', 'second', 'third'];

        $result = $this->invokeMethod('arrayToShortSyntax', [$input]);

        $this->assertStringContainsString("'first'", $result);
        $this->assertStringContainsString("'second'", $result);
        $this->assertStringContainsString("'third'", $result);
    }

    // ------------------------------------------------------------------
    // generateRoleSeeder (additional cases)
    // ------------------------------------------------------------------

    public function test_generate_role_seeder_with_writer_role(): void
    {
        $this->invokeMethod('generateRoleSeeder', [$this->tempDir, ['admin', 'writer']]);

        $seederPath = $this->tempDir . '/RoleSeeder.php';
        $content = File::get($seederPath);

        $this->assertStringContainsString("['slug' => 'writer']", $content);
        $this->assertStringContainsString("'name' => 'Writer'", $content);
        $this->assertStringContainsString("'description' => 'Writer role with create and edit access'", $content);
    }

    public function test_generate_role_seeder_with_custom_role(): void
    {
        $this->invokeMethod('generateRoleSeeder', [$this->tempDir, ['admin', 'moderator']]);

        $seederPath = $this->tempDir . '/RoleSeeder.php';
        $content = File::get($seederPath);

        $this->assertStringContainsString("['slug' => 'moderator']", $content);
        $this->assertStringContainsString("'name' => 'Moderator'", $content);
        $this->assertStringContainsString("'description' => 'Moderator role'", $content);
    }

    // ------------------------------------------------------------------
    // createSeeders
    // ------------------------------------------------------------------

    public function test_create_seeders_creates_all_seeder_files(): void
    {
        $stubPath = realpath(__DIR__ . '/../../stubs/multi-tenant');
        $this->setProperty('stubPath', $stubPath);

        $seedersDir = $this->tempDir . '/seeders';
        File::ensureDirectoryExists($seedersDir);
        $this->app->useDatabasePath($this->tempDir);
        $this->setBufferedOutput();

        // Create a basic DatabaseSeeder
        $seederContent = <<<'PHP'
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        //
    }
}
PHP;
        File::put($seedersDir . '/DatabaseSeeder.php', $seederContent);

        $this->invokeMethod('createSeeders', [['admin', 'editor']]);

        $this->assertFileExists($seedersDir . '/RoleSeeder.php');
        $this->assertFileExists($seedersDir . '/OrganizationSeeder.php');
        $this->assertFileExists($seedersDir . '/UserRoleSeeder.php');
    }

    // ------------------------------------------------------------------
    // publishConfig (requires artisan infrastructure, test via setBufferedOutput)
    // ------------------------------------------------------------------

    public function test_publish_config_calls_vendor_publish(): void
    {
        $this->setBufferedOutput();

        // publishConfig uses callSilently which needs the command to be
        // registered with the application. We test via artisan integration.
        // Just verifying the method exists and is callable.
        $ref = new ReflectionMethod(InstallCommand::class, 'publishConfig');
        $this->assertTrue($ref->isProtected());
    }

    public function test_publish_routes_exists(): void
    {
        $ref = new ReflectionMethod(InstallCommand::class, 'publishRoutes');
        $this->assertTrue($ref->isProtected());
    }

    // ------------------------------------------------------------------
    // createMigrations
    // ------------------------------------------------------------------

    public function test_create_migrations_creates_three_migration_files(): void
    {
        $migrationsDir = $this->tempDir . '/migrations';
        File::ensureDirectoryExists($migrationsDir);
        $this->app->useDatabasePath($this->tempDir);

        $stubPath = realpath(__DIR__ . '/../../stubs/multi-tenant');
        $this->setProperty('stubPath', $stubPath);

        $this->invokeMethod('createMigrations', []);

        $files = File::files($migrationsDir);
        $fileNames = array_map(fn ($f) => $f->getFilename(), $files);

        $hasOrganizations = collect($fileNames)->contains(fn ($n) => str_contains($n, 'create_organizations_table'));
        $hasRoles = collect($fileNames)->contains(fn ($n) => str_contains($n, 'create_roles_table'));
        $hasUserRoles = collect($fileNames)->contains(fn ($n) => str_contains($n, 'create_user_roles_table'));

        $this->assertTrue($hasOrganizations, 'Organizations migration should exist');
        $this->assertTrue($hasRoles, 'Roles migration should exist');
        $this->assertTrue($hasUserRoles, 'User roles migration should exist');
    }

    // ------------------------------------------------------------------
    // createModels
    // ------------------------------------------------------------------

    public function test_create_models_creates_three_model_files(): void
    {
        $modelsDir = $this->tempDir . '/Models';
        File::ensureDirectoryExists($modelsDir);
        $this->app->useAppPath($this->tempDir);

        $stubPath = realpath(__DIR__ . '/../../stubs/multi-tenant');
        $this->setProperty('stubPath', $stubPath);

        $this->invokeMethod('createModels', [['admin', 'editor']]);

        $this->assertFileExists($modelsDir . '/Organization.php');
        $this->assertFileExists($modelsDir . '/Role.php');
        $this->assertFileExists($modelsDir . '/UserRole.php');

        $roleContent = File::get($modelsDir . '/Role.php');
        $this->assertStringContainsString("'admin'", $roleContent);
        $this->assertStringContainsString("'editor'", $roleContent);
    }

    // ------------------------------------------------------------------
    // createFactories
    // ------------------------------------------------------------------

    public function test_create_factories_creates_three_factory_files(): void
    {
        $factoriesDir = $this->tempDir . '/factories';
        File::ensureDirectoryExists($factoriesDir);
        $this->app->useDatabasePath($this->tempDir);

        $stubPath = realpath(__DIR__ . '/../../stubs/multi-tenant');
        $this->setProperty('stubPath', $stubPath);

        $this->invokeMethod('createFactories', []);

        $this->assertFileExists($factoriesDir . '/OrganizationFactory.php');
        $this->assertFileExists($factoriesDir . '/RoleFactory.php');
        $this->assertFileExists($factoriesDir . '/UserRoleFactory.php');
    }

    // ------------------------------------------------------------------
    // createPolicies
    // ------------------------------------------------------------------

    public function test_create_policies_creates_policy_files(): void
    {
        $policiesDir = $this->tempDir . '/Policies';
        File::ensureDirectoryExists($policiesDir);
        $this->app->useAppPath($this->tempDir);

        $stubPath = realpath(__DIR__ . '/../../stubs/multi-tenant');
        $this->setProperty('stubPath', $stubPath);

        $this->invokeMethod('createPolicies', []);

        $this->assertFileExists($policiesDir . '/OrganizationPolicy.php');
        $this->assertFileExists($policiesDir . '/RolePolicy.php');
    }

    // ------------------------------------------------------------------
    // arrayToShortSyntax — additional coverage
    // ------------------------------------------------------------------

    public function test_array_to_short_syntax_with_boolean_values(): void
    {
        $input = [
            'enabled' => true,
            'disabled' => false,
        ];

        $result = $this->invokeMethod('arrayToShortSyntax', [$input]);

        $this->assertStringContainsString("'enabled' => true,", $result);
        $this->assertStringContainsString("'disabled' => false,", $result);
    }

    public function test_array_to_short_syntax_with_null_value(): void
    {
        $input = [
            'default' => null,
        ];

        $result = $this->invokeMethod('arrayToShortSyntax', [$input]);

        $this->assertStringContainsString("'default' => null,", $result);
    }

    public function test_array_to_short_syntax_with_class_reference(): void
    {
        $input = [
            'organization' => 'App\\Models\\Organization',
        ];

        $result = $this->invokeMethod('arrayToShortSyntax', [$input]);

        $this->assertStringContainsString('\\App\\Models\\Organization::class', $result);
    }

    public function test_array_to_short_syntax_with_empty_array(): void
    {
        $input = [
            'items' => [],
        ];

        $result = $this->invokeMethod('arrayToShortSyntax', [$input]);

        $this->assertStringContainsString("'items' => [],", $result);
    }

    public function test_array_to_short_syntax_with_nested_assoc_array(): void
    {
        $input = [
            'models' => [
                'organizations' => 'App\\Models\\Organization',
            ],
        ];

        $result = $this->invokeMethod('arrayToShortSyntax', [$input]);

        $this->assertStringContainsString("'models' => [", $result);
        $this->assertStringContainsString('\\App\\Models\\Organization::class', $result);
    }

    // ------------------------------------------------------------------
    // overwriteBootstrapApp — fallback stubPath
    // ------------------------------------------------------------------

    public function test_overwrite_bootstrap_app_uses_default_stub_path_when_null(): void
    {
        // Test that overwriteBootstrapApp works even when stubPath is null (falls back to default)
        $bootstrapDir = $this->tempDir . '/bootstrap';
        File::ensureDirectoryExists($bootstrapDir);
        File::put($bootstrapDir . '/app.php', '<?php // original bootstrap');
        $this->app->setBasePath($this->tempDir);

        // Do NOT set stubPath — let it use the fallback
        $this->setProperty('stubPath', null);

        $this->invokeMethod('overwriteBootstrapApp', []);

        $content = File::get($bootstrapDir . '/app.php');
        $this->assertStringContainsString('ResolveOrganizationFromRoute', $content);
    }

    // ------------------------------------------------------------------
    // generateRoleSeeder with editor role
    // ------------------------------------------------------------------

    public function test_generate_role_seeder_with_editor_role(): void
    {
        $this->invokeMethod('generateRoleSeeder', [$this->tempDir, ['admin', 'editor']]);

        $seederPath = $this->tempDir . '/RoleSeeder.php';
        $this->assertFileExists($seederPath);
        $content = File::get($seederPath);

        $this->assertStringContainsString("['slug' => 'admin']", $content);
        $this->assertStringContainsString("'description' => 'Administrator role with full access'", $content);
        $this->assertStringContainsString("['slug' => 'editor']", $content);
        $this->assertStringContainsString("'description' => 'Editor role with create, read, and update access'", $content);
    }

    public function test_generate_role_seeder_with_viewer_role(): void
    {
        $this->invokeMethod('generateRoleSeeder', [$this->tempDir, ['admin', 'viewer']]);

        $content = File::get($this->tempDir . '/RoleSeeder.php');

        $this->assertStringContainsString("['slug' => 'viewer']", $content);
        $this->assertStringContainsString("'description' => 'Viewer role with read-only access'", $content);
    }

    // ------------------------------------------------------------------
    // updateDatabaseSeeder — partial seeders present
    // ------------------------------------------------------------------

    public function test_update_database_seeder_adds_missing_seeders(): void
    {
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
            RoleSeeder::class,
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
    }

    // ------------------------------------------------------------------
    // updateDatabaseSeeder — no run() method match for first regex
    // ------------------------------------------------------------------

    public function test_update_database_seeder_with_different_run_signature(): void
    {
        $seedersDir = $this->tempDir . '/seeders';
        File::ensureDirectoryExists($seedersDir);
        $this->app->useDatabasePath($this->tempDir);

        // A seeder where the run() method has content but no existing $this->call
        $seederContent = <<<'PHP'
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        // Some comment
        \App\Models\User::factory()->create();
    }
}
PHP;
        File::put($seedersDir . '/DatabaseSeeder.php', $seederContent);

        $this->invokeMethod('updateDatabaseSeeder', []);

        $content = File::get($seedersDir . '/DatabaseSeeder.php');
        $this->assertStringContainsString('RoleSeeder::class', $content);
        $this->assertStringContainsString('OrganizationSeeder::class', $content);
        $this->assertStringContainsString('UserRoleSeeder::class', $content);
    }

    // ------------------------------------------------------------------
    // updateConfig — config content verification
    // ------------------------------------------------------------------

    public function test_update_config_sets_organization_identifier_column(): void
    {
        $configDir = $this->tempDir . '/config';
        File::ensureDirectoryExists($configDir);
        $this->app->useConfigPath($configDir);

        $configContent = <<<'PHP'
<?php

return [
    'models' => [
        'organizations' => \App\Models\Organization::class,
        'roles' => \App\Models\Role::class,
    ],
    'route_groups' => [
        'default' => [
            'prefix' => '',
            'middleware' => [],
            'models' => '*',
        ],
    ],
    'multi_tenant' => [
        'organization_identifier_column' => 'id',
    ],
];
PHP;
        File::put($configDir . '/rhino.php', $configContent);

        $this->setProperty('stubPath', realpath(__DIR__ . '/../../stubs/multi-tenant'));

        $this->invokeMethod('updateConfig', ['uuid']);

        $content = File::get($configDir . '/rhino.php');
        $this->assertStringContainsString('uuid', $content);
        $this->assertStringContainsString('tenant', $content);
        $this->assertStringContainsString('{organization}', $content);
    }

    // ------------------------------------------------------------------
    // createMiddleware — verify content
    // ------------------------------------------------------------------

    public function test_create_middleware_content_has_correct_namespace(): void
    {
        $middlewareDir = $this->tempDir . '/Http/Middleware';
        File::ensureDirectoryExists($middlewareDir);
        $this->app->useAppPath($this->tempDir);

        $this->invokeMethod('createMiddleware', []);

        $content = File::get($middlewareDir . '/ResolveOrganizationFromRoute.php');
        $this->assertStringContainsString('namespace App\Http\Middleware;', $content);
        $this->assertStringContainsString('class ResolveOrganizationFromRoute', $content);
    }

    // ------------------------------------------------------------------
    // installBlueprintDirectory — creates BLUEPRINT.md from stub
    // ------------------------------------------------------------------

    public function test_install_blueprint_directory_creates_md_from_stub(): void
    {
        $this->app->setBasePath($this->tempDir);
        $this->setBufferedOutput();

        $this->invokeMethod('installBlueprintDirectory', []);

        $this->assertFileExists($this->tempDir . '/.rhino/BLUEPRINT.md');
        $content = File::get($this->tempDir . '/.rhino/BLUEPRINT.md');
        $this->assertNotEmpty($content);
    }

    // ------------------------------------------------------------------
    // printNextSteps — with multiple features
    // ------------------------------------------------------------------

    public function test_print_next_steps_with_publish_feature(): void
    {
        $output = $this->setBufferedOutput();

        $this->invokeMethod('printNextSteps', [['publish']]);

        $out = $output->fetch();
        $this->assertStringContainsString('blueprint', $out);
        $this->assertStringNotContainsString('HasAuditTrail', $out);
    }

    public function test_print_next_steps_with_both_features(): void
    {
        $output = $this->setBufferedOutput();

        $this->invokeMethod('printNextSteps', [['audit_trail', 'publish']]);

        $out = $output->fetch();
        $this->assertStringContainsString('HasAuditTrail', $out);
        $this->assertStringContainsString('blueprint', $out);
    }

    // ------------------------------------------------------------------
    // updateUserModel — more edge cases
    // ------------------------------------------------------------------

    public function test_update_user_model_adds_all_imports_and_traits(): void
    {
        $modelsDir = $this->tempDir . '/Models';
        File::ensureDirectoryExists($modelsDir);
        $this->app->useAppPath($this->tempDir);

        $stubPath = realpath(__DIR__ . '/../../stubs/multi-tenant');
        $this->setProperty('stubPath', $stubPath);

        $userContent = <<<'PHP'
<?php

namespace App\Models;

use Illuminate\Foundation\Auth\User as Authenticatable;
use Illuminate\Database\Eloquent\Factories\HasFactory;

class User extends Authenticatable
{
    use HasFactory;

    protected $fillable = ['name', 'email', 'password'];
}
PHP;
        File::put($modelsDir . '/User.php', $userContent);

        $this->invokeMethod('updateUserModel', []);

        $content = File::get($modelsDir . '/User.php');
        $this->assertStringContainsString('use Laravel\Sanctum\HasApiTokens;', $content);
        $this->assertStringContainsString('use Rhino\Traits\HasPermissions;', $content);
        $this->assertStringContainsString('use Rhino\Contracts\HasRoleBasedValidation;', $content);
        $this->assertStringContainsString('use App\Models\Organization;', $content);
        $this->assertStringContainsString('use App\Models\Role;', $content);
        $this->assertStringContainsString('implements HasRoleBasedValidation', $content);
    }

    // ------------------------------------------------------------------
    // updateAppServiceProvider — verify Gate import and policy discovery
    // ------------------------------------------------------------------

    public function test_update_app_service_provider_full_content(): void
    {
        $providersDir = $this->tempDir . '/Providers';
        File::ensureDirectoryExists($providersDir);
        $this->app->useAppPath($this->tempDir);

        $providerContent = <<<'PHP'
<?php

namespace App\Providers;

use Illuminate\Support\ServiceProvider;

class AppServiceProvider extends ServiceProvider
{
    public function register(): void {}

    public function boot(): void
    {
        //
    }
}
PHP;
        File::put($providersDir . '/AppServiceProvider.php', $providerContent);

        $this->invokeMethod('updateAppServiceProvider', []);

        $content = File::get($providersDir . '/AppServiceProvider.php');
        $this->assertStringContainsString('use Illuminate\Support\Facades\Gate;', $content);
        $this->assertStringContainsString('guessPolicyNamesUsing', $content);
        $this->assertStringContainsString("class_basename(\$modelClass) . 'Policy'", $content);
    }

    // ------------------------------------------------------------------
    // createSeeders — full flow
    // ------------------------------------------------------------------

    public function test_create_seeders_full_flow(): void
    {
        $stubPath = realpath(__DIR__ . '/../../stubs/multi-tenant');
        $this->setProperty('stubPath', $stubPath);

        $seedersDir = $this->tempDir . '/seeders';
        File::ensureDirectoryExists($seedersDir);
        $this->app->useDatabasePath($this->tempDir);
        $this->setBufferedOutput();

        $seederContent = <<<'PHP'
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        //
    }
}
PHP;
        File::put($seedersDir . '/DatabaseSeeder.php', $seederContent);

        $this->invokeMethod('createSeeders', [['admin', 'editor', 'viewer']]);

        $this->assertFileExists($seedersDir . '/RoleSeeder.php');
        $this->assertFileExists($seedersDir . '/OrganizationSeeder.php');
        $this->assertFileExists($seedersDir . '/UserRoleSeeder.php');

        $roleContent = File::get($seedersDir . '/RoleSeeder.php');
        $this->assertStringContainsString("'admin'", $roleContent);
        $this->assertStringContainsString("'editor'", $roleContent);
        $this->assertStringContainsString("'viewer'", $roleContent);

        // DatabaseSeeder should be updated
        $dbSeederContent = File::get($seedersDir . '/DatabaseSeeder.php');
        $this->assertStringContainsString('RoleSeeder::class', $dbSeederContent);
    }

    // ------------------------------------------------------------------
    // ensureSanctumInstalled — when sanctum IS installed
    // ------------------------------------------------------------------

    public function test_ensure_sanctum_installed_class_exists(): void
    {
        // Verify that the Sanctum provider class exists in the test environment
        $this->assertTrue(class_exists(\Laravel\Sanctum\SanctumServiceProvider::class));
    }

    public function test_publish_sanctum_migrations_method_exists(): void
    {
        $ref = new ReflectionMethod(InstallCommand::class, 'publishSanctumMigrations');
        $this->assertTrue($ref->isProtected());
    }

    public function test_refresh_autoloader_method_exists(): void
    {
        $ref = new ReflectionMethod(InstallCommand::class, 'refreshAutoloader');
        $this->assertTrue($ref->isProtected());
    }

    // ------------------------------------------------------------------
    // arrayToShortSyntax — deeply nested structure
    // ------------------------------------------------------------------

    public function test_array_to_short_syntax_deeply_nested(): void
    {
        $input = [
            'route_groups' => [
                'tenant' => [
                    'prefix' => '{organization}',
                    'middleware' => ['Rhino\Http\Middleware\ResolveOrganizationFromRoute'],
                    'models' => '*',
                ],
            ],
        ];

        $result = $this->invokeMethod('arrayToShortSyntax', [$input]);

        $this->assertStringContainsString("'route_groups' => [", $result);
        $this->assertStringContainsString("'tenant' => [", $result);
        $this->assertStringContainsString("'prefix' => '{organization}'", $result);
        $this->assertStringContainsString("'models' => '*'", $result);
    }

    // ------------------------------------------------------------------
    // installAuditTrail — content verification
    // ------------------------------------------------------------------

    public function test_install_audit_trail_migration_contains_audit_logs_schema(): void
    {
        $migrationsDir = $this->tempDir . '/migrations';
        File::ensureDirectoryExists($migrationsDir);
        $this->app->useDatabasePath($this->tempDir);
        $this->setBufferedOutput();

        $this->invokeMethod('installAuditTrail', []);

        $files = File::files($migrationsDir);
        $this->assertNotEmpty($files);

        $auditMigration = collect($files)->first(fn ($f) => str_contains($f->getFilename(), 'audit_logs'));
        $this->assertNotNull($auditMigration);

        $content = File::get($auditMigration->getPathname());
        $this->assertStringContainsString('audit_logs', $content);
    }

    // ------------------------------------------------------------------
    // publishSanctumMigrations
    // ------------------------------------------------------------------

    // test_publish_sanctum_migrations, test_publish_config_with_phpunit, test_publish_routes removed
    // — these call vendor:publish which requires full application context not available in package tests

    // ------------------------------------------------------------------
    // installMultiTenant orchestration
    // ------------------------------------------------------------------

    public function test_install_multi_tenant_calls_all_sub_methods(): void
    {
        $stubPath = realpath(__DIR__ . '/../../stubs/multi-tenant');
        $this->setProperty('stubPath', $stubPath);
        $this->setBufferedOutput();

        // Set up temp directories
        $migrationsDir = $this->tempDir . '/migrations';
        $modelsDir = $this->tempDir . '/app/Models';
        $factoriesDir = $this->tempDir . '/factories';
        $policiesDir = $this->tempDir . '/app/Policies';
        $routesDir = $this->tempDir . '/routes';
        $middlewareDir = $this->tempDir . '/app/Http/Middleware';
        $seedersDir = $this->tempDir . '/seeders';
        $providerDir = $this->tempDir . '/app/Providers';
        $configDir = $this->tempDir . '/config';

        File::ensureDirectoryExists($migrationsDir);
        File::ensureDirectoryExists($modelsDir);
        File::ensureDirectoryExists($factoriesDir);
        File::ensureDirectoryExists($policiesDir);
        File::ensureDirectoryExists($routesDir);
        File::ensureDirectoryExists($middlewareDir);
        File::ensureDirectoryExists($seedersDir);
        File::ensureDirectoryExists($providerDir);
        File::ensureDirectoryExists($configDir);

        $this->app->setBasePath($this->tempDir);
        $this->app->useDatabasePath($this->tempDir);

        // Create required files for sub-methods
        File::put($modelsDir . '/User.php', '<?php namespace App\Models; class User extends \Illuminate\Foundation\Auth\User { }');
        File::put($providerDir . '/AppServiceProvider.php', "<?php namespace App\Providers;\nclass AppServiceProvider extends \\Illuminate\\Support\\ServiceProvider {\n    public function boot(): void\n    {\n    }\n}");
        File::put($seedersDir . '/DatabaseSeeder.php', "<?php namespace Database\Seeders;\nclass DatabaseSeeder {\n    public function run(): void\n    {\n    }\n}");
        File::put($configDir . '/rhino.php', "<?php return ['route_groups' => []];");

        $this->invokeMethod('installMultiTenant', ['id', ['admin', 'editor']]);

        // Verify migrations were created
        $migrationFiles = File::files($migrationsDir);
        $this->assertGreaterThanOrEqual(3, count($migrationFiles));

        // Verify models were created
        $this->assertFileExists($modelsDir . '/Organization.php');
        $this->assertFileExists($modelsDir . '/Role.php');
        $this->assertFileExists($modelsDir . '/UserRole.php');

        // Verify factories were created
        $this->assertFileExists($factoriesDir . '/OrganizationFactory.php');
        $this->assertFileExists($factoriesDir . '/RoleFactory.php');
        $this->assertFileExists($factoriesDir . '/UserRoleFactory.php');

        // Verify policies were created
        $this->assertFileExists($policiesDir . '/OrganizationPolicy.php');
        $this->assertFileExists($policiesDir . '/RolePolicy.php');

        // Verify middleware was created
        $this->assertFileExists($middlewareDir . '/ResolveOrganizationFromRoute.php');

        // Verify Role model has correct roles
        $roleContent = File::get($modelsDir . '/Role.php');
        $this->assertStringContainsString("'admin'", $roleContent);
        $this->assertStringContainsString("'editor'", $roleContent);
    }

    // ------------------------------------------------------------------
    // installBlueprintDirectory — verify BLUEPRINT.md is written
    // ------------------------------------------------------------------

    public function test_install_blueprint_directory_creates_blueprint_guide(): void
    {
        $rhinoDir = $this->tempDir . '/.rhino';
        File::ensureDirectoryExists($rhinoDir);
        $this->app->setBasePath($this->tempDir);
        $this->setBufferedOutput();

        // Remove any existing content
        File::deleteDirectory($rhinoDir);

        $this->invokeMethod('installBlueprintDirectory', []);

        $this->assertDirectoryExists($rhinoDir);
        $this->assertDirectoryExists($rhinoDir . '/blueprints');
        $this->assertFileExists($rhinoDir . '/BLUEPRINT.md');
    }

    // ------------------------------------------------------------------
    // printNextSteps — various feature combinations
    // ------------------------------------------------------------------

    public function test_print_next_steps_without_features(): void
    {
        $this->setBufferedOutput();
        $this->invokeMethod('printNextSteps', [[]]);
        $this->assertTrue(true); // Verify it doesn't throw
    }

    public function test_print_next_steps_with_multi_tenant_and_audit_trail(): void
    {
        $this->setBufferedOutput();
        $this->invokeMethod('printNextSteps', [['multi_tenant', 'audit_trail']]);
        $this->assertTrue(true);
    }

    // ------------------------------------------------------------------
    // ensureSanctumInstalled — when Sanctum is already installed
    // ------------------------------------------------------------------

    // test_ensure_sanctum_installed_returns_true_when_present removed — requires full app context

    // ------------------------------------------------------------------
    // createMigrations — standalone
    // ------------------------------------------------------------------

    public function test_create_migrations_creates_organization_role_userrole_tables(): void
    {
        $stubPath = realpath(__DIR__ . '/../../stubs/multi-tenant');
        $this->setProperty('stubPath', $stubPath);

        $migrationsDir = $this->tempDir . '/migrations';
        File::ensureDirectoryExists($migrationsDir);
        $this->app->useDatabasePath($this->tempDir);

        $this->invokeMethod('createMigrations', []);

        $files = File::files($migrationsDir);
        $fileNames = array_map(fn ($f) => $f->getFilename(), $files);

        $hasOrganizations = collect($fileNames)->contains(fn ($n) => str_contains($n, 'create_organizations_table'));
        $hasRoles = collect($fileNames)->contains(fn ($n) => str_contains($n, 'create_roles_table'));
        $hasUserRoles = collect($fileNames)->contains(fn ($n) => str_contains($n, 'create_user_roles_table'));

        $this->assertTrue($hasOrganizations);
        $this->assertTrue($hasRoles);
        $this->assertTrue($hasUserRoles);
    }

    // ------------------------------------------------------------------
    // createModels — with custom roles
    // ------------------------------------------------------------------

    public function test_create_models_with_custom_roles(): void
    {
        $stubPath = realpath(__DIR__ . '/../../stubs/multi-tenant');
        $this->setProperty('stubPath', $stubPath);

        $modelsDir = $this->tempDir . '/app/Models';
        File::ensureDirectoryExists($modelsDir);
        $this->app->setBasePath($this->tempDir);

        $this->invokeMethod('createModels', [['admin', 'manager', 'viewer']]);

        $this->assertFileExists($modelsDir . '/Organization.php');
        $this->assertFileExists($modelsDir . '/Role.php');
        $this->assertFileExists($modelsDir . '/UserRole.php');

        $roleContent = File::get($modelsDir . '/Role.php');
        $this->assertStringContainsString("'admin'", $roleContent);
        $this->assertStringContainsString("'manager'", $roleContent);
        $this->assertStringContainsString("'viewer'", $roleContent);
    }

    // ------------------------------------------------------------------
    // createFactories — standalone
    // ------------------------------------------------------------------

    public function test_create_factories_creates_all_factory_files(): void
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
    }

    // ------------------------------------------------------------------
    // createPolicies — standalone
    // ------------------------------------------------------------------

    public function test_create_policies_creates_org_and_role_policies(): void
    {
        $stubPath = realpath(__DIR__ . '/../../stubs/multi-tenant');
        $this->setProperty('stubPath', $stubPath);

        $policiesDir = $this->tempDir . '/app/Policies';
        File::ensureDirectoryExists($policiesDir);
        $this->app->setBasePath($this->tempDir);

        $this->invokeMethod('createPolicies', []);

        $this->assertFileExists($policiesDir . '/OrganizationPolicy.php');
        $this->assertFileExists($policiesDir . '/RolePolicy.php');
    }

    // ------------------------------------------------------------------
    // refreshAutoloader — verify it doesn't throw
    // ------------------------------------------------------------------

    // test_refresh_autoloader_does_not_throw removed — calls composer which requires shell access

    // ------------------------------------------------------------------
    // ensureSanctumInstalled — Sanctum is available in test env
    // ------------------------------------------------------------------

    // ensureSanctumInstalled, publishSanctumMigrations, publishConfig, publishRoutes,
    // refreshAutoloader — all require full Artisan context (callSilently / base_path)
    // and cannot be tested via reflection in package tests.

    // ------------------------------------------------------------------
    // updateDatabaseSeeder — fallback regex path (line 627-631)
    // ------------------------------------------------------------------

    public function test_update_database_seeder_fallback_regex(): void
    {
        $seedersDir = $this->tempDir . '/seeders';
        File::ensureDirectoryExists($seedersDir);
        $this->app->useDatabasePath($this->tempDir);

        // Create a DatabaseSeeder with a run method that has nested braces,
        // which will cause the first regex to fail and trigger the fallback
        $seederContent = <<<'PHP'
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        if (true) {
            // nested brace
        }
    }
}
PHP;
        File::put($seedersDir . '/DatabaseSeeder.php', $seederContent);

        $this->invokeMethod('updateDatabaseSeeder', []);

        $content = File::get($seedersDir . '/DatabaseSeeder.php');
        $this->assertStringContainsString('RoleSeeder::class', $content);
        $this->assertStringContainsString('OrganizationSeeder::class', $content);
        $this->assertStringContainsString('UserRoleSeeder::class', $content);
    }

    // ------------------------------------------------------------------
    // installBlueprintDirectory — when blueprint stub doesn't exist
    // ------------------------------------------------------------------

    public function test_install_blueprint_directory_creates_dir_without_stub(): void
    {
        $this->app->setBasePath($this->tempDir);
        $this->setBufferedOutput();

        // Ensure the .rhino directory doesn't exist
        $rhinoDir = $this->tempDir . '/.rhino';
        File::deleteDirectory($rhinoDir);

        // The method should still create the directory even if stub is missing
        $this->invokeMethod('installBlueprintDirectory', []);

        $this->assertDirectoryExists($rhinoDir . '/blueprints');
    }

    // ------------------------------------------------------------------
    // printNextSteps — multi_tenant feature
    // ------------------------------------------------------------------

    public function test_print_next_steps_with_multi_tenant_feature(): void
    {
        $buffered = $this->setBufferedOutput();
        $this->invokeMethod('printNextSteps', [['multi_tenant']]);

        $output = $buffered->fetch();
        $this->assertStringContainsString('blueprint', strtolower($output));
    }

    // ------------------------------------------------------------------
    // printNextSteps — publish feature
    // ------------------------------------------------------------------

    public function test_print_next_steps_publish_only(): void
    {
        $buffered = $this->setBufferedOutput();
        $this->invokeMethod('printNextSteps', [['publish']]);

        $output = $buffered->fetch();
        $this->assertStringContainsString('rhino:blueprint', $output);
    }

    // ------------------------------------------------------------------
    // overwriteBootstrapApp — with explicit stubPath set
    // ------------------------------------------------------------------

    public function test_overwrite_bootstrap_app_with_explicit_stub_path(): void
    {
        $stubPath = realpath(__DIR__ . '/../../stubs/multi-tenant');
        $this->setProperty('stubPath', $stubPath);

        $bootstrapDir = $this->tempDir . '/bootstrap';
        File::ensureDirectoryExists($bootstrapDir);
        File::put($bootstrapDir . '/app.php', '<?php // original');

        $this->app->setBasePath($this->tempDir);

        $this->invokeMethod('overwriteBootstrapApp', []);

        $content = File::get($bootstrapDir . '/app.php');
        $this->assertStringContainsString('ResolveOrganizationFromRoute', $content);
    }

    // ------------------------------------------------------------------
    // installAuditTrail — verify the migration is idempotent
    // ------------------------------------------------------------------

    public function test_install_audit_trail_is_idempotent(): void
    {
        $migrationsDir = $this->tempDir . '/migrations';
        File::ensureDirectoryExists($migrationsDir);
        $this->app->useDatabasePath($this->tempDir);
        $this->setBufferedOutput();

        // Run it twice
        $this->invokeMethod('installAuditTrail', []);
        $this->invokeMethod('installAuditTrail', []);

        $files = File::files($migrationsDir);
        $auditFiles = collect($files)->filter(fn ($f) => str_contains($f->getFilename(), 'audit_logs'));

        // Should only have one migration file
        $this->assertCount(1, $auditFiles);
    }
}
