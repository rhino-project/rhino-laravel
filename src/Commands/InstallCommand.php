<?php

namespace Rhino\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\note;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

class InstallCommand extends Command
{
    protected $signature = 'rhino:install';

    protected $description = 'Install and configure Rhino for your Laravel application';

    protected $stubPath;

    public function handle()
    {
        $this->newLine();
        note('+ Rhino :: Install :: Let\'s build something great +');
        $this->newLine();

        $features = multiselect(
            label: 'Which features would you like to configure?',
            options: [
                'publish' => 'Publish config & routes',
                'multi_tenant' => 'Multi-tenant support (Organizations, Roles)',
                'audit_trail' => 'Audit trail (change logging)',
            ],
            default: ['publish'],
            required: true,
        );

        $testFramework = select(
            label: 'Which test framework do you use?',
            options: [
                'pest' => 'Pest',
                'phpunit' => 'PHPUnit',
            ],
            default: 'pest',
        );

        $identifierColumn = 'id';
        $roles = ['admin'];

        if (in_array('multi_tenant', $features)) {
            $identifierColumn = text(
                label: 'What column should be used to identify organizations?',
                placeholder: 'id',
                default: 'id',
                hint: 'Common options: id, slug, uuid',
            );

            $rolesInput = text(
                label: 'What roles should your app have?',
                placeholder: 'admin, editor, viewer',
                default: 'admin, editor, viewer',
                hint: 'Comma-separated list of role slugs. "admin" is always included.',
            );

            $roles = array_unique(array_merge(
                ['admin'],
                array_filter(array_map('trim', explode(',', $rolesInput)))
            ));
        }

        $this->newLine();

        if (in_array('publish', $features)) {
            $this->publishConfig($testFramework);
            $this->publishRoutes();
        }

        if (in_array('multi_tenant', $features)) {
            if (!$this->ensureSanctumInstalled()) {
                warning('Skipping multi-tenant setup: Sanctum is required.');
            } else {
                $this->stubPath = __DIR__ . '/../../stubs/multi-tenant';
                $this->installMultiTenant($identifierColumn, $roles);
            }
        }

        if (in_array('audit_trail', $features)) {
            $this->installAuditTrail();
        }

        // Always create .rhino directory for Blueprint support
        $this->installBlueprintDirectory();

        $this->newLine();

        $this->runPostInstallSteps($features);

        $this->installAiSkill();

        $this->newLine();
        info('Rhino installed successfully!');
        $this->newLine();

        $this->printNextSteps($features);

        return 0;
    }

    // ------------------------------------------------------------------
    // Sanctum
    // ------------------------------------------------------------------

    protected function ensureSanctumInstalled(): bool
    {
        if (class_exists(\Laravel\Sanctum\SanctumServiceProvider::class)) {
            $this->publishSanctumMigrations();
            return true;
        }

        $installSanctum = confirm(
            label: 'Laravel Sanctum is required for API authentication but is not installed. Install it now?',
            default: true,
        );

        if (!$installSanctum) {
            warning('Sanctum is required. Please run: composer require laravel/sanctum');
            return false;
        }

        $process = new \Symfony\Component\Process\Process(
            ['composer', 'require', 'laravel/sanctum'],
            base_path()
        );
        $process->setTimeout(300);

        $this->components->task('Installing Laravel Sanctum', function () use ($process) {
            $process->run();

            return $process->isSuccessful() ?: false;
        });

        if (!$process->isSuccessful()) {
            warning('Failed to install Sanctum: ' . $process->getErrorOutput());
            warning('Please run manually: composer require laravel/sanctum');
            return false;
        }

        // Refresh the Composer autoloader so newly installed classes are available
        // in the current process (composer ran as a subprocess).
        $this->refreshAutoloader();

        $this->publishSanctumMigrations();

        return true;
    }

    protected function publishSanctumMigrations(): void
    {
        $this->components->task('Publishing Sanctum migrations', function () {
            $this->callSilently('vendor:publish', [
                '--provider' => 'Laravel\Sanctum\SanctumServiceProvider',
                '--tag' => 'sanctum-migrations',
            ]);
        });
    }

    protected function refreshAutoloader(): void
    {
        $loader = require base_path('vendor/autoload.php');

        $composerDir = base_path('vendor/composer');

        $classMap = require "$composerDir/autoload_classmap.php";
        $loader->addClassMap($classMap);

        $psr4 = require "$composerDir/autoload_psr4.php";
        foreach ($psr4 as $namespace => $paths) {
            $loader->setPsr4($namespace, $paths);
        }
    }

    // ------------------------------------------------------------------
    // Publish
    // ------------------------------------------------------------------

    protected function publishConfig(string $testFramework = 'pest'): void
    {
        $this->components->task('Publishing config', function () use ($testFramework) {
            $this->callSilently('vendor:publish', [
                '--provider' => 'Rhino\GlobalControllerServiceProvider',
                '--tag' => 'config',
            ]);

            // Set the chosen test framework in config
            $configPath = config_path('rhino.php');
            if (File::exists($configPath)) {
                $content = File::get($configPath);
                $content = str_replace(
                    "'test_framework' => 'pest'",
                    "'test_framework' => '{$testFramework}'",
                    $content
                );
                File::put($configPath, $content);
            }
        });
    }

    protected function publishRoutes(): void
    {
        $this->components->task('Publishing routes', function () {
            $this->callSilently('vendor:publish', [
                '--provider' => 'Rhino\GlobalControllerServiceProvider',
                '--tag' => 'routes',
            ]);
        });
    }

    // ------------------------------------------------------------------
    // Multi-tenant
    // ------------------------------------------------------------------

    protected function installMultiTenant(string $identifierColumn, array $roles = ['admin']): void
    {
        $this->components->task('Creating migrations', fn () => $this->createMigrations());
        $this->components->task('Creating models', fn () => $this->createModels($roles));
        $this->components->task('Creating factories', fn () => $this->createFactories());
        $this->components->task('Creating policies', fn () => $this->createPolicies());
        $this->components->task('Updating routes', fn () => $this->updateRoutes());
        $this->components->task('Creating middleware', fn () => $this->createMiddleware());
        $this->components->task('Updating config', fn () => $this->updateConfig($identifierColumn));
        $this->components->task('Updating User model', fn () => $this->updateUserModel());
        $this->components->task('Updating AppServiceProvider', fn () => $this->updateAppServiceProvider());
        $this->components->task('Creating seeders', fn () => $this->createSeeders($roles));
    }

    protected function createMigrations(): void
    {
        $migrationsPath = database_path('migrations');
        $timestamp = now()->format('Y_m_d_His');

        File::ensureDirectoryExists($migrationsPath);

        File::copy(
            $this->stubPath . '/migrations/create_organizations_table.php.stub',
            $migrationsPath . "/{$timestamp}_00_create_organizations_table.php"
        );

        File::copy(
            $this->stubPath . '/migrations/create_roles_table.php.stub',
            $migrationsPath . "/{$timestamp}_01_create_roles_table.php"
        );

        File::copy(
            $this->stubPath . '/migrations/create_user_roles_table.php.stub',
            $migrationsPath . "/{$timestamp}_02_create_user_roles_table.php"
        );

        File::copy(
            $this->stubPath . '/migrations/create_org_role_permissions_table.php.stub',
            $migrationsPath . "/{$timestamp}_03_create_org_role_permissions_table.php"
        );
    }

    protected function createModels(array $roles = ['admin']): void
    {
        $modelsPath = app_path('Models');

        File::ensureDirectoryExists($modelsPath);

        File::copy(
            $this->stubPath . '/models/Organization.php.stub',
            $modelsPath . '/Organization.php'
        );

        // Role model with dynamic $roles array
        $roleStub = File::get($this->stubPath . '/models/Role.php.stub');
        $rolesPhp = "[\n" . implode("\n", array_map(fn ($r) => "        '{$r}',", $roles)) . "\n    ]";
        $roleContent = str_replace('{{ roles }}', $rolesPhp, $roleStub);
        File::put($modelsPath . '/Role.php', $roleContent);

        File::copy(
            $this->stubPath . '/models/UserRole.php.stub',
            $modelsPath . '/UserRole.php'
        );

        File::copy(
            $this->stubPath . '/models/OrgRolePermission.php.stub',
            $modelsPath . '/OrgRolePermission.php'
        );
    }

    protected function createFactories(): void
    {
        $factoriesPath = database_path('factories');

        File::ensureDirectoryExists($factoriesPath);

        File::copy(
            $this->stubPath . '/factories/OrganizationFactory.php.stub',
            $factoriesPath . '/OrganizationFactory.php'
        );

        File::copy(
            $this->stubPath . '/factories/RoleFactory.php.stub',
            $factoriesPath . '/RoleFactory.php'
        );

        File::copy(
            $this->stubPath . '/factories/UserRoleFactory.php.stub',
            $factoriesPath . '/UserRoleFactory.php'
        );
    }

    protected function createPolicies(): void
    {
        $policiesPath = app_path('Policies');

        File::ensureDirectoryExists($policiesPath);

        File::copy(
            $this->stubPath . '/policies/OrganizationPolicy.php.stub',
            $policiesPath . '/OrganizationPolicy.php'
        );

        File::copy(
            $this->stubPath . '/policies/RolePolicy.php.stub',
            $policiesPath . '/RolePolicy.php'
        );

        File::copy(
            $this->stubPath . '/policies/UserPolicy.php.stub',
            $policiesPath . '/UserPolicy.php'
        );
    }

    protected function updateRoutes(): void
    {
        $apiRoutesPath = base_path('routes/api.php');

        File::ensureDirectoryExists(dirname($apiRoutesPath));

        File::copy(
            $this->stubPath . '/routes/api-route-prefix.php.stub',
            $apiRoutesPath
        );
    }

    protected function createMiddleware(): void
    {
        $middlewarePath = app_path('Http/Middleware');
        $packageMiddlewarePath = __DIR__ . '/../Http/Middleware';

        File::ensureDirectoryExists($middlewarePath);

        $packageContent = File::get($packageMiddlewarePath . '/ResolveOrganizationFromRoute.php');
        $appContent = str_replace(
            'namespace Rhino\Http\Middleware;',
            'namespace App\Http\Middleware;',
            $packageContent
        );
        File::put($middlewarePath . '/ResolveOrganizationFromRoute.php', $appContent);
    }

    protected function updateConfig(string $identifierColumn): void
    {
        $configPath = config_path('rhino.php');

        if (!File::exists($configPath)) {
            return;
        }

        $config = require $configPath;

        $config['multi_tenant'] = [
            'organization_identifier_column' => $identifierColumn,
        ];

        $config['route_groups'] = [
            'tenant' => [
                'prefix' => '{organization}',
                'middleware' => ['Rhino\Http\Middleware\ResolveOrganizationFromRoute'],
                'models' => '*',
            ],
        ];

        $config['models']['organizations'] = \App\Models\Organization::class;
        $config['models']['roles'] = \App\Models\Role::class;

        $configContent = "<?php\n\nreturn " . $this->arrayToShortSyntax($config) . ";\n";
        File::put($configPath, $configContent);
    }

    protected function updateUserModel(): void
    {
        $userModelPath = app_path('Models/User.php');

        if (!File::exists($userModelPath)) {
            return;
        }

        $userModelContent = File::get($userModelPath);

        if (strpos($userModelContent, 'function organizations()') !== false) {
            return;
        }

        $relationshipsStub = File::get($this->stubPath . '/user-relationships.php.stub');

        // Step 1: Add traits to the "use" line inside the class BEFORE adding imports
        // (Adding imports first would cause strpos checks to find the class name in the import line)

        // Add HasApiTokens to the use traits line
        if (strpos($userModelContent, 'HasApiTokens') === false) {
            $userModelContent = preg_replace(
                '/(use\s+HasFactory(?:,\s*\w+)*)(;)/',
                '$1, HasApiTokens$2',
                $userModelContent,
                1
            );
        }

        // Add HasPermissions to the use traits line
        if (strpos($userModelContent, 'HasPermissions') === false) {
            $userModelContent = preg_replace(
                '/(use\s+HasFactory(?:,\s*\w+)*)(;)/',
                '$1, HasPermissions$2',
                $userModelContent,
                1
            );
        }

        // Step 2: Add "implements HasRoleBasedValidation" to class declaration
        $userModelContent = preg_replace(
            '/(class User extends Authenticatable)(?!\s+implements)/',
            '$1 implements HasRoleBasedValidation',
            $userModelContent
        );

        // Step 3: Add namespace imports
        if (strpos($userModelContent, 'use App\Models\Organization') === false) {
            $userModelContent = str_replace(
                'namespace App\Models;',
                "namespace App\Models;\n\nuse App\Models\Organization;\nuse App\Models\Role;",
                $userModelContent
            );
        }

        if (strpos($userModelContent, 'Laravel\Sanctum\HasApiTokens') === false) {
            $userModelContent = str_replace(
                'namespace App\Models;',
                "namespace App\Models;\n\nuse Laravel\Sanctum\HasApiTokens;",
                $userModelContent
            );
        }

        if (strpos($userModelContent, 'Rhino\Traits\HasPermissions') === false) {
            $userModelContent = str_replace(
                'namespace App\Models;',
                "namespace App\Models;\n\nuse Rhino\Traits\HasPermissions;",
                $userModelContent
            );
        }

        if (strpos($userModelContent, 'Rhino\Contracts\HasRoleBasedValidation') === false) {
            $userModelContent = str_replace(
                'namespace App\Models;',
                "namespace App\Models;\n\nuse Rhino\Contracts\HasRoleBasedValidation;",
                $userModelContent
            );
        }

        // Step 4: Append relationship methods
        $userModelContent = preg_replace(
            '/(\n\})$/',
            "\n" . $relationshipsStub . '$1',
            $userModelContent
        );

        File::put($userModelPath, $userModelContent);
    }

    protected function updateAppServiceProvider(): void
    {
        $providerPath = app_path('Providers/AppServiceProvider.php');

        if (!File::exists($providerPath)) {
            return;
        }

        $content = File::get($providerPath);

        // Skip if already configured
        if (strpos($content, 'guessPolicyNamesUsing') !== false) {
            return;
        }

        // Add Gate import if not present
        if (strpos($content, 'use Illuminate\Support\Facades\Gate') === false) {
            $content = str_replace(
                'use Illuminate\Support\ServiceProvider;',
                "use Illuminate\Support\Facades\Gate;\nuse Illuminate\Support\ServiceProvider;",
                $content
            );
        }

        // Add the policy discovery to the boot() method
        $policyDiscovery = "\n        Gate::guessPolicyNamesUsing(function (\$modelClass) {\n"
            . "            return 'App\\\\Policies\\\\' . class_basename(\$modelClass) . 'Policy';\n"
            . "        });\n";

        $content = preg_replace_callback(
            '/(public function boot\(\)(?::\s*void)?\s*\{)/',
            function ($matches) use ($policyDiscovery) {
                return $matches[1] . $policyDiscovery;
            },
            $content
        );

        File::put($providerPath, $content);
    }

    protected function createSeeders(array $roles = ['admin']): void
    {
        $seedersPath = database_path('seeders');

        File::ensureDirectoryExists($seedersPath);

        // Generate RoleSeeder with all roles
        $this->generateRoleSeeder($seedersPath, $roles);

        File::copy(
            $this->stubPath . '/seeders/OrganizationSeeder.php.stub',
            $seedersPath . '/OrganizationSeeder.php'
        );

        File::copy(
            $this->stubPath . '/seeders/UserRoleSeeder.php.stub',
            $seedersPath . '/UserRoleSeeder.php'
        );

        $this->updateDatabaseSeeder();
    }

    protected function generateRoleSeeder(string $seedersPath, array $roles): void
    {
        $seedLines = [];

        foreach ($roles as $role) {
            $name = ucfirst($role);
            $description = match ($role) {
                'admin' => 'Administrator role with full access',
                'editor' => 'Editor role with create, read, and update access',
                'viewer' => 'Viewer role with read-only access',
                'writer' => 'Writer role with create and edit access',
                default => ucfirst($role) . ' role',
            };

            $seedLines[] = "        Role::firstOrCreate(\n"
                . "            ['slug' => '{$role}'],\n"
                . "            [\n"
                . "                'name' => '{$name}',\n"
                . "                'description' => '{$description}',\n"
                . "            ]\n"
                . "        );";
        }

        $content = "<?php\n\nnamespace Database\\Seeders;\n\n"
            . "use App\\Models\\Role;\n"
            . "use Illuminate\\Database\\Seeder;\n\n"
            . "class RoleSeeder extends Seeder\n{\n"
            . "    /**\n     * Run the database seeds.\n     */\n"
            . "    public function run(): void\n    {\n"
            . implode("\n\n", $seedLines) . "\n"
            . "    }\n}\n";

        File::put($seedersPath . '/RoleSeeder.php', $content);
    }

    protected function updateDatabaseSeeder(): void
    {
        $databaseSeederPath = database_path('seeders/DatabaseSeeder.php');

        if (!File::exists($databaseSeederPath)) {
            return;
        }

        $databaseSeederContent = File::get($databaseSeederPath);

        if (
            strpos($databaseSeederContent, 'RoleSeeder') !== false &&
            strpos($databaseSeederContent, 'OrganizationSeeder') !== false &&
            strpos($databaseSeederContent, 'UserRoleSeeder') !== false
        ) {
            return;
        }

        if (preg_match('/(public function run\(\): void\s*\{[^}]*?)(\s*\})/s', $databaseSeederContent, $matches)) {
            $beforeClosingBrace = $matches[1];
            $closingBrace = $matches[2];

            $seedersCall = "\n        \$this->call([\n            RoleSeeder::class,\n            OrganizationSeeder::class,\n            UserRoleSeeder::class,\n        ]);";

            $databaseSeederContent = str_replace(
                $matches[0],
                $beforeClosingBrace . $seedersCall . $closingBrace,
                $databaseSeederContent
            );
        } else {
            $databaseSeederContent = preg_replace(
                '/(public function run\(\): void\s*\{[^}]*)(\})/s',
                '$1' . "\n        \$this->call([\n            RoleSeeder::class,\n            OrganizationSeeder::class,\n            UserRoleSeeder::class,\n        ]);\n" . '$2',
                $databaseSeederContent
            );
        }

        File::put($databaseSeederPath, $databaseSeederContent);
    }

    // ------------------------------------------------------------------
    // Blueprint directory
    // ------------------------------------------------------------------

    protected function installBlueprintDirectory(): void
    {
        $rhinoDir = base_path('.rhino');
        $blueprintsDir = $rhinoDir . '/blueprints';
        $blueprintMd = $rhinoDir . '/BLUEPRINT.md';
        $stubPath = __DIR__ . '/../../stubs/blueprint/BLUEPRINT.md.stub';

        $this->components->task('Creating .rhino directory', function () use ($rhinoDir, $blueprintsDir) {
            File::ensureDirectoryExists($blueprintsDir);
        });

        if (!File::exists($blueprintMd) && File::exists($stubPath)) {
            $this->components->task('Publishing BLUEPRINT.md (AI guide)', function () use ($blueprintMd, $stubPath) {
                File::copy($stubPath, $blueprintMd);
            });
        }
    }

    // ------------------------------------------------------------------
    // Audit trail
    // ------------------------------------------------------------------

    protected function installAuditTrail(): void
    {
        $this->components->task('Creating audit trail migration', function () {
            $stubPath = __DIR__ . '/../../stubs/audit-trail/migrations/create_audit_logs_table.php.stub';
            $migrationsPath = database_path('migrations');
            $timestamp = now()->format('Y_m_d_His');

            $existingMigrations = glob($migrationsPath . '/*_create_audit_logs_table.php');
            if (!empty($existingMigrations)) {
                return;
            }

            File::copy(
                $stubPath,
                $migrationsPath . "/{$timestamp}_create_audit_logs_table.php"
            );
        });
    }

    // ------------------------------------------------------------------
    // Post-install steps
    // ------------------------------------------------------------------

    protected function runPostInstallSteps(array $features): void
    {
        $hasMigrations = in_array('multi_tenant', $features) || in_array('audit_trail', $features);

        if ($hasMigrations) {
            $runMigrate = confirm(
                label: 'Would you like to run migrations now?',
                default: true,
            );

            if ($runMigrate) {
                $this->components->task('Running migrations', function () {
                    $this->callSilently('migrate');
                });
            }
        }

        if (in_array('multi_tenant', $features)) {
            $runSeed = confirm(
                label: 'Would you like to seed the database? (Roles, Organizations, UserRoles)',
                default: true,
            );

            if ($runSeed) {
                $this->components->task('Seeding database', function () {
                    $this->callSilently('db:seed');
                });
            }

            $configureBootstrap = confirm(
                label: 'Would you like to configure bootstrap/app.php? (API routes, middleware, exception handlers)',
                default: true,
            );

            if ($configureBootstrap) {
                $this->components->task('Configuring bootstrap/app.php', function () {
                    $this->overwriteBootstrapApp();
                });
            }
        }
    }

    protected function overwriteBootstrapApp(): void
    {
        $bootstrapPath = base_path('bootstrap/app.php');

        $middlewareClass = '\\Rhino\\Http\\Middleware\\ResolveOrganizationFromRoute';

        $stubPath = $this->stubPath ?? __DIR__ . '/../../stubs/multi-tenant';
        $stub = File::get($stubPath . '/bootstrap/app.php.stub');
        $content = str_replace('{{ middlewareClass }}', $middlewareClass, $stub);

        File::put($bootstrapPath, $content);
    }

    // ------------------------------------------------------------------
    // Next steps (remaining manual steps)
    // ------------------------------------------------------------------

    protected function printNextSteps(array $features): void
    {
        $this->components->info('Remaining steps:');
        $this->newLine();

        $step = 1;

        if (in_array('audit_trail', $features)) {
            $this->line("  <fg=yellow>{$step}.</> Add <fg=white>HasAuditTrail</> trait to your models:");
            $this->line('     <fg=gray>use Rhino\Traits\HasAuditTrail;</>');
            $step++;
        }

        $this->line("  <fg=yellow>{$step}.</> Create blueprint YAML files in <fg=white>.rhino/blueprints/</>");
        $this->line('     <fg=gray>See .rhino/BLUEPRINT.md for the complete format guide</>');
        $step++;
        $this->line("  <fg=yellow>{$step}.</> Generate code: <fg=white>php artisan rhino:blueprint</>");

        $this->newLine();
    }

    // ------------------------------------------------------------------
    // AI Skill
    // ------------------------------------------------------------------

    protected function installAiSkill(): void
    {
        $this->newLine();

        $installSkills = confirm(
            label: 'Install Rhino AI skills for Claude Code?',
            default: true,
            hint: 'Copies 13 slash commands to .claude/commands/ (always overwritten with latest version)',
        );

        if (! $installSkills) {
            return;
        }

        $skillsSourceDir = dirname(__DIR__, 2) . '/stubs/skills';
        $destDir = base_path('.claude/commands');

        File::ensureDirectoryExists($destDir);

        $skillFiles = File::glob($skillsSourceDir . '/rhino-*.md');

        if (empty($skillFiles)) {
            warning('No skill files found in package stubs. This may indicate a broken installation.');
            return;
        }

        $installed = 0;

        foreach ($skillFiles as $skillFile) {
            $filename = basename($skillFile);
            $destFile = $destDir . '/' . $filename;

            $this->components->task("Installing /rhino:" . str_replace(['rhino-', '.md'], '', $filename), function () use ($skillFile, $destFile) {
                File::copy($skillFile, $destFile);
            });

            $installed++;
        }

        // Also install the legacy SKILL.md for Cursor/AI tools if requested
        $alsoLegacy = confirm(
            label: 'Also install the full Rhino reference for Cursor or other AI tools?',
            default: false,
            hint: 'Downloads the complete reference file for non-Claude tools',
        );

        if ($alsoLegacy) {
            $legacyTools = multiselect(
                label: 'Which tools?',
                options: [
                    'cursor' => 'Cursor (.cursor/rules/rhino/)',
                    'ai' => 'AI Directory (.ai/skills/rhino/)',
                ],
                default: ['cursor'],
            );

            if (! empty($legacyTools)) {
                $url = 'https://agent-code.dev/skills/server/SKILL.md';
                $content = @file_get_contents($url);

                if ($content === false) {
                    warning('Could not download reference file. You can manually download it from:');
                    $this->line("  <fg=gray>{$url}</>");
                } else {
                    $destinations = [
                        'cursor' => '.cursor/rules/rhino/SKILL.md',
                        'ai' => '.ai/skills/rhino/SKILL.md',
                    ];

                    foreach ($legacyTools as $tool) {
                        $destFile = base_path($destinations[$tool]);

                        $this->components->task("Installing reference for {$tool}", function () use ($destFile, $content) {
                            File::ensureDirectoryExists(dirname($destFile));
                            File::put($destFile, $content);
                        });
                    }
                }
            }
        }

        info("{$installed} Rhino skills installed to .claude/commands/");
        $this->line('  <fg=gray>Run any skill in Claude Code: /rhino:feature, /rhino:review, etc.</>');
    }

    protected function arrayToShortSyntax(array $array, int $depth = 1): string
    {
        $indent = str_repeat('    ', $depth);
        $closingIndent = str_repeat('    ', $depth - 1);
        $lines = [];

        $isAssoc = array_keys($array) !== range(0, count($array) - 1);

        foreach ($array as $key => $value) {
            $exportedValue = match (true) {
                is_array($value) => $this->arrayToShortSyntax($value, $depth + 1),
                is_bool($value) => $value ? 'true' : 'false',
                is_null($value) => 'null',
                is_int($value) || is_float($value) => (string) $value,
                is_string($value) && preg_match('/^[A-Z][A-Za-z0-9]*(\\\\[A-Z][A-Za-z0-9]*)+$/', $value) => '\\' . $value . '::class',
                default => "'" . addslashes((string) $value) . "'",
            };

            if ($isAssoc) {
                $exportedKey = is_int($key) ? $key : "'" . addslashes($key) . "'";
                $lines[] = "{$indent}{$exportedKey} => {$exportedValue},";
            } else {
                $lines[] = "{$indent}{$exportedValue},";
            }
        }

        if (empty($lines)) {
            return '[]';
        }

        return "[\n" . implode("\n", $lines) . "\n{$closingIndent}]";
    }
}
