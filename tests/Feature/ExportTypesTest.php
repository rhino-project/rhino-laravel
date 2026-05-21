<?php

namespace Rhino\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Schema\Blueprint;
use Rhino\Tests\TestCase;
use Rhino\Traits\HasValidation;
use Rhino\Traits\HidableColumns;

class ExportTypesPostModel extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'export_types_posts';
    protected $fillable = ['title', 'status', 'view_count', 'is_published', 'metadata', 'published_at'];
}

class ExportTypesBlogModel extends Model
{
    use HasValidation, HidableColumns, SoftDeletes;

    protected $table = 'export_types_blogs';
    protected $fillable = ['name', 'description', 'rating'];
}

class ExportTypesTest extends TestCase
{
    private string $outputDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->outputDir = sys_get_temp_dir() . '/rhino_export_types_test_' . uniqid();
        mkdir($this->outputDir, 0755, true);

        // Create test tables
        Schema::create('export_types_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->string('status')->default('draft');
            $table->integer('view_count')->default(0);
            $table->boolean('is_published')->default(false);
            $table->json('metadata')->nullable();
            $table->timestamp('published_at')->nullable();
            $table->timestamps();
        });

        Schema::create('export_types_blogs', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->text('description')->nullable();
            $table->decimal('rating', 3, 2)->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    protected function tearDown(): void
    {
        if (is_dir($this->outputDir)) {
            $this->recursiveDelete($this->outputDir);
        }

        Schema::dropIfExists('export_types_posts');
        Schema::dropIfExists('export_types_blogs');

        parent::tearDown();
    }

    private function recursiveDelete(string $dir): void
    {
        $items = array_diff(scandir($dir), ['.', '..']);
        foreach ($items as $item) {
            $path = $dir . '/' . $item;
            is_dir($path) ? $this->recursiveDelete($path) : unlink($path);
        }
        rmdir($dir);
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('rhino.models', [
            'posts' => ExportTypesPostModel::class,
            'blogs' => ExportTypesBlogModel::class,
        ]);
    }

    /** @test */
    public function it_runs_without_error_with_output_flag(): void
    {
        $outputFile = $this->outputDir . '/rhino.d.ts';

        $exitCode = Artisan::call('rhino:export-types', [
            '--output' => $outputFile,
        ]);

        $this->assertEquals(0, $exitCode);
        $this->assertFileExists($outputFile);
    }

    /** @test */
    public function it_generates_interfaces_for_all_registered_models(): void
    {
        $outputFile = $this->outputDir . '/rhino.d.ts';

        Artisan::call('rhino:export-types', ['--output' => $outputFile]);

        $content = file_get_contents($outputFile);

        // Both models should have interfaces (openapi-typescript uses components.schemas keys)
        $this->assertStringContainsString('Post', $content);
        $this->assertStringContainsString('Blog', $content);
    }

    /** @test */
    public function it_maps_integer_columns_correctly(): void
    {
        $outputFile = $this->outputDir . '/rhino.d.ts';

        Artisan::call('rhino:export-types', ['--output' => $outputFile]);

        $content = file_get_contents($outputFile);

        // view_count is an integer column, should map to number
        $this->assertMatchesRegularExpression('/view_count\??\s*:\s*number/', $content);
    }

    /** @test */
    public function it_maps_boolean_columns_correctly(): void
    {
        $outputFile = $this->outputDir . '/rhino.d.ts';

        Artisan::call('rhino:export-types', ['--output' => $outputFile]);

        $content = file_get_contents($outputFile);

        // SQLite stores booleans as integers, so is_published maps to number.
        // On PostgreSQL/MySQL it would map to boolean. Both are valid.
        $this->assertMatchesRegularExpression('/is_published\??\s*:\s*(boolean|number)/', $content);
    }

    /** @test */
    public function it_maps_string_columns_correctly(): void
    {
        $outputFile = $this->outputDir . '/rhino.d.ts';

        Artisan::call('rhino:export-types', ['--output' => $outputFile]);

        $content = file_get_contents($outputFile);

        // title is a string column
        $this->assertMatchesRegularExpression('/title\??\s*:\s*string/', $content);
    }

    /** @test */
    public function it_makes_all_fields_optional(): void
    {
        $outputFile = $this->outputDir . '/rhino.d.ts';

        Artisan::call('rhino:export-types', ['--output' => $outputFile]);

        $content = file_get_contents($outputFile);

        // No "required" array should be present in the generated output
        // openapi-typescript marks required fields without '?'
        // All our fields should have '?' since we don't set required in the spec
        // Check that the interface doesn't have non-optional fields
        $this->assertStringNotContainsString('required', strtolower($content));
    }

    /** @test */
    public function it_fails_when_no_output_paths_configured(): void
    {
        // Remove env paths
        $this->app['config']->set('rhino.client_path', null);
        $this->app['config']->set('rhino.mobile_path', null);

        $exitCode = Artisan::call('rhino:export-types');

        $this->assertEquals(1, $exitCode);
        $this->assertStringContainsString('No output paths configured', Artisan::output());
    }

    /** @test */
    public function it_uses_client_path_from_config(): void
    {
        $clientDir = $this->outputDir . '/client';
        mkdir($clientDir . '/src/types', 0755, true);

        $this->app['config']->set('rhino.client_path', $clientDir);
        $this->app['config']->set('rhino.mobile_path', null);

        $exitCode = Artisan::call('rhino:export-types');

        $this->assertEquals(0, $exitCode);
        $this->assertFileExists($clientDir . '/src/types/rhino.d.ts');
    }

    /** @test */
    public function it_uses_mobile_path_from_config(): void
    {
        $mobileDir = $this->outputDir . '/mobile';
        mkdir($mobileDir . '/src/types', 0755, true);

        $this->app['config']->set('rhino.client_path', null);
        $this->app['config']->set('rhino.mobile_path', $mobileDir);

        $exitCode = Artisan::call('rhino:export-types');

        $this->assertEquals(0, $exitCode);
        $this->assertFileExists($mobileDir . '/src/types/rhino.d.ts');
    }

    /** @test */
    public function it_writes_to_both_paths_when_both_set(): void
    {
        $clientDir = $this->outputDir . '/client';
        $mobileDir = $this->outputDir . '/mobile';

        $this->app['config']->set('rhino.client_path', $clientDir);
        $this->app['config']->set('rhino.mobile_path', $mobileDir);

        $exitCode = Artisan::call('rhino:export-types');

        $this->assertEquals(0, $exitCode);
        $this->assertFileExists($clientDir . '/src/types/rhino.d.ts');
        $this->assertFileExists($mobileDir . '/src/types/rhino.d.ts');
    }

    /** @test */
    public function it_output_flag_overrides_env_paths(): void
    {
        $explicitPath = $this->outputDir . '/explicit.d.ts';
        $clientDir = $this->outputDir . '/client';

        $this->app['config']->set('rhino.client_path', $clientDir);

        $exitCode = Artisan::call('rhino:export-types', [
            '--output' => $explicitPath,
        ]);

        $this->assertEquals(0, $exitCode);
        $this->assertFileExists($explicitPath);
        $this->assertFileDoesNotExist($clientDir . '/src/types/rhino.d.ts');
    }

    /** @test */
    public function it_handles_empty_models_config(): void
    {
        $this->app['config']->set('rhino.models', []);

        $exitCode = Artisan::call('rhino:export-types', [
            '--output' => $this->outputDir . '/empty.d.ts',
        ]);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('No models registered', Artisan::output());
    }

    /** @test */
    public function it_cleans_up_temp_openapi_file(): void
    {
        $outputFile = $this->outputDir . '/rhino.d.ts';

        Artisan::call('rhino:export-types', ['--output' => $outputFile]);

        // No temp JSON files should remain
        $tempFiles = glob(sys_get_temp_dir() . '/rhino_openapi_*.json');
        $this->assertEmpty($tempFiles);
    }

    /** @test */
    public function it_converts_slug_to_singular_pascal_case_interface(): void
    {
        $outputFile = $this->outputDir . '/rhino.d.ts';

        Artisan::call('rhino:export-types', ['--output' => $outputFile]);

        $content = file_get_contents($outputFile);

        // 'posts' slug → 'Post' interface, 'blogs' slug → 'Blog' interface
        $this->assertStringContainsString('Post', $content);
        $this->assertStringContainsString('Blog', $content);
    }

    /** @test */
    public function it_includes_soft_delete_column_for_soft_deletable_models(): void
    {
        $outputFile = $this->outputDir . '/rhino.d.ts';

        Artisan::call('rhino:export-types', ['--output' => $outputFile]);

        $content = file_get_contents($outputFile);

        // Blog model uses SoftDeletes, should have deleted_at column
        $this->assertStringContainsString('deleted_at', $content);
    }

    /** @test */
    public function it_skips_nonexistent_model_classes(): void
    {
        $this->app['config']->set('rhino.models', [
            'posts' => ExportTypesPostModel::class,
            'ghosts' => 'NonExistent\\Model\\Class',
        ]);

        $outputFile = $this->outputDir . '/rhino.d.ts';
        $exitCode = Artisan::call('rhino:export-types', ['--output' => $outputFile]);

        $this->assertEquals(0, $exitCode);
        $this->assertStringContainsString('Model class does not exist', Artisan::output());

        $content = file_get_contents($outputFile);
        $this->assertStringContainsString('Post', $content);
    }
}
