<?php

namespace Rhino\Tests\Feature;

use Illuminate\Support\Facades\File;
use Rhino\Tests\TestCase;

/**
 * End-to-end coverage for `rhino:blueprint`: discovery, dependency-ordered
 * generation of models/migrations/factories/policies/scopes, dry-run, the
 * circular-dependency warning, and the error/empty guards. No production code
 * is changed. All generated artifacts are cleaned up in tearDown.
 */
class BlueprintCommandTest extends TestCase
{
    private string $bpDir;
    /** @var string[] table names whose migrations may have been generated */
    private array $tables = ['cov_projects', 'cov_tasks', 'cov_comments', 'cov_alphas', 'cov_betas'];
    /** @var string[] model base names whose artifacts may have been generated */
    private array $models = ['CovProject', 'CovTask', 'CovComment', 'CovAlpha', 'CovBeta'];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bpDir = base_path('rhino_cov_bp');
        File::deleteDirectory($this->bpDir);
        File::ensureDirectoryExists($this->bpDir);
        $this->cleanGenerated();
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->bpDir);
        $this->cleanGenerated();
        parent::tearDown();
    }

    private function cleanGenerated(): void
    {
        foreach ($this->tables as $t) {
            foreach (File::glob(database_path("migrations/*_create_{$t}_table.php")) as $f) {
                File::delete($f);
            }
        }
        foreach ($this->models as $m) {
            File::delete(app_path("Models/{$m}.php"));
            File::delete(app_path("Models/Scopes/{$m}Scope.php"));
            File::delete(app_path("Policies/{$m}Policy.php"));
            File::delete(database_path("factories/{$m}Factory.php"));
        }
    }

    private function writeRoles(): void
    {
        File::put($this->bpDir . '/_roles.yaml', "roles:\n  admin:\n    name: Admin\n    description: Admin role\n");
    }

    /** Write a model blueprint; $fk maps column => foreign model. */
    private function writeModel(string $model, string $table, array $fk = []): void
    {
        $yaml = "model: {$model}\nslug: {$table}\ntable: {$table}\noptions:\n  soft_deletes: true\ncolumns:\n";
        $yaml .= "  title:\n    type: string\n";
        foreach ($fk as $col => $foreign) {
            $yaml .= "  {$col}:\n    type: foreignId\n    foreign_model: {$foreign}\n";
        }
        File::put("{$this->bpDir}/{$table}.yaml", $yaml);
    }

    private function migrationOrder(string $table): ?string
    {
        $matches = File::glob(database_path("migrations/*_create_{$table}_table.php"));
        return $matches ? basename($matches[0]) : null;
    }

    // ── happy path: dependency-ordered generation ──────────────────────

    public function test_generates_artifacts_in_dependency_order(): void
    {
        $this->writeRoles();
        // Alphabetically: cov_comments < cov_projects < cov_tasks. By dependency
        // it must be projects → tasks → comments.
        $this->writeModel('CovComment', 'cov_comments', ['cov_task_id' => 'CovTask']);
        $this->writeModel('CovProject', 'cov_projects');
        $this->writeModel('CovTask', 'cov_tasks', ['cov_project_id' => 'CovProject']);

        $this->artisan('rhino:blueprint', [
            '--dir' => 'rhino_cov_bp',
            '--force' => true,
            '--skip-tests' => true,
            '--skip-seeders' => true,
        ])->assertExitCode(0);

        // Models + migrations + factories + policies + scopes generated.
        $this->assertFileExists(app_path('Models/CovProject.php'));
        $this->assertFileExists(app_path('Models/CovTask.php'));
        $this->assertFileExists(database_path('factories/CovProjectFactory.php'));
        $this->assertFileExists(app_path('Policies/CovProjectPolicy.php'));
        $this->assertFileExists(app_path('Models/Scopes/CovProjectScope.php'));

        // Migration filenames carry a sequential timestamp → filename order is
        // the run order. Parent tables must sort before their children.
        $projects = $this->migrationOrder('cov_projects');
        $tasks = $this->migrationOrder('cov_tasks');
        $comments = $this->migrationOrder('cov_comments');
        $this->assertNotNull($projects);
        $this->assertNotNull($tasks);
        $this->assertNotNull($comments);
        $this->assertLessThan($tasks, $projects, 'projects migration must precede tasks');
        $this->assertLessThan($comments, $tasks, 'tasks migration must precede comments');

        // The child migration references the parent table.
        $taskMigration = File::get(File::glob(database_path('migrations/*_create_cov_tasks_table.php'))[0]);
        $this->assertStringContainsString("constrained('cov_projects')", $taskMigration);
    }

    // ── dry-run writes nothing ─────────────────────────────────────────

    public function test_dry_run_generates_no_files(): void
    {
        $this->writeRoles();
        $this->writeModel('CovProject', 'cov_projects');

        $this->artisan('rhino:blueprint', ['--dir' => 'rhino_cov_bp', '--force' => true, '--dry-run' => true])
            ->assertExitCode(0);

        $this->assertFileDoesNotExist(app_path('Models/CovProject.php'));
        $this->assertSame([], File::glob(database_path('migrations/*_create_cov_projects_table.php')));
    }

    // ── circular dependency is reported, run still succeeds ─────────────

    public function test_warns_on_circular_foreign_key_dependency(): void
    {
        $this->writeRoles();
        $this->writeModel('CovAlpha', 'cov_alphas', ['cov_beta_id' => 'CovBeta']);
        $this->writeModel('CovBeta', 'cov_betas', ['cov_alpha_id' => 'CovAlpha']);

        $this->artisan('rhino:blueprint', [
            '--dir' => 'rhino_cov_bp',
            '--force' => true,
            '--skip-tests' => true,
            '--skip-seeders' => true,
        ])
            ->expectsOutputToContain('Circular')
            ->assertExitCode(0);
    }

    // ── guards ─────────────────────────────────────────────────────────

    public function test_errors_when_the_blueprints_directory_is_missing(): void
    {
        $this->artisan('rhino:blueprint', ['--dir' => 'rhino_cov_does_not_exist'])->assertExitCode(1);
    }

    public function test_warns_when_no_blueprint_files_are_present(): void
    {
        // Only a roles file, no model YAMLs.
        $this->writeRoles();
        $this->artisan('rhino:blueprint', ['--dir' => 'rhino_cov_bp', '--force' => true])->assertExitCode(0);
        $this->assertSame([], File::glob(database_path('migrations/*_create_cov_*_table.php')));
    }
}
