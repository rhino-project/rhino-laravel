<?php

namespace Rhino\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;

use function Laravel\Prompts\confirm;
use function Laravel\Prompts\info;
use function Laravel\Prompts\multiselect;
use function Laravel\Prompts\select;
use function Laravel\Prompts\text;
use function Laravel\Prompts\warning;

class GenerateCommand extends Command
{
    protected $signature = 'rhino:generate';

    protected $description = 'Generate Rhino resources (Model, Policy, Scope)';

    protected $aliases = ['rhino:g'];

    protected $stubPath;

    public function handle(): int
    {
        $this->stubPath = __DIR__ . '/../../stubs/generate';

        $this->printStyledHeader();

        $type = select(
            label: 'What type of resource would you like to generate?',
            options: [
                'model' => 'Model (with migration and factory)',
                'policy' => 'Policy (extends ResourcePolicy)',
                'scope' => 'Scope (for ScopedDB)',
            ],
        );

        $name = text(
            label: 'What is the resource name?',
            placeholder: 'e.g., Post, BlogCategory',
            required: true,
            hint: 'Use PascalCase singular (e.g., "Post", not "posts")',
            validate: fn (string $value) => preg_match('/^[A-Za-z][A-Za-z0-9]*$/', $value)
                ? null
                : 'The name must start with a letter and contain only alphanumeric characters.',
        );

        $name = Str::studly($name);

        return match ($type) {
            'model' => $this->generateModel($name),
            'policy' => $this->generatePolicy($name),
            'scope' => $this->generateScope($name),
        };
    }

    // ------------------------------------------------------------------
    // Display helpers
    // ------------------------------------------------------------------

    protected function printStyledHeader(): void
    {
        $cyan = "\033[38;2;0;255;255m";
        $dimCyan = "\033[38;2;0;140;140m";
        $headerBg = "\033[48;2;22;27;34m";
        $reset = "\033[0m";

        $text = '+ Rhino :: Generate :: Scaffold your resources +';
        $pad = 4;
        $innerWidth = mb_strlen($text) + ($pad * 2);

        $this->newLine();
        $this->output->writeln("  {$dimCyan}{$headerBg} ┌" . str_repeat('─', $innerWidth) . "┐ {$reset}");
        $this->output->writeln("  {$dimCyan}{$headerBg} │" . str_repeat(' ', $pad) . "{$cyan}{$text}{$dimCyan}" . str_repeat(' ', $pad) . "│ {$reset}");
        $this->output->writeln("  {$dimCyan}{$headerBg} └" . str_repeat('─', $innerWidth) . "┘ {$reset}");
        $this->newLine();
    }

    protected function printSelections(string $type, string $name, ?int $columnCount = null): void
    {
        $green = "\033[38;2;40;200;64m";
        $cyan = "\033[38;2;0;255;255m";
        $gray = "\033[38;2;139;148;158m";
        $reset = "\033[0m";

        $typeLabel = match ($type) {
            'model' => 'Model',
            'policy' => 'Policy',
            'scope' => 'Scope',
            default => ucfirst($type),
        };

        $this->newLine();
        $line = "  {$green}✓{$reset} {$gray}Type:{$reset} {$cyan}{$typeLabel}{$reset}  {$green}✓{$reset} {$gray}Name:{$reset} {$cyan}{$name}{$reset}";

        if ($columnCount !== null) {
            $line .= "  {$green}✓{$reset} {$gray}Columns:{$reset} {$cyan}{$columnCount} defined{$reset}";
        }

        $this->output->writeln($line);
        $this->newLine();
    }

    protected function printColumnsSummary(array $columns): void
    {
        if (empty($columns)) {
            return;
        }

        $cyan = "\033[38;2;0;255;255m";
        $gray = "\033[38;2;139;148;158m";
        $muted = "\033[38;2;72;79;88m";
        $white = "\033[38;2;201;209;217m";
        $border = "\033[38;2;48;54;61m";
        $headerBg = "\033[48;2;22;27;34m";
        $rowBg = "\033[48;2;13;17;23m";
        $reset = "\033[0m";

        $nameMax = max(6, ...array_map(fn ($c) => mb_strlen($c['name']), $columns));
        $typeMax = max(4, ...array_map(fn ($c) => mb_strlen($this->getColumnTypeDisplay($c)), $columns));
        $modMax = max(8, ...array_map(fn ($c) => mb_strlen($this->getColumnModifier($c)), $columns));

        $colW = $nameMax + 6;
        $typeW = $typeMax + 4;
        $modW = $modMax + 2;

        $this->output->writeln("  {$gray}Define your columns:{$reset}");
        $this->newLine();

        // Top border
        $this->output->writeln("  {$border}┌" . str_repeat('─', $colW + 2) . '┬' . str_repeat('─', $typeW + 2) . '┬' . str_repeat('─', $modW + 2) . "┐{$reset}");

        // Header row
        $this->output->writeln(
            "  {$border}│{$headerBg} {$muted}" . str_pad('COLUMN', $colW + 1)
            . "{$border}│{$headerBg} {$muted}" . str_pad('TYPE', $typeW + 1)
            . "{$border}│{$headerBg} {$muted}" . str_pad('MODIFIER', $modW + 1)
            . "{$border}│{$reset}"
        );

        // Header separator
        $this->output->writeln("  {$border}├" . str_repeat('─', $colW + 2) . '┼' . str_repeat('─', $typeW + 2) . '┼' . str_repeat('─', $modW + 2) . "┤{$reset}");

        // Rows
        foreach ($columns as $col) {
            $typeDisplay = $this->getColumnTypeDisplay($col);
            $modifier = $this->getColumnModifier($col);

            $this->output->writeln(
                "  {$border}│{$rowBg} {$cyan}+ {$white}" . str_pad($col['name'], $colW - 1)
                . "{$border}│{$rowBg} {$cyan}" . str_pad($typeDisplay, $typeW + 1)
                . "{$border}│{$rowBg} {$gray}" . str_pad($modifier, $modW + 1)
                . "{$border}│{$reset}"
            );
        }

        // Bottom border
        $this->output->writeln("  {$border}└" . str_repeat('─', $colW + 2) . '┴' . str_repeat('─', $typeW + 2) . '┴' . str_repeat('─', $modW + 2) . "┘{$reset}");

        $this->newLine();
    }

    protected function getColumnTypeDisplay(array $column): string
    {
        return match ($column['type']) {
            'decimal' => 'decimal(8,2)',
            default => $column['type'],
        };
    }

    protected function getColumnModifier(array $column): string
    {
        $parts = [];

        if ($column['type'] === 'foreignId') {
            $parts[] = 'constrained';
        }

        if ($column['nullable']) {
            $parts[] = 'nullable';
        } elseif ($column['type'] !== 'foreignId') {
            $parts[] = 'required';
        }

        if (!empty($column['unique'])) {
            $parts[] = 'unique';
        }

        if ($column['default'] !== null) {
            $parts[] = "default:{$column['default']}";
        }

        return implode(', ', $parts);
    }

    // ------------------------------------------------------------------
    // Stub helpers
    // ------------------------------------------------------------------

    protected function getStub(string $name): string
    {
        return File::get("{$this->stubPath}/{$name}.php.stub");
    }

    protected function replacePlaceholders(string $stub, array $replacements): string
    {
        foreach ($replacements as $key => $value) {
            $stub = str_replace("{{ {$key} }}", $value, $stub);
        }

        return $stub;
    }

    // ------------------------------------------------------------------
    // Model generation
    // ------------------------------------------------------------------

    protected function generateModel(string $name): int
    {
        $modelPath = app_path("Models/{$name}.php");

        if (File::exists($modelPath)) {
            warning("Model {$name} already exists at {$modelPath}.");
            if (!confirm('Do you want to overwrite it?', default: false)) {
                info('Model generation cancelled.');
                return 0;
            }
        }

        $this->components->task("Creating {$name} model, migration, and factory", function () use ($name) {
            $this->callSilently('make:model', [
                'name' => $name,
                '-m' => true,
                '-f' => true,
            ]);
        });

        // Multi-tenant: check organization ownership
        $belongsToOrg = false;
        $ownerRelation = null;
        $isMultiTenant = $this->isMultiTenantEnabled();

        if ($isMultiTenant) {
            $belongsToOrg = confirm(
                label: 'Does this model belong to an organization?',
                default: true,
                hint: 'If yes, an organization_id column will be added and the BelongsToOrganization trait enabled',
            );

            if (!$belongsToOrg) {
                $existingModels = $this->getExistingModels();

                if (!empty($existingModels)) {
                    $hasParent = confirm(
                        label: 'Does this model have a parent that belongs to an organization?',
                        default: false,
                        hint: 'Models without a parent owner will be available as global models for all organizations',
                    );

                    if ($hasParent) {
                        $ownerModel = select(
                            label: 'Which model is the parent owner?',
                            options: $existingModels,
                        );
                        $ownerRelation = Str::camel($ownerModel);
                    } else {
                        info('This model will be a global model, available across all organizations.');
                    }
                } else {
                    info('This model will be a global model, available across all organizations.');
                }
            }
        }

        // Collect columns
        $columns = [];
        $defineColumns = confirm(
            label: 'Would you like to define columns interactively?',
            default: true,
            hint: 'This will generate $fillable, validation rules, migration columns, and factory definitions',
        );

        if ($defineColumns) {
            $columns = $this->collectColumns($name);
        }

        // Auto-add organization_id FK when model belongs to organization
        if ($belongsToOrg) {
            array_unshift($columns, [
                'name' => 'organization_id',
                'type' => 'foreignId',
                'nullable' => false,
                'unique' => false,
                'index' => true,
                'default' => null,
                'foreignModel' => 'Organization',
            ]);
        }

        // Auto-add owner FK when model has a parent owner (e.g., blog_id)
        if ($ownerRelation) {
            $ownerModel = Str::studly($ownerRelation);
            $ownerFk = Str::snake($ownerRelation) . '_id';

            // Only add if user didn't already define this column interactively
            $alreadyDefined = collect($columns)->contains(fn ($col) => $col['name'] === $ownerFk);

            if (!$alreadyDefined) {
                array_unshift($columns, [
                    'name' => $ownerFk,
                    'type' => 'foreignId',
                    'nullable' => false,
                    'unique' => false,
                    'index' => true,
                    'default' => null,
                    'foreignModel' => $ownerModel,
                ]);
            }
        }

        $this->printColumnsSummary($columns);
        $this->printSelections('model', $name, count($columns));

        // Additional options
        $options = $this->collectAdditionalOptions();

        // Role access configuration (only if policy is selected)
        $roleAccess = [];
        if ($options['policy']) {
            $roleAccess = $this->collectRoleAccess($name);
        }

        // Generate model file
        $this->components->task("Enhancing {$name} model with Rhino traits", function () use ($name, $columns, $belongsToOrg, $ownerRelation, $options) {
            $this->writeModelFile($name, $columns, $belongsToOrg, $ownerRelation, $options['soft_deletes'], $options['audit_trail']);
        });

        // Update migration & factory
        if (!empty($columns)) {
            $this->components->task('Updating migration with column definitions', function () use ($name, $columns, $options) {
                $this->updateMigrationFile($name, $columns, $options['soft_deletes']);
            });

            $this->components->task('Updating factory with faker definitions', function () use ($name, $columns) {
                $this->updateFactoryFile($name, $columns);
            });
        }

        // Register in config
        $this->components->task("Registering {$name} in config/rhino.php", function () use ($name) {
            $this->registerModelInConfig($name);
        });

        // Generate policy
        if ($options['policy']) {
            $this->components->task("Generating {$name}Policy", function () use ($name) {
                $this->createPolicyFile($name);
            });
        }

        // Generate seeder
        if ($options['factory_seeder']) {
            $this->components->task("Generating {$name}Seeder", function () use ($name, $belongsToOrg, $ownerRelation) {
                $this->createSeederFile($name, $belongsToOrg, $ownerRelation);
            });

            $this->components->task("Registering {$name}Seeder in DatabaseSeeder", function () use ($name) {
                $this->addSeederToDatabaseSeeder($name);
            });
        }

        // Generate scope
        $this->components->task("Generating {$name}Scope", function () use ($name) {
            $this->createScopeFile($name);
        });

        // Generate tests
        $this->components->task("Generating tests/Model/{$name}Test.php", function () use ($name, $columns, $roleAccess, $isMultiTenant) {
            $this->generateTestFile($name, $columns, $roleAccess, $isMultiTenant);
        });

        $this->newLine();
        info("{$name} model generated successfully!");
        $this->printCreatedFiles($name, $columns, $options);
        $this->printModelNextSteps($name, $options);

        // Print permission tips for non-admin roles
        if ($isMultiTenant && $options['policy'] && !empty($roleAccess)) {
            $slug = Str::snake(Str::plural($name));
            $this->newLine();
            $this->components->info('Permission tips:');
            $this->line("  <fg=gray>Admin users have wildcard '*' — no changes needed.</>");
            foreach ($roleAccess as $role => $access) {
                if ($role === 'admin') {
                    continue;
                }
                $perms = $this->roleAccessToPermissions($slug, $access);
                if (!empty($perms)) {
                    $permStr = implode(', ', $perms);
                    $this->line("  <fg=yellow>{$role}:</> <fg=white>{$permStr}</>");
                } else {
                    $this->line("  <fg=yellow>{$role}:</> <fg=gray>no access</>");
                }
            }
            $this->line("  <fg=gray>Add these to the permissions array in UserRoleSeeder.</>");
            $this->newLine();
        }

        return 0;
    }

    protected function printCreatedFiles(string $name, array $columns, array $options = []): void
    {
        $tableName = Str::snake(Str::plural($name));

        $this->newLine();
        $this->components->info('Created files:');
        $this->newLine();
        $this->line("  <fg=gray>Model</>       <fg=white>app/Models/{$name}.php</>");

        $migrationFile = $this->findMigrationFile($tableName);
        if ($migrationFile) {
            $this->line("  <fg=gray>Migration</>   <fg=white>database/migrations/" . basename($migrationFile) . '</>' );
        }

        if (!empty($columns)) {
            $this->line("  <fg=gray>Factory</>     <fg=white>database/factories/{$name}Factory.php</>");
        }

        $this->line("  <fg=gray>Config</>      <fg=white>config/rhino.php</> <fg=gray>(registered as '{$tableName}')</>");

        if (!empty($options['policy'])) {
            $this->line("  <fg=gray>Policy</>      <fg=white>app/Policies/{$name}Policy.php</>");
        }

        if (!empty($options['factory_seeder'])) {
            $this->line("  <fg=gray>Seeder</>      <fg=white>database/seeders/{$name}Seeder.php</>");
        }

        $this->line("  <fg=gray>Scope</>       <fg=white>app/Models/Scopes/{$name}Scope.php</>");
        $this->line("  <fg=gray>Test</>        <fg=white>tests/Model/{$name}Test.php</>");
    }

    protected function findMigrationFile(string $tableName): ?string
    {
        $files = glob(database_path("migrations/*_create_{$tableName}_table.php"));

        return !empty($files) ? end($files) : null;
    }

    protected function isMultiTenantEnabled(): bool
    {
        $configPath = config_path('rhino.php');

        if (!File::exists($configPath)) {
            return false;
        }

        $config = require $configPath;

        return isset($config['route_groups']['tenant']);
    }

    protected function getOrganizationIdentifierColumn(): string
    {
        $configPath = config_path('rhino.php');

        if (!File::exists($configPath)) {
            return 'id';
        }

        $config = require $configPath;

        return $config['multi_tenant']['organization_identifier_column'] ?? 'id';
    }

    protected function collectColumns(string $modelName): array
    {
        $columns = [];
        $existingModels = $this->getExistingModels();

        do {
            $columnName = text(
                label: 'Column name',
                placeholder: 'e.g., title, user_id, is_published',
                required: true,
                validate: fn (string $value) => preg_match('/^[a-z][a-z0-9_]*$/', $value)
                    ? null
                    : 'Column name must be snake_case and start with a lowercase letter.',
            );

            $columnType = select(
                label: "Column type for '{$columnName}'",
                options: [
                    'string' => 'string (VARCHAR 255)',
                    'text' => 'text (TEXT)',
                    'integer' => 'integer',
                    'bigInteger' => 'bigInteger',
                    'boolean' => 'boolean',
                    'date' => 'date',
                    'datetime' => 'datetime',
                    'timestamp' => 'timestamp',
                    'decimal' => 'decimal (8, 2)',
                    'float' => 'float',
                    'json' => 'json',
                    'uuid' => 'uuid',
                    'foreignId' => 'foreignId (foreign key)',
                ],
            );

            $column = [
                'name' => $columnName,
                'type' => $columnType,
                'nullable' => false,
                'unique' => false,
                'index' => false,
                'default' => null,
                'foreignModel' => null,
            ];

            if ($columnType === 'foreignId') {
                if (!empty($existingModels)) {
                    $foreignModel = select(
                        label: "Which model does '{$columnName}' reference?",
                        options: $existingModels,
                    );
                    $column['foreignModel'] = $foreignModel;
                } else {
                    warning('No existing models found in app/Models/. The foreign key will use ->constrained() with Laravel convention.');
                }
            }

            $column['nullable'] = confirm(
                label: "Is '{$columnName}' nullable?",
                default: false,
                hint: $columnType === 'foreignId' ? 'Nullable foreign keys allow optional relationships' : '',
            );

            $column['unique'] = confirm(
                label: "Should '{$columnName}' be unique?",
                default: false,
            );

            $column['index'] = confirm(
                label: "Should '{$columnName}' have an index?",
                default: false,
            );

            if ($columnType !== 'foreignId') {
                $defaultValue = text(
                    label: "Default value for '{$columnName}'? (leave empty for none)",
                    default: '',
                    hint: $columnType === 'boolean' ? 'Use true or false' : '',
                );
                $column['default'] = $defaultValue !== '' ? $defaultValue : null;
            }

            $columns[] = $column;

            $addMore = confirm(
                label: 'Add another column?',
                default: true,
            );
        } while ($addMore);

        return $columns;
    }

    protected function collectAdditionalOptions(): array
    {
        $selected = multiselect(
            label: 'Additional options',
            options: [
                'soft_deletes' => 'Add soft deletes',
                'policy' => 'Generate policy',
                'factory_seeder' => 'Generate factory & seeder',
                'audit_trail' => 'Add audit trail',
            ],
            default: ['soft_deletes', 'policy', 'factory_seeder'],
            hint: 'Space to toggle, Enter to confirm',
        );

        return [
            'soft_deletes' => in_array('soft_deletes', $selected),
            'policy' => in_array('policy', $selected),
            'factory_seeder' => in_array('factory_seeder', $selected),
            'audit_trail' => in_array('audit_trail', $selected),
        ];
    }

    // ------------------------------------------------------------------
    // Role access
    // ------------------------------------------------------------------

    protected function getRolesFromRoleModel(): array
    {
        $rolePath = app_path('Models/Role.php');

        if (!File::exists($rolePath)) {
            return [];
        }

        $content = File::get($rolePath);

        if (preg_match('/\$roles\s*=\s*\[(.*?)\]/s', $content, $matches)) {
            preg_match_all("/['\"]([^'\"]+)['\"]/", $matches[1], $roleMatches);
            return $roleMatches[1] ?? [];
        }

        return [];
    }

    protected function collectRoleAccess(string $name): array
    {
        $roles = $this->getRolesFromRoleModel();

        if (empty($roles)) {
            return [];
        }

        $slug = Str::snake(Str::plural($name));
        $roleAccess = [];

        // Admin always gets editor (all actions)
        $roles = array_filter($roles, fn ($role) => $role !== 'admin');

        if (empty($roles)) {
            return [];
        }

        $this->newLine();
        $this->output->writeln("  \033[38;2;139;148;158mDefine role access for \033[38;2;0;255;255m{$slug}\033[38;2;139;148;158m:\033[0m");
        $this->newLine();

        foreach ($roles as $role) {
            $access = select(
                label: "Access level for '{$role}'",
                options: [
                    'editor' => 'Editor — all actions on this model',
                    'viewer' => 'Viewer — read-only (index, show)',
                    'writer' => 'Writer — create & edit (index, show, store, update)',
                    'none' => 'No access',
                ],
                hint: "Permissions for the {$role} role on {$slug}",
            );

            $roleAccess[$role] = $access;
        }

        // Admin is always editor
        $roleAccess = array_merge(['admin' => 'editor'], $roleAccess);

        $this->printRoleAccessSummary($name, $roleAccess);

        return $roleAccess;
    }

    protected function roleAccessToPermissions(string $slug, string $access): array
    {
        return match ($access) {
            'editor' => ["{$slug}.*"],
            'viewer' => ["{$slug}.index", "{$slug}.show"],
            'writer' => ["{$slug}.index", "{$slug}.show", "{$slug}.store", "{$slug}.update"],
            'none' => [],
            default => [],
        };
    }

    protected function printRoleAccessSummary(string $name, array $roleAccess): void
    {
        $slug = Str::snake(Str::plural($name));
        $cyan = "\033[38;2;0;255;255m";
        $green = "\033[38;2;40;200;64m";
        $gray = "\033[38;2;139;148;158m";
        $white = "\033[38;2;201;209;217m";
        $yellow = "\033[38;2;255;220;50m";
        $red = "\033[38;2;255;85;85m";
        $reset = "\033[0m";

        $this->newLine();
        $this->output->writeln("  {$gray}Role access summary:{$reset}");
        $this->newLine();

        $maxRoleLen = max(array_map('mb_strlen', array_keys($roleAccess)));

        foreach ($roleAccess as $role => $access) {
            $paddedRole = str_pad($role, $maxRoleLen);
            $permissions = $this->roleAccessToPermissions($slug, $access);

            $color = match ($access) {
                'editor' => $green,
                'viewer' => $cyan,
                'writer' => $yellow,
                'none' => $red,
            };

            $label = match ($access) {
                'editor' => '# all actions',
                'viewer' => '# read-only',
                'writer' => '# create & edit',
                'none' => '# no access',
            };

            if (empty($permissions)) {
                $this->output->writeln("  {$white}{$paddedRole}{$reset}  {$gray}→{$reset}  {$red}—{$reset}  {$gray}{$label}{$reset}");
            } else {
                $permStr = implode(', ', array_map(fn ($p) => "{$color}\"{$p}\"{$reset}", $permissions));
                $this->output->writeln("  {$white}{$paddedRole}{$reset}  {$gray}→{$reset}  {$permStr}  {$gray}{$label}{$reset}");
            }
        }

        $this->newLine();
    }

    protected function getExistingModels(): array
    {
        $modelsPath = app_path('Models');

        if (!File::isDirectory($modelsPath)) {
            return [];
        }

        $models = [];

        foreach (File::files($modelsPath) as $file) {
            if ($file->getExtension() !== 'php') {
                continue;
            }

            $filename = $file->getFilenameWithoutExtension();
            $tableName = Str::snake(Str::plural($filename));
            $models[$filename] = "{$filename} ({$tableName} table)";
        }

        return $models;
    }

    protected function writeModelFile(string $name, array $columns, bool $belongsToOrg = false, ?string $ownerRelation = null, bool $includeSoftDeletes = true, bool $includeAuditTrail = false): void
    {
        $modelPath = app_path("Models/{$name}.php");
        $tableName = Str::snake(Str::plural($name));

        $fillableColumns = array_map(fn ($col) => $col['name'], $columns);

        $validationRules = [];
        foreach ($columns as $col) {
            $validationRules[$col['name']] = $this->columnToValidationRule($col, $tableName);
        }

        $ruleFields = array_map(fn ($col) => $col['name'], $columns);

        $filterColumns = array_filter($columns, fn ($col) => !in_array($col['type'], ['text', 'json']));
        $filterNames = array_values(array_map(fn ($col) => $col['name'], $filterColumns));

        $sortColumns = array_filter($columns, fn ($col) => !in_array($col['type'], ['text', 'json']));
        $sortNames = array_values(array_unique(array_merge(
            array_map(fn ($col) => $col['name'], $sortColumns),
            ['created_at']
        )));

        $allFieldNames = array_values(array_unique(array_merge(['id'], $fillableColumns, ['created_at'])));

        $includeNames = [];
        foreach ($columns as $col) {
            if ($col['type'] === 'foreignId' && $col['foreignModel']) {
                $includeNames[] = Str::camel(Str::replaceLast('_id', '', $col['name']));
            }
        }

        // Build casts for JSON columns
        $jsonCasts = [];
        foreach ($columns as $col) {
            if ($col['type'] === 'json') {
                $jsonCasts[$col['name']] = 'array';
            }
        }

        // Build FK imports (skip Organization if BelongsToOrganization trait handles it)
        $imports = '';
        foreach ($columns as $col) {
            if ($col['type'] === 'foreignId' && $col['foreignModel']) {
                if ($belongsToOrg && $col['foreignModel'] === 'Organization') {
                    continue;
                }
                $imports .= "use App\\Models\\{$col['foreignModel']};\n";
            }
        }

        // Build relationships block (skip organization if BelongsToOrganization trait handles it)
        $filteredColumns = $belongsToOrg
            ? array_filter($columns, fn ($col) => !($col['type'] === 'foreignId' && $col['foreignModel'] === 'Organization'))
            : $columns;
        $relationships = $this->buildRelationshipMethods($filteredColumns);
        if (!empty($relationships)) {
            $relationships = "\n    // ---------------------------------------------------------------\n"
                . "    // Relationships\n"
                . "    // ---------------------------------------------------------------\n\n"
                . $relationships;
        }

        // Organization scoping is auto-detected from BelongsTo relationships.
        // No $owner property needed — the framework introspects the relationship chain.
        $organizationScoping = '';

        // Build casts placeholder content
        $castsContent = '';
        if (!empty($jsonCasts)) {
            $castsContent = "\n    protected \$casts = " . $this->assocArrayToPhpString($jsonCasts, 4) . ";\n";
        }

        $stub = $this->getStub('model');
        $content = $this->replacePlaceholders($stub, [
            'class' => $name,
            'imports' => $imports,
            'fillable' => $this->arrayToPhpString($fillableColumns, 8),
            'casts' => $castsContent,
            'validationRules' => $this->assocArrayToPhpString($validationRules, 8),
            'validationRulesStore' => $this->arrayToPhpString($ruleFields, 8),
            'validationRulesUpdate' => $this->arrayToPhpString($ruleFields, 8),
            'allowedFilters' => $this->arrayToPhpString($filterNames, 8),
            'allowedSorts' => $this->arrayToPhpString($sortNames, 8),
            'allowedFields' => $this->arrayToPhpString($allFieldNames, 8),
            'allowedIncludes' => $this->arrayToPhpString($includeNames, 8),
            'organizationScoping' => $organizationScoping,
            'relationships' => $relationships,
        ]);

        // Remove SoftDeletes if not selected
        if (!$includeSoftDeletes) {
            $content = str_replace(
                "use Illuminate\\Database\\Eloquent\\SoftDeletes;\n",
                '',
                $content
            );
            $content = str_replace(
                'use HasFactory, SoftDeletes, HasValidation, HidableColumns, HasAutoScope;',
                'use HasFactory, HasValidation, HidableColumns, HasAutoScope;',
                $content
            );
        }

        // Uncomment HasAuditTrail if selected
        if ($includeAuditTrail) {
            $content = str_replace(
                '// use Rhino\\Traits\\HasAuditTrail;',
                'use Rhino\\Traits\\HasAuditTrail;',
                $content
            );
            $content = str_replace(
                '    // use HasAuditTrail;',
                '    use HasAuditTrail;',
                $content
            );
        }

        // Uncomment BelongsToOrganization trait when model belongs to org
        if ($belongsToOrg) {
            $content = str_replace(
                '// use Rhino\\Traits\\BelongsToOrganization;',
                'use Rhino\\Traits\\BelongsToOrganization;',
                $content
            );
            $content = str_replace(
                '    // use BelongsToOrganization;',
                '    use BelongsToOrganization;',
                $content
            );
        }

        File::put($modelPath, $content);
    }

    protected function buildRelationshipMethods(array $columns): string
    {
        $methods = '';

        foreach ($columns as $col) {
            if ($col['type'] === 'foreignId' && $col['foreignModel']) {
                $relationName = Str::camel(Str::replaceLast('_id', '', $col['name']));
                $modelClass = $col['foreignModel'];
                $methods .= "    public function {$relationName}(): \\Illuminate\\Database\\Eloquent\\Relations\\BelongsTo\n";
                $methods .= "    {\n";
                $methods .= "        return \$this->belongsTo({$modelClass}::class);\n";
                $methods .= "    }\n\n";
            }
        }

        return $methods;
    }

    protected function columnToValidationRule(array $column, string $tableName): string
    {
        $rules = [];

        if ($column['nullable']) {
            $rules[] = 'nullable';
        } else {
            $rules[] = 'required';
        }

        if (!empty($column['unique'])) {
            $rules[] = "unique:{$tableName},{$column['name']}";
        }

        switch ($column['type']) {
            case 'string':
                $rules[] = 'string';
                $rules[] = 'max:255';
                break;
            case 'text':
                $rules[] = 'string';
                break;
            case 'integer':
            case 'bigInteger':
                $rules[] = 'integer';
                break;
            case 'boolean':
                $rules[] = 'boolean';
                break;
            case 'date':
            case 'datetime':
            case 'timestamp':
                $rules[] = 'date';
                break;
            case 'decimal':
            case 'float':
                $rules[] = 'numeric';
                break;
            case 'json':
                $rules[] = 'array';
                break;
            case 'uuid':
                $rules[] = 'uuid';
                break;
            case 'foreignId':
                $rules[] = 'integer';
                if ($column['foreignModel']) {
                    $foreignTable = Str::snake(Str::plural($column['foreignModel']));
                    $rules[] = "exists:{$foreignTable},id";
                }
                break;
        }

        return implode('|', $rules);
    }

    protected function updateMigrationFile(string $name, array $columns, bool $includeSoftDeletes = true): void
    {
        $tableName = Str::snake(Str::plural($name));
        $migrationsPath = database_path('migrations');

        $files = glob($migrationsPath . "/*_create_{$tableName}_table.php");

        if (empty($files)) {
            warning("Could not find migration for table '{$tableName}'. Skipping migration update.");
            return;
        }

        $migrationFile = end($files);
        $content = File::get($migrationFile);

        $columnLines = [];
        $columnLines[] = '            $table->id();';

        foreach ($columns as $col) {
            $columnLines[] = '            ' . $this->columnToMigrationLine($col);
        }

        if ($includeSoftDeletes) {
            $columnLines[] = '            $table->softDeletes();';
        }
        $columnLines[] = '            $table->timestamps();';

        $columnsBlock = implode("\n", $columnLines);

        $content = preg_replace(
            '/(Schema::create\(\'' . preg_quote($tableName, '/') . '\',\s*function\s*\(Blueprint\s*\$table\)\s*\{)(.*?)(\s*\}\);)/s',
            '$1' . "\n" . $columnsBlock . "\n        " . '$3',
            $content
        );

        File::put($migrationFile, $content);
    }

    protected function columnToMigrationLine(array $column): string
    {
        $name = $column['name'];
        $type = $column['type'];

        if ($type === 'foreignId') {
            $line = "\$table->foreignId('{$name}')";
            if ($column['nullable']) {
                $line .= '->nullable()';
            }
            if ($column['foreignModel']) {
                $foreignTable = Str::snake(Str::plural($column['foreignModel']));
                $line .= "->constrained('{$foreignTable}')->cascadeOnDelete()";
            } else {
                $line .= '->constrained()->cascadeOnDelete()';
            }
            if (!empty($column['unique'])) {
                $line .= '->unique()';
            }
            if ($column['index']) {
                $line .= '->index()';
            }
            return $line . ';';
        }

        if ($type === 'decimal') {
            $line = "\$table->decimal('{$name}', 8, 2)";
        } else {
            $line = "\$table->{$type}('{$name}')";
        }

        if ($column['nullable']) {
            $line .= '->nullable()';
        }

        if (!empty($column['unique'])) {
            $line .= '->unique()';
        }

        if ($column['default'] !== null) {
            $defaultValue = $this->formatDefaultValue($column['default'], $type);
            $line .= "->default({$defaultValue})";
        }

        if ($column['index']) {
            $line .= '->index()';
        }

        return $line . ';';
    }

    protected function formatDefaultValue(string $value, string $type): string
    {
        if (in_array($type, ['integer', 'bigInteger', 'decimal', 'float'])) {
            return $value;
        }

        if ($type === 'boolean') {
            return in_array(strtolower($value), ['true', '1']) ? 'true' : 'false';
        }

        return "'{$value}'";
    }

    protected function updateFactoryFile(string $name, array $columns): void
    {
        $factoryPath = database_path("factories/{$name}Factory.php");

        if (!File::exists($factoryPath)) {
            warning("Factory file not found at {$factoryPath}. Skipping factory update.");
            return;
        }

        $fakerLines = [];
        foreach ($columns as $col) {
            $fakerLines[] = "            '{$col['name']}' => {$this->columnToFakerValue($col)},";
        }
        $fakerBlock = implode("\n", $fakerLines);

        $content = File::get($factoryPath);

        $content = preg_replace(
            '/(public function definition\(\).*?return\s*\[)(.*?)(\s*\];)/s',
            '$1' . "\n" . $fakerBlock . "\n        " . '$3',
            $content
        );

        File::put($factoryPath, $content);
    }

    protected function columnToFakerValue(array $column): string
    {
        $type = $column['type'];
        $name = $column['name'];

        $nameBasedFaker = $this->nameBasedFakerValue($name);
        if ($nameBasedFaker !== null) {
            if ($column['nullable']) {
                return "fake()->optional()->{$nameBasedFaker}";
            }
            return "fake()->{$nameBasedFaker}";
        }

        if ($type === 'foreignId') {
            if ($column['foreignModel']) {
                return "\\App\\Models\\{$column['foreignModel']}::factory()";
            }
            return 'fake()->numberBetween(1, 10)';
        }

        $value = match ($type) {
            'string' => 'fake()->sentence(3)',
            'text' => 'fake()->paragraph()',
            'integer' => 'fake()->numberBetween(1, 100)',
            'bigInteger' => 'fake()->numberBetween(1, 10000)',
            'boolean' => 'fake()->boolean()',
            'date' => 'fake()->date()',
            'datetime', 'timestamp' => 'fake()->dateTime()',
            'decimal', 'float' => 'fake()->randomFloat(2, 0, 1000)',
            'json' => '[]',
            'uuid' => 'fake()->uuid()',
            default => 'fake()->word()',
        };

        if ($column['nullable'] && !in_array($type, ['json', 'boolean'])) {
            return str_replace('fake()->', 'fake()->optional()->', $value);
        }

        return $value;
    }

    protected function nameBasedFakerValue(string $name): ?string
    {
        return match (true) {
            $name === 'name' || $name === 'full_name' => 'name()',
            $name === 'first_name' => 'firstName()',
            $name === 'last_name' => 'lastName()',
            $name === 'email' => 'safeEmail()',
            $name === 'phone' || $name === 'phone_number' => 'phoneNumber()',
            $name === 'address' => 'address()',
            $name === 'city' => 'city()',
            $name === 'country' => 'country()',
            $name === 'zip_code' || $name === 'postal_code' => 'postcode()',
            $name === 'url' || $name === 'website' => 'url()',
            $name === 'title' => 'sentence(3)',
            $name === 'description' || $name === 'content' || $name === 'body' => 'paragraph()',
            $name === 'slug' => 'slug()',
            $name === 'price' || $name === 'amount' || $name === 'cost' => 'randomFloat(2, 1, 1000)',
            $name === 'quantity' || $name === 'count' => 'numberBetween(1, 100)',
            str_starts_with($name, 'is_') => 'boolean()',
            default => null,
        };
    }

    protected function registerModelInConfig(string $name): void
    {
        $configPath = config_path('rhino.php');

        if (!File::exists($configPath)) {
            warning('Config file config/rhino.php not found. Please register the model manually.');
            return;
        }

        $content = File::get($configPath);
        $slug = Str::snake(Str::plural($name));
        $modelClass = "\\App\\Models\\{$name}::class";

        if (Str::contains($content, "'{$slug}'") || Str::contains($content, "\"{$slug}\"")) {
            return;
        }

        $newEntry = "        '{$slug}' => {$modelClass},";

        // Handle modern [] syntax — only replace the FIRST 'models' => [...] match
        // (the top-level models array), not route_groups nested models arrays.
        $updated = preg_replace(
            "/('models'\s*=>\s*\[)(.*?)(\s*\])/s",
            '$1$2' . "\n" . $newEntry . '$3',
            $content,
            1 // limit to first match
        );

        // Handle legacy array() syntax
        if ($updated === $content) {
            $updated = preg_replace(
                "/('models'\s*=>\s*(?:array\s*\())(.*?)(\s*\))/s",
                '$1$2' . "\n" . $newEntry . '$3',
                $content,
                1 // limit to first match
            );
        }

        File::put($configPath, $updated);
    }

    protected function printModelNextSteps(string $name, array $options = []): void
    {
        $slug = Str::snake(Str::plural($name));

        $this->newLine();
        $this->components->info('Next steps:');
        $this->newLine();

        $step = 1;

        if (empty($options['policy'])) {
            $this->line("  <fg=yellow>{$step}.</> Create a policy: <fg=white>php artisan rhino:g</> (select Policy, name: {$name})");
            $step++;
        }

        $this->line("  <fg=yellow>{$step}.</> Run migrations: <fg=white>php artisan migrate</>");
        $step++;
        $this->line("  <fg=yellow>{$step}.</> Review the generated model at: <fg=white>app/Models/{$name}.php</>");
        $step++;
        $this->line("  <fg=yellow>{$step}.</> Run tests: <fg=white>php artisan test tests/Model/{$name}Test.php</>");
        $step++;
        $this->line("  <fg=yellow>{$step}.</> Your API endpoints: <fg=white>GET/POST /api/{$slug}</>, <fg=white>GET/PUT/DELETE /api/{$slug}/{id}</>");
        $this->newLine();
    }

    // ------------------------------------------------------------------
    // File creators (non-interactive, used by generateModel)
    // ------------------------------------------------------------------

    protected function createPolicyFile(string $name): void
    {
        $policyName = "{$name}Policy";
        $policyPath = app_path("Policies/{$policyName}.php");

        File::ensureDirectoryExists(app_path('Policies'));

        $stub = $this->getStub('policy');
        $content = $this->replacePlaceholders($stub, [
            'modelName' => $name,
            'policyName' => $policyName,
        ]);

        File::put($policyPath, $content);
    }

    protected function createSeederFile(string $name, bool $belongsToOrg = false, ?string $ownerRelation = null): void
    {
        $seederPath = database_path("seeders/{$name}Seeder.php");

        $stub = $this->getStub('seeder');

        $parentImports = '';
        $parentCreation = '';
        $factoryAttributes = '';

        if ($belongsToOrg) {
            $parentImports = "use App\\Models\\Organization;\n";
            $parentCreation = "        \$org = Organization::first() ?? Organization::factory()->create();\n\n";
            $factoryAttributes = "['organization_id' => \$org->id]";
        } elseif ($ownerRelation) {
            $ownerModel = Str::studly($ownerRelation);
            $ownerFk = Str::snake($ownerRelation) . '_id';
            $parentImports = "use App\\Models\\{$ownerModel};\n";
            $parentCreation = "        \${$ownerRelation} = {$ownerModel}::first() ?? {$ownerModel}::factory()->create();\n\n";
            $factoryAttributes = "['{$ownerFk}' => \${$ownerRelation}->id]";
        }

        $content = $this->replacePlaceholders($stub, [
            'modelName' => $name,
            'parentImports' => $parentImports,
            'parentCreation' => $parentCreation,
            'factoryAttributes' => $factoryAttributes,
        ]);

        File::put($seederPath, $content);
    }

    protected function createScopeFile(string $name): void
    {
        $scopeName = "{$name}Scope";
        $tableName = Str::snake(Str::plural($name));
        $scopePath = app_path("Models/Scopes/{$scopeName}.php");

        File::ensureDirectoryExists(app_path('Models/Scopes'));

        $stub = $this->getStub('scope');
        $content = $this->replacePlaceholders($stub, [
            'scopeName' => $scopeName,
            'modelName' => $name,
            'tableName' => $tableName,
        ]);

        File::put($scopePath, $content);
    }

    protected function addSeederToDatabaseSeeder(string $name): void
    {
        $seederClass = "{$name}Seeder";
        $databaseSeederPath = database_path('seeders/DatabaseSeeder.php');

        if (!File::exists($databaseSeederPath)) {
            return;
        }

        $content = File::get($databaseSeederPath);

        // Skip if already registered
        if (strpos($content, $seederClass) !== false) {
            return;
        }

        // Try to find existing $this->call([...]) block and append
        if (preg_match('/(\$this->call\(\[)(.*?)(\]\))/s', $content, $matches)) {
            $content = str_replace(
                $matches[0],
                $matches[1] . $matches[2] . "            {$seederClass}::class,\n        " . $matches[3],
                $content
            );
        } else {
            // No existing call block — add one before the closing brace of run()
            $content = preg_replace(
                '/(public function run\(\): void\s*\{[^}]*?)(\s*\})/s',
                '$1' . "\n        \$this->call([\n            {$seederClass}::class,\n        ]);" . '$2',
                $content
            );
        }

        File::put($databaseSeederPath, $content);
    }

    // ------------------------------------------------------------------
    // Test generation
    // ------------------------------------------------------------------

    protected function getTestFramework(): string
    {
        $configPath = config_path('rhino.php');

        if (!File::exists($configPath)) {
            return 'pest';
        }

        $config = require $configPath;

        return $config['test_framework'] ?? 'pest';
    }

    protected function generateTestFile(string $name, array $columns, array $roleAccess, bool $isMultiTenant = false): void
    {
        $testDir = base_path('tests/Model');
        File::ensureDirectoryExists($testDir);

        $testPath = "{$testDir}/{$name}Test.php";
        $slug = Str::snake(Str::plural($name));
        $orgIdentifier = $isMultiTenant ? $this->getOrganizationIdentifierColumn() : null;
        $framework = $this->getTestFramework();

        $roleTests = $this->buildRoleTests($name, $slug, $roleAccess, $isMultiTenant, $orgIdentifier, $framework);
        $relationshipTests = $this->buildRelationshipTests($name, $columns, $framework);

        // Select the correct stub based on framework and multi-tenancy
        if ($isMultiTenant) {
            $stubName = $framework === 'phpunit' ? 'test-phpunit' : 'test';
        } else {
            $stubName = $framework === 'phpunit' ? 'test-phpunit-nontenant' : 'test-nontenant';
        }
        $stub = $this->getStub($stubName);
        $content = $this->replacePlaceholders($stub, [
            'modelName' => $name,
            'roleTests' => $roleTests,
            'relationshipTests' => $relationshipTests,
        ]);

        File::put($testPath, $content);
    }

    protected function buildRoleTests(string $name, string $slug, array $roleAccess, bool $isMultiTenant = false, ?string $orgIdentifier = null, string $framework = 'pest'): string
    {
        if (empty($roleAccess)) {
            return '';
        }

        // Build PHP expressions for URLs
        if ($isMultiTenant) {
            $listUrl = "'/api/' . \$org->{$orgIdentifier} . '/{$slug}'";
            $itemUrl = "'/api/' . \$org->{$orgIdentifier} . '/{$slug}/' . \$model->id";
        } else {
            $listUrl = "'/api/{$slug}'";
            $itemUrl = "'/api/{$slug}/' . \$model->id";
        }

        $isPest = $framework === 'pest';
        $indent = $isPest ? '    ' : '        ';

        $tests = $isPest
            ? "// ---------------------------------------------------------------\n// Role-based access tests\n// ---------------------------------------------------------------\n\n"
            : "    // ---------------------------------------------------------------\n    // Role-based access tests\n    // ---------------------------------------------------------------\n\n";

        foreach ($roleAccess as $role => $access) {
            $permissions = $this->roleAccessToPermissions($slug, $access);
            $permissionsPhp = $this->permissionsToPhpArray($permissions);

            $allowedEndpoints = match ($access) {
                'editor' => ['index', 'show', 'store', 'update', 'destroy'],
                'viewer' => ['index', 'show'],
                'writer' => ['index', 'show', 'store', 'update'],
                'none' => [],
                default => [],
            };

            $blockedEndpoints = array_diff(
                ['index', 'show', 'store', 'update', 'destroy'],
                $allowedEndpoints
            );

            // Allowed endpoints test
            if (!empty($allowedEndpoints)) {
                if ($isPest) {
                    $tests .= "it('allows {$role} to access permitted {$slug} endpoints', function () {\n";
                } else {
                    $methodName = 'test_' . $role . '_can_access_permitted_' . $slug . '_endpoints';
                    $tests .= "    public function {$methodName}(): void\n    {\n";
                }

                if ($isMultiTenant) {
                    $tests .= "{$indent}\$org = Organization::factory()->create();\n";
                    $createCall = $isPest ? 'createUserWithRole' : '$this->createUserWithRole';
                    $tests .= "{$indent}\$user = {$createCall}('{$role}', \$org, {$permissionsPhp});\n";
                } else {
                    $createCall = $isPest ? 'createUserWithPermissions' : '$this->createUserWithPermissions';
                    $tests .= "{$indent}\$user = {$createCall}({$permissionsPhp});\n";
                }
                $tests .= "{$indent}\$model = {$name}::factory()->create();\n\n";
                $tests .= "{$indent}\$this->actingAs(\$user);\n\n";

                foreach ($allowedEndpoints as $endpoint) {
                    $tests .= match ($endpoint) {
                        'index' => "{$indent}\$this->getJson({$listUrl})->assertStatus(200);\n",
                        'show' => "{$indent}\$this->getJson({$itemUrl})->assertStatus(200);\n",
                        'store' => "{$indent}// \$this->postJson({$listUrl}, [...])->assertStatus(201);\n",
                        'update' => "{$indent}// \$this->putJson({$itemUrl}, [...])->assertStatus(200);\n",
                        'destroy' => "{$indent}\$this->deleteJson({$itemUrl})->assertStatus(200);\n",
                    };
                }

                $tests .= $isPest ? "});\n\n" : "    }\n\n";
            }

            // Blocked endpoints test
            if (!empty($blockedEndpoints)) {
                if ($isPest) {
                    $tests .= "it('blocks {$role} from restricted {$slug} endpoints', function () {\n";
                } else {
                    $methodName = 'test_' . $role . '_is_blocked_from_restricted_' . $slug . '_endpoints';
                    $tests .= "    public function {$methodName}(): void\n    {\n";
                }

                if ($isMultiTenant) {
                    $tests .= "{$indent}\$org = Organization::factory()->create();\n";
                    $createCall = $isPest ? 'createUserWithRole' : '$this->createUserWithRole';
                    $tests .= "{$indent}\$user = {$createCall}('{$role}', \$org, {$permissionsPhp});\n";
                } else {
                    $createCall = $isPest ? 'createUserWithPermissions' : '$this->createUserWithPermissions';
                    $tests .= "{$indent}\$user = {$createCall}({$permissionsPhp});\n";
                }
                $tests .= "{$indent}\$model = {$name}::factory()->create();\n\n";
                $tests .= "{$indent}\$this->actingAs(\$user);\n\n";

                foreach ($blockedEndpoints as $endpoint) {
                    $tests .= match ($endpoint) {
                        'index' => "{$indent}\$this->getJson({$listUrl})->assertStatus(403);\n",
                        'show' => "{$indent}\$this->getJson({$itemUrl})->assertStatus(403);\n",
                        'store' => "{$indent}\$this->postJson({$listUrl}, [])->assertStatus(403);\n",
                        'update' => "{$indent}\$this->putJson({$itemUrl}, [])->assertStatus(403);\n",
                        'destroy' => "{$indent}\$this->deleteJson({$itemUrl})->assertStatus(403);\n",
                    };
                }

                $tests .= $isPest ? "});\n\n" : "    }\n\n";
            }
        }

        return $tests;
    }

    protected function permissionsToPhpArray(array $permissions): string
    {
        if (empty($permissions)) {
            return '[]';
        }

        $items = array_map(fn ($p) => "'{$p}'", $permissions);

        return '[' . implode(', ', $items) . ']';
    }

    protected function buildRelationshipTests(string $name, array $columns, string $framework = 'pest'): string
    {
        $fkColumns = array_filter($columns, fn ($col) => $col['type'] === 'foreignId' && $col['foreignModel']);

        if (empty($fkColumns)) {
            return '';
        }

        $isPest = $framework === 'pest';
        $indent = $isPest ? '    ' : '        ';

        $tests = $isPest
            ? "// ---------------------------------------------------------------\n// Relationship tests\n// ---------------------------------------------------------------\n\n"
            : "    // ---------------------------------------------------------------\n    // Relationship tests\n    // ---------------------------------------------------------------\n\n";

        foreach ($fkColumns as $col) {
            $relationName = Str::camel(Str::replaceLast('_id', '', $col['name']));
            $foreignModel = $col['foreignModel'];

            if ($isPest) {
                $tests .= "it('belongs to {$relationName}', function () {\n";
                $tests .= "{$indent}\$model = {$name}::factory()->create();\n\n";
                $tests .= "{$indent}expect(\$model->{$relationName}())\n";
                $tests .= "{$indent}    ->toBeInstanceOf(\\Illuminate\\Database\\Eloquent\\Relations\\BelongsTo::class);\n";
                $tests .= "{$indent}expect(\$model->{$relationName})\n";
                $tests .= "{$indent}    ->toBeInstanceOf(\\App\\Models\\{$foreignModel}::class);\n";
                $tests .= "});\n\n";
            } else {
                $methodName = 'test_it_belongs_to_' . Str::snake($relationName);
                $tests .= "    public function {$methodName}(): void\n    {\n";
                $tests .= "{$indent}\$model = {$name}::factory()->create();\n\n";
                $tests .= "{$indent}\$this->assertInstanceOf(\n";
                $tests .= "{$indent}    \\Illuminate\\Database\\Eloquent\\Relations\\BelongsTo::class,\n";
                $tests .= "{$indent}    \$model->{$relationName}()\n";
                $tests .= "{$indent});\n";
                $tests .= "{$indent}\$this->assertInstanceOf(\n";
                $tests .= "{$indent}    \\App\\Models\\{$foreignModel}::class,\n";
                $tests .= "{$indent}    \$model->{$relationName}\n";
                $tests .= "{$indent});\n";
                $tests .= "    }\n\n";
            }
        }

        return $tests;
    }

    // ------------------------------------------------------------------
    // Policy generation
    // ------------------------------------------------------------------

    protected function generatePolicy(string $name): int
    {
        $this->printSelections('policy', $name);

        $policyName = Str::endsWith($name, 'Policy') ? $name : "{$name}Policy";
        $modelName = Str::replaceLast('Policy', '', $policyName);
        $policyPath = app_path("Policies/{$policyName}.php");

        if (File::exists($policyPath)) {
            warning("Policy {$policyName} already exists at {$policyPath}.");
            if (!confirm('Do you want to overwrite it?', default: false)) {
                info('Policy generation cancelled.');
                return 0;
            }
        }

        $this->components->task("Generating {$policyName}", function () use ($policyName, $modelName, $policyPath) {
            File::ensureDirectoryExists(app_path('Policies'));

            $stub = $this->getStub('policy');
            $content = $this->replacePlaceholders($stub, [
                'modelName' => $modelName,
                'policyName' => $policyName,
            ]);

            File::put($policyPath, $content);
        });

        $this->newLine();
        info("{$policyName} generated successfully!");

        $this->newLine();
        $this->components->info('Created files:');
        $this->newLine();
        $this->line("  <fg=gray>Policy</>  <fg=white>app/Policies/{$policyName}.php</>");

        $this->newLine();
        $this->components->info('Next steps:');
        $this->newLine();
        $this->line("  <fg=yellow>1.</> Register the policy in <fg=white>App\\Providers\\AuthServiceProvider</> or rely on Laravel's auto-discovery.");
        $this->line("  <fg=yellow>2.</> Uncomment and customize the authorization methods you need.");
        $this->newLine();

        return 0;
    }

    // ------------------------------------------------------------------
    // Scope generation
    // ------------------------------------------------------------------

    protected function generateScope(string $name): int
    {
        $this->printSelections('scope', $name);

        $scopeName = Str::endsWith($name, 'Scope') ? $name : "{$name}Scope";
        $modelName = Str::replaceLast('Scope', '', $scopeName);
        $tableName = Str::snake(Str::plural($modelName));
        $scopePath = app_path("Models/Scopes/{$scopeName}.php");

        if (File::exists($scopePath)) {
            warning("Scope {$scopeName} already exists at {$scopePath}.");
            if (!confirm('Do you want to overwrite it?', default: false)) {
                info('Scope generation cancelled.');
                return 0;
            }
        }

        $this->components->task("Generating {$scopeName}", function () use ($scopeName, $modelName, $tableName, $scopePath) {
            File::ensureDirectoryExists(app_path('Models/Scopes'));

            $stub = $this->getStub('scope');
            $content = $this->replacePlaceholders($stub, [
                'scopeName' => $scopeName,
                'modelName' => $modelName,
                'tableName' => $tableName,
            ]);

            File::put($scopePath, $content);
        });

        $this->newLine();
        info("{$scopeName} generated successfully!");

        $this->newLine();
        $this->components->info('Created files:');
        $this->newLine();
        $this->line("  <fg=gray>Scope</>  <fg=white>app/Models/Scopes/{$scopeName}.php</>");

        $this->newLine();
        $this->components->info('Next steps:');
        $this->newLine();
        $this->line("  <fg=yellow>1.</> Uncomment the filter logic in the apply() method.");
        $this->line("  <fg=yellow>2.</> Use via: <fg=white>ScopedDB::table('{$tableName}')</>");
        $this->newLine();

        return 0;
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function arrayToPhpString(array $items, int $indent = 8): string
    {
        if (empty($items)) {
            return '[]';
        }

        $pad = str_repeat(' ', $indent);
        $inner = implode(",\n", array_map(fn ($item) => "{$pad}    '{$item}'", $items));

        return "[\n{$inner},\n{$pad}]";
    }

    protected function assocArrayToPhpString(array $items, int $indent = 8): string
    {
        if (empty($items)) {
            return '[]';
        }

        $pad = str_repeat(' ', $indent);
        $lines = [];

        foreach ($items as $key => $value) {
            $lines[] = "{$pad}    '{$key}' => '{$value}'";
        }

        $inner = implode(",\n", $lines);

        return "[\n{$inner},\n{$pad}]";
    }
}
