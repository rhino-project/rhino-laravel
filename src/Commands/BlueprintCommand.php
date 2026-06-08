<?php

namespace Rhino\Commands;

use Illuminate\Console\Command;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Str;
use Rhino\Blueprint\BlueprintParser;
use Rhino\Blueprint\BlueprintValidator;
use Rhino\Blueprint\ManifestManager;
use Rhino\Blueprint\Generators\PolicyGenerator;
use Rhino\Blueprint\Generators\TestGenerator;
use Rhino\Blueprint\Generators\SeederGenerator;
use Rhino\Commands\Traits\GeneratorHelpers;

class BlueprintCommand extends Command
{
    use GeneratorHelpers;
    protected $signature = 'rhino:blueprint
        {--dir=.rhino/blueprints : Directory containing blueprint YAML files}
        {--model= : Process a single model slug}
        {--force : Regenerate even if unchanged}
        {--dry-run : Preview what would be generated}
        {--skip-tests : Skip test file generation}
        {--skip-seeders : Skip seeder generation}';

    protected $description = 'Generate Rhino resources from YAML blueprint specifications (zero-token deterministic generation)';

    protected BlueprintParser $parser;
    protected BlueprintValidator $validator;
    protected ManifestManager $manifest;
    protected PolicyGenerator $policyGenerator;
    protected TestGenerator $testGenerator;
    protected SeederGenerator $seederGenerator;

    protected string $stubPath;
    protected array $generatedFiles = [];
    protected int $skippedCount = 0;
    protected int $processedCount = 0;
    protected int $errorCount = 0;
    protected bool $isMultiTenant = false;
    protected int $migrationTimestampOffset = 0;

    public function handle(): int
    {
        $this->stubPath = __DIR__ . '/../../stubs';

        $this->parser = new BlueprintParser();
        $this->validator = new BlueprintValidator();
        $this->policyGenerator = new PolicyGenerator();
        $this->testGenerator = new TestGenerator();
        $this->seederGenerator = new SeederGenerator();
        $this->isMultiTenant = $this->isMultiTenantEnabled();

        $this->printHeader();

        $dir = base_path($this->option('dir'));

        if (!is_dir($dir)) {
            $this->printError("Blueprint directory not found: {$dir}");
            $this->newLine();
            $this->line("  Run <fg=white>php artisan rhino:install</> to create the .rhino directory,");
            $this->line("  then add your blueprint YAML files to <fg=white>.rhino/blueprints/</>");
            return 1;
        }

        $this->manifest = new ManifestManager($dir);

        // 1. Parse roles
        $roles = $this->parseRoles($dir);
        if ($roles === null) {
            return 1;
        }

        // 2. Discover and parse model blueprints
        $blueprints = $this->discoverBlueprints($dir, $roles);
        if ($blueprints === false) {
            return 1;
        }

        if (empty($blueprints)) {
            $this->printWarning('No blueprint files found to process.');
            return 0;
        }

        // Order so a referenced model's table is migrated before any model that
        // foreign-keys to it (parents before children). Migration timestamps are
        // assigned in iteration order, so this is what guarantees a runnable set.
        $sorter = new \Rhino\Blueprint\BlueprintSorter();
        $blueprints = $sorter->sort($blueprints);
        if (!empty($sorter->cycles())) {
            $this->printWarning(
                'Circular foreign-key dependency among: ' . implode(', ', $sorter->cycles())
                . '. Migration order is best-effort — make one side nullable or add the FK in a later migration.'
            );
        }

        // 3. Generate per-model artifacts
        foreach ($blueprints as $blueprint) {
            $this->processBlueprint($blueprint, $roles);
        }

        // 4. Generate cross-model seeders
        if (!$this->option('skip-seeders')) {
            $this->generateSeeders($roles, $blueprints);
        }

        // 5. Save manifest
        if (!$this->option('dry-run')) {
            $this->manifest->save();
        }

        // 6. Print summary
        $this->printSummary();

        return $this->errorCount > 0 ? 1 : 0;
    }

    // ------------------------------------------------------------------
    // Display helpers
    // ------------------------------------------------------------------

    protected function printHeader(): void
    {
        $cyan = "\033[38;2;0;255;255m";
        $dimCyan = "\033[38;2;0;140;140m";
        $reset = "\033[0m";

        $this->newLine();
        $this->output->writeln("  {$cyan}Blueprint{$reset} {$dimCyan}— Zero-token deterministic code generation{$reset}");
        $this->newLine();
    }

    protected function printSuccess(string $message): void
    {
        $green = "\033[38;2;100;220;100m";
        $reset = "\033[0m";
        $this->output->writeln("  {$green}✓{$reset} {$message}");
    }

    protected function printWarning(string $message): void
    {
        $yellow = "\033[38;2;255;220;50m";
        $reset = "\033[0m";
        $this->output->writeln("  {$yellow}⚠{$reset} {$message}");
    }

    protected function printError(string $message): void
    {
        $red = "\033[38;2;255;80;80m";
        $reset = "\033[0m";
        $this->output->writeln("  {$red}✗{$reset} {$message}");
    }

    protected function printSkipped(string $message): void
    {
        $dim = "\033[38;2;120;120;120m";
        $reset = "\033[0m";
        $this->output->writeln("  {$dim}○{$reset} {$message}");
    }

    protected function printSection(string $title): void
    {
        $cyan = "\033[38;2;0;200;200m";
        $reset = "\033[0m";
        $this->newLine();
        $this->output->writeln("  {$cyan}▸ {$title}{$reset}");
    }

    // ------------------------------------------------------------------
    // 1. Parse roles
    // ------------------------------------------------------------------

    protected function parseRoles(string $dir): ?array
    {
        $rolesFile = $dir . '/_roles.yaml';

        if (!file_exists($rolesFile)) {
            $rolesFile = $dir . '/_roles.yml';
        }

        if (!file_exists($rolesFile)) {
            $this->printWarning('No _roles.yaml found — skipping role validation.');
            return [];
        }

        try {
            $roles = $this->parser->parseRoles($rolesFile);
        } catch (\RuntimeException $e) {
            $this->printError("Failed to parse roles: {$e->getMessage()}");
            return null;
        }

        $result = $this->validator->validateRoles($roles);

        if (!$result['valid']) {
            $this->printError('Role validation failed:');
            foreach ($result['errors'] as $error) {
                $this->printError("  → {$error}");
            }
            return null;
        }

        $roleNames = array_keys($roles);
        $this->printSuccess('Roles: ' . implode(', ', $roleNames));

        return $roles;
    }

    // ------------------------------------------------------------------
    // 2. Discover blueprints
    // ------------------------------------------------------------------

    protected function discoverBlueprints(string $dir, array $roles): array|false
    {
        $modelFilter = $this->option('model');

        // Find all YAML files (excluding _roles.yaml and manifest)
        $files = array_merge(
            glob($dir . '/*.yaml') ?: [],
            glob($dir . '/*.yml') ?: []
        );

        $files = array_filter($files, function ($file) {
            $basename = basename($file);
            return !str_starts_with($basename, '_')
                && !str_starts_with($basename, '.');
        });

        if ($modelFilter) {
            $files = array_filter($files, function ($file) use ($modelFilter) {
                $slug = pathinfo(basename($file), PATHINFO_FILENAME);
                return $slug === $modelFilter;
            });

            if (empty($files)) {
                $this->printError("Blueprint file not found for model: {$modelFilter}");
                return false;
            }
        }

        // Sort for deterministic order
        sort($files);

        $blueprints = [];

        foreach ($files as $file) {
            try {
                $blueprint = $this->parser->parseModel($file);
            } catch (\RuntimeException $e) {
                $this->printError("Failed to parse " . basename($file) . ": {$e->getMessage()}");
                $this->errorCount++;
                continue;
            }

            // Validate
            $result = $this->validator->validateModel($blueprint, $roles);

            if (!$result['valid']) {
                $this->printError("Validation failed for {$blueprint['model']}:");
                foreach ($result['errors'] as $error) {
                    $this->printError("  → {$error}");
                }
                $this->errorCount++;
                continue;
            }

            foreach ($result['warnings'] as $warning) {
                $this->printWarning("{$blueprint['model']}: {$warning}");
            }

            $blueprints[] = $blueprint;
        }

        return $blueprints;
    }

    // ------------------------------------------------------------------
    // 3. Process individual blueprint
    // ------------------------------------------------------------------

    protected function processBlueprint(array $blueprint, array $roles): void
    {
        $modelName = $blueprint['model'];
        $slug = $blueprint['slug'];
        $sourceFile = $blueprint['source_file'];
        $force = $this->option('force');
        $dryRun = $this->option('dry-run');

        // Check manifest for changes
        $sourceDir = base_path($this->option('dir'));
        $sourceFilePath = $sourceDir . '/' . $sourceFile;
        $contentHash = $this->parser->computeFileHash($sourceFilePath);

        if (!$force && !$this->manifest->hasChanged($sourceFile, $contentHash)) {
            $this->printSkipped("{$modelName} — unchanged, skipping");
            $this->skippedCount++;
            return;
        }

        $this->printSection($modelName);

        $generatedForModel = [];

        // --- Model ---
        $modelPath = "app/Models/{$modelName}.php";
        if ($dryRun) {
            $this->printSuccess("[dry-run] Would generate Model → {$modelPath}");
        } else {
            $this->generateModelFile($blueprint);
            $this->printSuccess("Model → {$modelPath}");
        }
        $generatedForModel[] = $modelPath;

        // --- Migration ---
        $migrationName = 'create_' . $blueprint['table'] . '_table';
        $migrationPath = "database/migrations/" . date('Y_m_d_His', time() + $this->migrationTimestampOffset) . "_{$migrationName}.php";
            $this->migrationTimestampOffset++;
        if ($dryRun) {
            $this->printSuccess("[dry-run] Would generate Migration → {$migrationPath}");
        } else {
            $migrationPath = $this->generateMigrationFile($blueprint);
            $this->printSuccess("Migration → {$migrationPath}");
        }
        $generatedForModel[] = $migrationPath;

        // --- Factory ---
        $factoryPath = "database/factories/{$modelName}Factory.php";
        if ($dryRun) {
            $this->printSuccess("[dry-run] Would generate Factory → {$factoryPath}");
        } else {
            $this->generateFactoryFile($blueprint);
            $this->printSuccess("Factory → {$factoryPath}");
        }
        $generatedForModel[] = $factoryPath;

        // --- Scope ---
        $scopePath = "app/Models/Scopes/{$modelName}Scope.php";
        if ($dryRun) {
            $this->printSuccess("[dry-run] Would generate Scope → {$scopePath}");
        } else {
            $this->generateScopeFile($blueprint);
            $this->printSuccess("Scope → {$scopePath}");
        }
        $generatedForModel[] = $scopePath;

        // --- Policy (key feature) ---
        $policyPath = "app/Policies/{$modelName}Policy.php";
        if ($dryRun) {
            $this->printSuccess("[dry-run] Would generate Policy → {$policyPath}");
        } else {
            $this->generatePolicyFile($blueprint);
            $this->printSuccess("Policy → {$policyPath}");
        }
        $generatedForModel[] = $policyPath;

        // --- Tests ---
        if (!$this->option('skip-tests')) {
            $testPath = $this->getTestPath($modelName);
            if ($dryRun) {
                $this->printSuccess("[dry-run] Would generate Tests → {$testPath}");
            } else {
                $this->generateTestFile($blueprint);
                $this->printSuccess("Tests → {$testPath}");
            }
            $generatedForModel[] = $testPath;
        }

        // --- Register in rhino config ---
        if (!$dryRun) {
            $this->registerModelInConfig($modelName, $slug);
        }

        // Update manifest
        if (!$dryRun) {
            $this->manifest->recordGeneration($sourceFile, $contentHash, $generatedForModel);
        }

        $this->processedCount++;
        $this->generatedFiles = array_merge($this->generatedFiles, $generatedForModel);
    }

    // ------------------------------------------------------------------
    // Model generation
    // ------------------------------------------------------------------

    protected function generateModelFile(array $blueprint): void
    {
        $name = $blueprint['model'];
        $columns = $blueprint['columns'];
        $options = $blueprint['options'];
        $relationships = $blueprint['relationships'] ?? [];

        $modelPath = app_path("Models/{$name}.php");
        File::ensureDirectoryExists(app_path('Models'));

        $fillableColumns = array_map(fn($col) => $col['name'], $columns);
        $validationRules = [];
        $filterNames = [];
        $sortNames = [];
        $allFieldNames = array_merge(['id'], $fillableColumns, ['created_at', 'updated_at']);

        foreach ($columns as $col) {
            $validationRules[$col['name']] = $this->columnToValidationRule($col, $blueprint['table']);
            if (!empty($col['filterable'])) {
                $filterNames[] = $col['name'];
            }
            if (!empty($col['sortable'])) {
                $sortNames[] = $col['name'];
            }
        }

        // Build casts for JSON columns
        $jsonCasts = [];
        foreach ($columns as $col) {
            if ($col['type'] === 'json') {
                $jsonCasts[$col['name']] = 'array';
            }
        }

        // Build FK imports and include names
        $imports = '';
        $includeNames = [];
        $belongsToOrg = $options['belongs_to_organization'] ?? false;

        foreach ($columns as $col) {
            if ($col['type'] === 'foreignId' && $col['foreignModel']) {
                $includeNames[] = Str::camel(Str::replaceLast('_id', '', $col['name']));
                if ($belongsToOrg && $col['foreignModel'] === 'Organization') {
                    continue;
                }
                $imports .= "use App\\Models\\{$col['foreignModel']};\n";
            }
        }

        // Build relationship methods from both columns and explicit relationships
        $relationshipMethods = $this->buildRelationshipMethods($columns, $relationships, $belongsToOrg);
        if (!empty($relationshipMethods)) {
            $relationshipMethods = "\n    // ---------------------------------------------------------------\n"
                . "    // Relationships\n"
                . "    // ---------------------------------------------------------------\n\n"
                . $relationshipMethods;
        }

        $organizationScoping = '';

        // Build casts placeholder content
        $castsContent = '';
        if (!empty($jsonCasts)) {
            $castsContent = "\n    protected \$casts = " . $this->assocArrayToPhpString($jsonCasts, 4) . ";\n";
        }

        $stub = File::get($this->stubPath . '/generate/model.php.stub');
        $content = $this->replacePlaceholders($stub, [
            'class' => $name,
            'imports' => $imports,
            'fillable' => $this->arrayToPhpString($fillableColumns, 8),
            'casts' => $castsContent,
            'validationRules' => $this->assocArrayToPhpString($validationRules, 8),
            'allowedFilters' => $this->arrayToPhpString($filterNames, 8),
            'allowedSorts' => $this->arrayToPhpString($sortNames, 8),
            'allowedFields' => $this->arrayToPhpString($allFieldNames, 8),
            'allowedIncludes' => $this->arrayToPhpString($includeNames, 8),
            'organizationScoping' => $organizationScoping,
            'relationships' => $relationshipMethods,
        ]);

        // Handle soft deletes
        if (!($options['soft_deletes'] ?? true)) {
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

        // Handle audit trail
        if ($options['audit_trail'] ?? false) {
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

        // Handle BelongsToOrganization
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

        // Handle except_actions
        if (!empty($options['except_actions'])) {
            $exceptPhp = $this->arrayToPhpString($options['except_actions'], 4);
            $content = str_replace(
                "    // public static array \$exceptActions = [];",
                "    public static array \$exceptActions = {$exceptPhp};",
                $content
            );
        }

        // Handle pagination
        if ($options['pagination'] ?? false) {
            $perPage = $options['per_page'] ?? 25;
            $content = str_replace(
                "    // public static bool \$paginationEnabled = false;",
                "    public static bool \$paginationEnabled = true;",
                $content
            );
            $content = str_replace(
                "    // protected \$perPage = 25;",
                "    protected \$perPage = {$perPage};",
                $content
            );
        }

        File::put($modelPath, $content);
    }

    // ------------------------------------------------------------------
    // Migration generation
    // ------------------------------------------------------------------

    protected function generateMigrationFile(array $blueprint): string
    {
        $name = $blueprint['model'];
        $tableName = $blueprint['table'];
        $columns = $blueprint['columns'];
        $options = $blueprint['options'];

        $migrationsPath = database_path('migrations');
        File::ensureDirectoryExists($migrationsPath);

        // Check if migration already exists
        $existing = glob($migrationsPath . "/*_create_{$tableName}_table.php");
        if (!empty($existing)) {
            // Update existing migration
            $migrationFile = end($existing);
            $this->updateExistingMigration($migrationFile, $tableName, $columns, $options);
            return str_replace(base_path() . '/', '', $migrationFile);
        }

        // Create new migration with sequential timestamp to ensure proper ordering
        $timestamp = date('Y_m_d_His', time() + $this->migrationTimestampOffset);
        $this->migrationTimestampOffset++;
        $migrationFile = $migrationsPath . "/{$timestamp}_create_{$tableName}_table.php";

        $columnLines = [];
        $columnLines[] = '            $table->id();';

        if ($options['belongs_to_organization'] ?? false) {
            $columnLines[] = '            $table->foreignId(\'organization_id\')->constrained()->cascadeOnDelete();';
        }

        foreach ($columns as $col) {
            $columnLines[] = '            ' . $this->columnToMigrationLine($col);
        }

        if ($options['soft_deletes'] ?? true) {
            $columnLines[] = '            $table->softDeletes();';
        }
        $columnLines[] = '            $table->timestamps();';

        $columnsBlock = implode("\n", $columnLines);
        $className = 'Create' . Str::studly($tableName) . 'Table';

        $content = <<<PHP
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('{$tableName}', function (Blueprint \$table) {
{$columnsBlock}
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('{$tableName}');
    }
};

PHP;

        File::put($migrationFile, $content);
        return str_replace(base_path() . '/', '', $migrationFile);
    }

    protected function updateExistingMigration(string $migrationFile, string $tableName, array $columns, array $options): void
    {
        $content = File::get($migrationFile);

        $columnLines = [];
        $columnLines[] = '            $table->id();';

        if ($options['belongs_to_organization'] ?? false) {
            $columnLines[] = '            $table->foreignId(\'organization_id\')->constrained()->cascadeOnDelete();';
        }

        foreach ($columns as $col) {
            $columnLines[] = '            ' . $this->columnToMigrationLine($col);
        }

        if ($options['soft_deletes'] ?? true) {
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

    // ------------------------------------------------------------------
    // Factory generation
    // ------------------------------------------------------------------

    protected function generateFactoryFile(array $blueprint): void
    {
        $name = $blueprint['model'];
        $columns = $blueprint['columns'];
        $options = $blueprint['options'];

        $factoryPath = database_path("factories/{$name}Factory.php");
        File::ensureDirectoryExists(database_path('factories'));

        $fakerLines = [];

        if ($options['belongs_to_organization'] ?? false) {
            $fakerLines[] = "            'organization_id' => \\App\\Models\\Organization::factory(),";
        }

        foreach ($columns as $col) {
            $fakerLines[] = "            '{$col['name']}' => {$this->columnToFakerValue($col)},";
        }
        $fakerBlock = implode("\n", $fakerLines);

        $content = <<<PHP
<?php

namespace Database\Factories;

use App\Models\\{$name};
use Illuminate\Database\Eloquent\Factories\Factory;

class {$name}Factory extends Factory
{
    protected \$model = {$name}::class;

    public function definition(): array
    {
        return [
{$fakerBlock}
        ];
    }
}

PHP;

        File::put($factoryPath, $content);
    }

    // ------------------------------------------------------------------
    // Scope generation
    // ------------------------------------------------------------------

    protected function generateScopeFile(array $blueprint): void
    {
        $name = $blueprint['model'];
        $tableName = $blueprint['table'];

        $scopePath = app_path("Models/Scopes/{$name}Scope.php");
        File::ensureDirectoryExists(app_path('Models/Scopes'));

        $stub = File::get($this->stubPath . '/generate/scope.php.stub');
        $content = $this->replacePlaceholders($stub, [
            'scopeName' => "{$name}Scope",
            'modelName' => $name,
            'tableName' => $tableName,
        ]);

        File::put($scopePath, $content);
    }

    // ------------------------------------------------------------------
    // Policy generation (KEY FEATURE)
    // ------------------------------------------------------------------

    protected function generatePolicyFile(array $blueprint): void
    {
        $name = $blueprint['model'];
        $policyPath = app_path("Policies/{$name}Policy.php");
        File::ensureDirectoryExists(app_path('Policies'));

        $content = $this->policyGenerator->generate($blueprint);
        File::put($policyPath, $content);
    }

    // ------------------------------------------------------------------
    // Test generation (KEY FEATURE)
    // ------------------------------------------------------------------

    protected function generateTestFile(array $blueprint): void
    {
        $name = $blueprint['model'];

        // Detect test framework from config or default to pest
        $testFramework = $this->detectTestFramework();

        // Use app-level multi-tenancy setting (not per-model belongs_to_organization)
        // because tests need the correct helper function and model imports
        $orgIdentifier = $this->isMultiTenant ? $this->getOrganizationIdentifierColumn() : 'id';

        $content = $this->testGenerator->generate(
            $blueprint,
            $testFramework,
            $this->isMultiTenant,
            $orgIdentifier
        );

        $testPath = $this->getTestPath($name);
        $fullPath = base_path($testPath);
        File::ensureDirectoryExists(dirname($fullPath));
        File::put($fullPath, $content);
    }

    protected function getTestPath(string $modelName): string
    {
        return "tests/Model/{$modelName}Test.php";
    }

    protected function detectTestFramework(): string
    {
        // Check config file if it exists
        $configPath = base_path('config/rhino.php');
        if (file_exists($configPath)) {
            $config = include $configPath;
            if (isset($config['test_framework'])) {
                return $config['test_framework'];
            }
        }

        // Check for Pest's Pest.php
        if (file_exists(base_path('tests/Pest.php'))) {
            return 'pest';
        }

        return 'pest';
    }

    // ------------------------------------------------------------------
    // Seeder generation (cross-model)
    // ------------------------------------------------------------------

    protected function generateSeeders(array $roles, array $blueprints): void
    {
        if (empty($roles)) {
            return;
        }

        $dryRun = $this->option('dry-run');

        $this->printSection('Seeders (cross-model)');

        // Aggregate permissions from all blueprints
        $aggregatedPermissions = $this->seederGenerator->aggregatePermissions($blueprints);

        if ($this->isMultiTenant) {
            // Multi-tenant: generate RoleSeeder + UserRoleSeeder
            // (requires Organization, Role, UserRole models)
            $roleSeederPath = 'database/seeders/RoleSeeder.php';
            if ($dryRun) {
                $this->printSuccess("[dry-run] Would generate RoleSeeder → {$roleSeederPath}");
            } else {
                $content = $this->seederGenerator->generateRoleSeeder($roles);
                File::ensureDirectoryExists(database_path('seeders'));
                File::put(base_path($roleSeederPath), $content);
                $this->printSuccess("RoleSeeder → {$roleSeederPath}");
            }
            $this->generatedFiles[] = $roleSeederPath;

            $userRoleSeederPath = 'database/seeders/UserRoleSeeder.php';
            if ($dryRun) {
                $this->printSuccess("[dry-run] Would generate UserRoleSeeder → {$userRoleSeederPath}");
            } else {
                $content = $this->seederGenerator->generateUserRoleSeeder($roles, $aggregatedPermissions);
                File::put(base_path($userRoleSeederPath), $content);
                $this->printSuccess("UserRoleSeeder → {$userRoleSeederPath}");
            }
            $this->generatedFiles[] = $userRoleSeederPath;
        } else {
            // Non-tenant: generate UserPermissionSeeder
            // (permissions stored directly on User model, no Role/Organization/UserRole)
            $seederPath = 'database/seeders/UserPermissionSeeder.php';
            if ($dryRun) {
                $this->printSuccess("[dry-run] Would generate UserPermissionSeeder → {$seederPath}");
            } else {
                $content = $this->seederGenerator->generateUserPermissionSeeder($roles, $aggregatedPermissions);
                File::ensureDirectoryExists(database_path('seeders'));
                File::put(base_path($seederPath), $content);
                $this->printSuccess("UserPermissionSeeder → {$seederPath}");
            }
            $this->generatedFiles[] = $seederPath;
        }
    }

    // ------------------------------------------------------------------
    // Multi-tenancy detection
    // ------------------------------------------------------------------

    /**
     * Check whether the app has a tenant route group configured.
     *
     * When a tenant route group exists, the app uses Organization, Role,
     * and UserRole models for multi-tenant permission checks. When absent,
     * permissions are stored directly on the User model's `permissions` column.
     */
    protected function isMultiTenantEnabled(): bool
    {
        $configPath = base_path('config/rhino.php');

        if (!file_exists($configPath)) {
            return false;
        }

        $config = require $configPath;

        return isset($config['route_groups']['tenant']);
    }

    /**
     * Get the organization identifier column used in tenant URLs.
     *
     * Reads from config/rhino.php → organization_identifier, defaults to 'id'.
     */
    protected function getOrganizationIdentifierColumn(): string
    {
        $configPath = base_path('config/rhino.php');

        if (!file_exists($configPath)) {
            return 'id';
        }

        $config = require $configPath;

        return $config['organization_identifier'] ?? 'id';
    }

    // ------------------------------------------------------------------
    // Config registration
    // ------------------------------------------------------------------

    protected function registerModelInConfig(string $name, string $slug): void
    {
        $configPath = base_path('config/rhino.php');

        if (!file_exists($configPath)) {
            return;
        }

        $content = File::get($configPath);
        $modelClass = "\\App\\Models\\{$name}::class";

        if (Str::contains($content, "'{$slug}'") || Str::contains($content, "\"{$slug}\"")) {
            return;
        }

        $newEntry = "        '{$slug}' => {$modelClass},";

        $updated = preg_replace(
            "/('models'\s*=>\s*\[)(.*?)(\s*\])/s",
            '$1$2' . "\n" . $newEntry . '$3',
            $content,
            1
        );

        if ($updated !== $content) {
            File::put($configPath, $updated);
        }
    }

    // ------------------------------------------------------------------
    // Summary
    // ------------------------------------------------------------------

    protected function printSummary(): void
    {
        $cyan = "\033[38;2;0;255;255m";
        $green = "\033[38;2;100;220;100m";
        $yellow = "\033[38;2;255;220;50m";
        $red = "\033[38;2;255;80;80m";
        $dim = "\033[38;2;120;120;120m";
        $reset = "\033[0m";

        $this->newLine();
        $this->output->writeln("  {$cyan}━━━ Summary ━━━{$reset}");
        $this->newLine();

        if ($this->processedCount > 0) {
            $this->output->writeln("  {$green}✓{$reset} {$this->processedCount} model(s) processed");
        }
        if ($this->skippedCount > 0) {
            $this->output->writeln("  {$dim}○{$reset} {$this->skippedCount} model(s) unchanged, skipped");
        }
        if ($this->errorCount > 0) {
            $this->output->writeln("  {$red}✗{$reset} {$this->errorCount} error(s)");
        }

        $fileCount = count($this->generatedFiles);
        if ($fileCount > 0) {
            $action = $this->option('dry-run') ? 'would be generated' : 'generated';
            $this->output->writeln("  {$dim}  {$fileCount} file(s) {$action}{$reset}");
        }

        if (!$this->option('dry-run') && $this->processedCount > 0) {
            $this->newLine();
            $this->output->writeln("  {$cyan}Next steps:{$reset}");
            $this->output->writeln("  {$yellow}1.{$reset} Review generated files");
            $this->output->writeln("  {$yellow}2.{$reset} Run migrations: {$dim}php artisan migrate{$reset}");
            $this->output->writeln("  {$yellow}3.{$reset} Run seeders: {$dim}php artisan db:seed{$reset}");
            $this->output->writeln("  {$yellow}4.{$reset} Run tests: {$dim}php artisan test tests/Model/{$reset}");
        }

        $this->newLine();
    }

}
