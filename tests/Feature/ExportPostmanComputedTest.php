<?php

namespace Rhino\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Rhino\Tests\TestCase;
use Rhino\Traits\HasValidation;
use Rhino\Traits\HidableColumns;

class ExportComputedModel extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'posts';

    protected $fillable = ['title', 'status'];

    protected $validationRules = ['title' => 'string', 'status' => 'boolean'];

    protected $validationRulesStore = ['*' => ['title' => 'required']];

    protected $validationRulesUpdate = ['*' => ['title' => 'sometimes']];

    public static $allowedFilters = ['status'];

    public function rhinoRecordComputedAttributes(): array
    {
        return [
            'word_count' => fn ($record, $user) => str_word_count((string) $record->title),
        ];
    }

    public static function rhinoCollectionComputedAttributes(): array
    {
        return [
            'published_count' => fn ($query, $user) => $query->where('status', true)->count(),
            'draft_count' => fn ($query, $user) => $query->where('status', false)->count(),
        ];
    }
}

/** Declares aggregates but opts the action out. */
class ExportComputedExceptedModel extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'posts';

    protected $fillable = ['title'];

    protected $validationRules = ['title' => 'string'];

    protected $validationRulesStore = ['*' => ['title' => 'required']];

    protected $validationRulesUpdate = ['*' => ['title' => 'sometimes']];

    public static array $exceptActions = ['computed'];

    public static function rhinoCollectionComputedAttributes(): array
    {
        return ['published_count' => fn ($query, $user) => $query->count()];
    }
}

/** Declares nothing — must produce no Computed Attributes folder at all. */
class ExportPlainModel extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'posts';

    protected $fillable = ['title'];

    protected $validationRules = ['title' => 'string'];

    protected $validationRulesStore = ['*' => ['title' => 'required']];

    protected $validationRulesUpdate = ['*' => ['title' => 'sometimes']];
}

class ExportPostmanComputedTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('rhino.models', [
            'computedPosts' => ExportComputedModel::class,
            'computedExcepted' => ExportComputedExceptedModel::class,
            'plainPosts' => ExportPlainModel::class,
        ]);
        $app['config']->set('rhino.route_groups', [
            'default' => ['prefix' => '', 'middleware' => [], 'models' => '*'],
        ]);
        $app['config']->set('rhino.postman.role_class', 'App\Models\Role');
        $app['config']->set('rhino.postman.user_role_class', 'App\Models\UserRole');
        $app['config']->set('rhino.postman.user_class', 'App\Models\User');
    }

    private function runExportAndDecode(): array
    {
        $path = sys_get_temp_dir() . '/postman_computed_' . uniqid() . '.json';
        $exitCode = Artisan::call('rhino:export-postman', ['--output' => $path]);
        $this->assertSame(0, $exitCode);
        $json = json_decode(File::get($path), true);
        $this->assertNotNull($json);
        @unlink($path);

        return $json;
    }

    private function folder(array $json, string $model, string $action): ?array
    {
        $modelFolder = collect($json['item'])->firstWhere('name', $model);
        if ($modelFolder === null) {
            return null;
        }

        return collect($modelFolder['item'])->firstWhere('name', $action);
    }

    private function rawUrl(array $request): string
    {
        return $request['request']['url']['raw'] ?? '';
    }

    public function test_computed_folder_is_exported_for_declaring_models(): void
    {
        $json = $this->runExportAndDecode();

        $folder = $this->folder($json, 'computedPosts', 'Computed Attributes');
        $this->assertNotNull($folder);

        $names = array_column($folder['item'], 'name');
        $this->assertContains('All computed attributes', $names);
        $this->assertContains('Computed: published_count', $names);
        $this->assertContains('Computed: draft_count', $names);
        $this->assertContains('Computed: multiple attributes', $names);
    }

    public function test_computed_requests_target_the_computed_endpoint(): void
    {
        $json = $this->runExportAndDecode();

        $folder = $this->folder($json, 'computedPosts', 'Computed Attributes');
        $all = collect($folder['item'])->firstWhere('name', 'All computed attributes');
        $one = collect($folder['item'])->firstWhere('name', 'Computed: published_count');
        $many = collect($folder['item'])->firstWhere('name', 'Computed: multiple attributes');

        $this->assertStringContainsString('/computedPosts/computed', $this->rawUrl($all));
        $this->assertStringNotContainsString('attributes=', $this->rawUrl($all));
        $this->assertStringContainsString('attributes=published_count', $this->rawUrl($one));
        // Postman URL-encodes the comma in the raw URL.
        $this->assertStringContainsString('attributes=published_count%2Cdraft_count', $this->rawUrl($many));
        $this->assertSame(
            'published_count,draft_count',
            collect($many['request']['url']['query'])->firstWhere('key', 'attributes')['value']
        );
        $this->assertSame('GET', $all['request']['method']);
    }

    public function test_computed_folder_is_absent_without_a_declaration(): void
    {
        $json = $this->runExportAndDecode();

        $this->assertNull($this->folder($json, 'plainPosts', 'Computed Attributes'));
    }

    public function test_computed_folder_is_absent_when_the_action_is_excepted(): void
    {
        $json = $this->runExportAndDecode();

        $this->assertNull($this->folder($json, 'computedExcepted', 'Computed Attributes'));
    }

    public function test_index_exports_a_record_computed_attribute_example(): void
    {
        $json = $this->runExportAndDecode();

        $index = $this->folder($json, 'computedPosts', 'Index');
        $this->assertNotNull($index);

        $request = collect($index['item'])->firstWhere('name', 'With computed attribute word_count');
        $this->assertNotNull($request);
        $this->assertStringContainsString('computed_attributes=word_count', $this->rawUrl($request));
    }

    public function test_show_exports_a_record_computed_attribute_example(): void
    {
        $json = $this->runExportAndDecode();

        $show = $this->folder($json, 'computedPosts', 'Show');
        $this->assertNotNull($show);

        $request = collect($show['item'])->firstWhere('name', 'Show with computed attribute word_count');
        $this->assertNotNull($request);
        $this->assertStringContainsString('computed_attributes=word_count', $this->rawUrl($request));
    }

    public function test_models_without_record_attributes_get_no_index_example(): void
    {
        $json = $this->runExportAndDecode();

        $index = $this->folder($json, 'plainPosts', 'Index');
        $this->assertNotNull($index);

        $names = array_column($index['item'], 'name');
        foreach ($names as $name) {
            $this->assertStringNotContainsString('computed attribute', $name);
        }
    }

    public function test_excepted_model_still_exports_its_crud_folders(): void
    {
        $json = $this->runExportAndDecode();

        $modelFolder = collect($json['item'])->firstWhere('name', 'computedExcepted');
        $this->assertNotNull($modelFolder);
        $names = array_column($modelFolder['item'], 'name');
        $this->assertContains('Index', $names);
        $this->assertContains('Show', $names);
    }
}
