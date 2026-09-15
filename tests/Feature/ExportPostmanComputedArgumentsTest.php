<?php

namespace Rhino\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Rhino\Tests\TestCase;
use Rhino\Traits\HasValidation;
use Rhino\Traits\HidableColumns;

/**
 * Mixes parameterless, single-parameter, multi-parameter and all-optional
 * attributes so every branch of the exported request shape is covered.
 */
class ExportArgComputedModel extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'posts';

    protected $fillable = ['title', 'status'];

    protected $validationRules = ['title' => 'string', 'status' => 'boolean'];

    protected $validationRulesStore = ['*' => ['title' => 'required']];

    protected $validationRulesUpdate = ['*' => ['title' => 'sometimes']];

    public function rhinoRecordComputedAttributes(): array
    {
        return [
            'word_count' => fn ($record, $user) => 1,
            'label_since' => [
                'params' => ['since'],
                'using' => fn ($record, $user, $since) => $since,
            ],
            'label_window' => [
                'params' => ['from', 'to'],
                'using' => fn ($record, $user, $from, $to) => "{$from}..{$to}",
            ],
        ];
    }

    public static function rhinoCollectionComputedAttributes(): array
    {
        return [
            'published_count' => fn ($query, $user) => $query->count(),
            'draft_count' => fn ($query, $user) => $query->count(),
            'status_count' => [
                'params' => ['status'],
                'using' => fn ($query, $user, $status) => $query->count(),
            ],
            'range_count' => [
                'params' => ['min', 'max'],
                'using' => fn ($query, $user, $min, $max) => $query->count(),
            ],
            'optional_count' => [
                'params' => ['status'],
                'optional' => ['status'],
                'using' => fn ($query, $user, $status = null) => $query->count(),
            ],
        ];
    }
}

/** Only ONE attribute can be requested without arguments: no combined request. */
class ExportArgOnlyOneFreeModel extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'posts';

    protected $fillable = ['title'];

    protected $validationRules = ['title' => 'string'];

    protected $validationRulesStore = ['*' => ['title' => 'required']];

    protected $validationRulesUpdate = ['*' => ['title' => 'sometimes']];

    public static function rhinoCollectionComputedAttributes(): array
    {
        return [
            'total_count' => fn ($query, $user) => $query->count(),
            'status_count' => [
                'params' => ['status'],
                'using' => fn ($query, $user, $status) => $query->count(),
            ],
        ];
    }
}

class ExportPostmanComputedArgumentsTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('rhino.models', [
            'argPosts' => ExportArgComputedModel::class,
            'oneFreePosts' => ExportArgOnlyOneFreeModel::class,
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
        $path = sys_get_temp_dir() . '/postman_computed_args_' . uniqid() . '.json';
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

    /** @return array<string, string> key => value of a request's query params */
    private function query(array $request): array
    {
        $out = [];
        foreach ($request['request']['url']['query'] ?? [] as $pair) {
            $out[$pair['key']] = $pair['value'];
        }

        return $out;
    }

    // ------------------------------------------------------------------
    // /computed
    // ------------------------------------------------------------------

    public function test_parameterless_attribute_still_uses_the_plain_list_form(): void
    {
        $folder = $this->folder($this->runExportAndDecode(), 'argPosts', 'Computed Attributes');
        $request = collect($folder['item'])->firstWhere('name', 'Computed: published_count');

        $this->assertSame(['attributes' => 'published_count'], $this->query($request));
    }

    public function test_single_parameter_attribute_uses_the_bare_bracket_form(): void
    {
        $folder = $this->folder($this->runExportAndDecode(), 'argPosts', 'Computed Attributes');
        $request = collect($folder['item'])->firstWhere('name', 'Computed: status_count');

        $this->assertSame(['attributes[status_count]' => 'example'], $this->query($request));
    }

    public function test_multi_parameter_attribute_uses_one_key_per_parameter(): void
    {
        $folder = $this->folder($this->runExportAndDecode(), 'argPosts', 'Computed Attributes');
        $request = collect($folder['item'])->firstWhere('name', 'Computed: range_count');

        $this->assertSame([
            'attributes[range_count][min]' => 'example',
            'attributes[range_count][max]' => 'example',
        ], $this->query($request));
    }

    public function test_all_optional_attribute_still_uses_the_bracket_form(): void
    {
        $folder = $this->folder($this->runExportAndDecode(), 'argPosts', 'Computed Attributes');
        $request = collect($folder['item'])->firstWhere('name', 'Computed: optional_count');

        // It declares a parameter, so the bracket form is what shows it off,
        // even though the parameter may be left out.
        $this->assertSame(['attributes[optional_count]' => 'example'], $this->query($request));
    }

    public function test_the_combined_request_excludes_required_parameter_attributes(): void
    {
        $folder = $this->folder($this->runExportAndDecode(), 'argPosts', 'Computed Attributes');
        $request = collect($folder['item'])->firstWhere('name', 'Computed: multiple attributes');

        // status_count and range_count would be a guaranteed 403 without
        // arguments; optional_count is safe because its parameter is optional.
        $this->assertSame(
            ['attributes' => 'published_count,draft_count,optional_count'],
            $this->query($request)
        );
    }

    public function test_the_all_request_still_sends_no_parameters(): void
    {
        $folder = $this->folder($this->runExportAndDecode(), 'argPosts', 'Computed Attributes');
        $request = collect($folder['item'])->firstWhere('name', 'All computed attributes');

        // A bare /computed skips required-parameter attributes server-side, so
        // it stays a valid request.
        $this->assertSame([], $this->query($request));
    }

    public function test_no_combined_request_when_fewer_than_two_attributes_are_argument_free(): void
    {
        $folder = $this->folder($this->runExportAndDecode(), 'oneFreePosts', 'Computed Attributes');
        $names = array_column($folder['item'], 'name');

        $this->assertContains('Computed: total_count', $names);
        $this->assertContains('Computed: status_count', $names);
        $this->assertNotContains('Computed: multiple attributes', $names);
    }

    // ------------------------------------------------------------------
    // index / show
    // ------------------------------------------------------------------

    public function test_index_exports_the_bracket_form_for_a_parameterised_record_attribute(): void
    {
        $index = $this->folder($this->runExportAndDecode(), 'argPosts', 'Index');

        $plain = collect($index['item'])->firstWhere('name', 'With computed attribute word_count');
        $one = collect($index['item'])->firstWhere('name', 'With computed attribute label_since');
        $many = collect($index['item'])->firstWhere('name', 'With computed attribute label_window');

        $this->assertSame(['computed_attributes' => 'word_count'], $this->query($plain));
        $this->assertSame(['computed_attributes[label_since]' => 'example'], $this->query($one));
        $this->assertSame([
            'computed_attributes[label_window][from]' => 'example',
            'computed_attributes[label_window][to]' => 'example',
        ], $this->query($many));
    }

    public function test_show_exports_the_bracket_form_for_a_parameterised_record_attribute(): void
    {
        $show = $this->folder($this->runExportAndDecode(), 'argPosts', 'Show');

        $one = collect($show['item'])->firstWhere('name', 'Show with computed attribute label_since');
        $many = collect($show['item'])->firstWhere('name', 'Show with computed attribute label_window');

        $this->assertSame(['computed_attributes[label_since]' => 'example'], $this->query($one));
        $this->assertSame([
            'computed_attributes[label_window][from]' => 'example',
            'computed_attributes[label_window][to]' => 'example',
        ], $this->query($many));
    }
}
