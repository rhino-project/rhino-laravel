<?php

namespace Rhino\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\File;
use Rhino\Tests\TestCase;
use Rhino\Traits\HasValidation;
use Rhino\Traits\HidableColumns;

class ExportPostModel extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'posts';

    protected $fillable = ['title', 'status'];

    protected $validationRules = [
        'title' => 'string|max:255',
        'status' => 'boolean',
    ];

    protected $validationRulesStore = [
        'admin' => ['title' => 'required', 'status' => 'nullable'],
        '*' => ['title' => 'required'],
    ];

    protected $validationRulesUpdate = [
        'admin' => ['title' => 'sometimes', 'status' => 'nullable'],
        '*' => ['title' => 'sometimes'],
    ];

    public static $allowedFilters = ['title', 'status'];
    public static $allowedSorts = ['title', 'created_at'];
    public static $allowedFields = ['id', 'title', 'status'];
    public static $allowedIncludes = ['blog'];
    public static $allowedSearch = ['title'];
    public static $defaultSort = 'created_at';
}

class ExportPostModelWithSoftDeletes extends Model
{
    use HasValidation, HidableColumns, SoftDeletes;

    protected $table = 'posts';

    protected $fillable = ['title'];

    protected $validationRules = ['title' => 'string'];
    protected $validationRulesStore = ['*' => ['title' => 'required']];
    protected $validationRulesUpdate = ['*' => ['title' => 'sometimes']];

    public static $allowedSorts = ['deleted_at'];
}

class ExportPostModelWithExcept extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'posts';
    protected $fillable = ['title'];
    protected $validationRules = ['title' => 'string'];
    protected $validationRulesStore = ['*' => ['title' => 'required']];
    protected $validationRulesUpdate = ['*' => ['title' => 'sometimes']];

    public static array $exceptActions = ['destroy', 'update'];
}

class ExportPostmanTest extends TestCase
{
    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('rhino.models', [
            'exportPosts' => ExportPostModel::class,
            'exportPostsSoft' => ExportPostModelWithSoftDeletes::class,
            'exportPostsExcept' => ExportPostModelWithExcept::class,
        ]);
        $app['config']->set('rhino.route_groups', [
            'default' => ['prefix' => '', 'middleware' => [], 'models' => '*'],
        ]);
        $app['config']->set('rhino.postman.role_class', 'App\Models\Role');
        $app['config']->set('rhino.postman.user_role_class', 'App\Models\UserRole');
        $app['config']->set('rhino.postman.user_class', 'App\Models\User');
    }

    private function runExportAndDecode(array $options = []): array
    {
        $path = sys_get_temp_dir() . '/postman_export_test_' . uniqid() . '.json';
        $defaults = ['--output' => $path];
        $exitCode = Artisan::call('rhino:export-postman', array_merge($defaults, $options));
        $this->assertSame(0, $exitCode);
        $this->assertFileExists($path);
        $json = json_decode(File::get($path), true);
        $this->assertNotNull($json);
        @unlink($path);
        return $json;
    }

    private function runExportWithConfig(array $models, array $routeGroups, array $options = []): array
    {
        $this->app['config']->set('rhino.models', $models);
        $this->app['config']->set('rhino.route_groups', $routeGroups);
        return $this->runExportAndDecode($options);
    }

    // ─── Original tests (single default group) ───

    public function test_collection_json_is_valid_and_has_correct_structure(): void
    {
        $json = $this->runExportAndDecode(['--project-name' => 'Test API']);

        $this->assertArrayHasKey('info', $json);
        $this->assertSame('Test API', $json['info']['name']);
        $this->assertSame('https://schema.getpostman.com/json/collection/v2.1.0/collection.json', $json['info']['schema']);
        $this->assertArrayHasKey('variable', $json);
        $this->assertArrayHasKey('item', $json);
        $this->assertIsArray($json['item']);
    }

    public function test_authentication_folder_is_first(): void
    {
        $json = $this->runExportAndDecode();

        $first = $json['item'][0] ?? null;
        $this->assertNotNull($first);
        $this->assertSame('Authentication', $first['name']);
        $authNames = array_column($first['item'], 'name');
        $this->assertContains('Login', $authNames);
        $this->assertContains('Logout', $authNames);
        $this->assertContains('Password recover', $authNames);
        $this->assertContains('Password reset', $authNames);
        $this->assertContains('Register (with invitation)', $authNames);
        $this->assertContains('Accept invitation', $authNames);
    }

    public function test_models_from_config_appear_as_top_level_folders_with_config_slug_name(): void
    {
        $json = $this->runExportAndDecode();

        $names = array_column($json['item'], 'name');
        $this->assertContains('Authentication', $names);
        $this->assertContains('exportPosts', $names);
        $this->assertContains('exportPostsSoft', $names);
        $this->assertContains('exportPostsExcept', $names);
    }

    public function test_model_folder_has_action_folders_directly(): void
    {
        $json = $this->runExportAndDecode();

        $exportPostsFolder = collect($json['item'])->firstWhere('name', 'exportPosts');
        $this->assertNotNull($exportPostsFolder);
        $actionNames = array_column($exportPostsFolder['item'], 'name');
        $this->assertContains('Index', $actionNames);
        $this->assertContains('Show', $actionNames);
        $this->assertContains('Store', $actionNames);
    }

    public function test_index_has_query_builder_examples(): void
    {
        $json = $this->runExportAndDecode();

        $exportPostsFolder = collect($json['item'])->firstWhere('name', 'exportPosts');
        $this->assertNotNull($exportPostsFolder);
        $indexFolder = collect($exportPostsFolder['item'])->firstWhere('name', 'Index');
        $this->assertNotNull($indexFolder);
        $requestNames = array_column($indexFolder['item'], 'name');
        $this->assertContains('List all', $requestNames);
        $this->assertContains('Filter by title', $requestNames);
        $this->assertContains('Sort by title (asc)', $requestNames);
        $this->assertContains('Include blog', $requestNames);
        $this->assertContains('Select fields', $requestNames);
        $this->assertContains('Search', $requestNames);
        $this->assertContains('Paginate', $requestNames);
        $this->assertContains('Combined', $requestNames);
    }

    public function test_soft_delete_actions_appear_only_for_soft_deletes_models(): void
    {
        $json = $this->runExportAndDecode();

        $regularFolder = collect($json['item'])->firstWhere('name', 'exportPosts');
        $softFolder = collect($json['item'])->firstWhere('name', 'exportPostsSoft');

        $regularActionNames = array_column($regularFolder['item'] ?? [], 'name');
        $this->assertNotContains('Trashed', $regularActionNames);
        $this->assertNotContains('Restore', $regularActionNames);
        $this->assertNotContains('Force Delete', $regularActionNames);

        $softActionNames = array_column($softFolder['item'] ?? [], 'name');
        $this->assertContains('Trashed', $softActionNames);
        $this->assertContains('Restore', $softActionNames);
        $this->assertContains('Force Delete', $softActionNames);
    }

    public function test_except_actions_are_excluded(): void
    {
        $json = $this->runExportAndDecode();

        $exceptFolder = collect($json['item'])->firstWhere('name', 'exportPostsExcept');
        $this->assertNotNull($exceptFolder);
        $actionNames = array_column($exceptFolder['item'] ?? [], 'name');
        $this->assertNotContains('Destroy', $actionNames);
        $this->assertNotContains('Update', $actionNames);
        $this->assertContains('Index', $actionNames);
        $this->assertContains('Show', $actionNames);
        $this->assertContains('Store', $actionNames);
    }

    public function test_all_requests_have_bearer_token_header(): void
    {
        $json = $this->runExportAndDecode();

        $exportPostsFolder = collect($json['item'])->firstWhere('name', 'exportPosts');
        $indexFolder = collect($exportPostsFolder['item'])->firstWhere('name', 'Index');
        $listAllRequest = $indexFolder['item'][0]['request'] ?? null;
        $this->assertNotNull($listAllRequest);
        $headers = $listAllRequest['header'] ?? [];
        $authHeader = collect($headers)->firstWhere('key', 'Authorization');
        $this->assertNotNull($authHeader);
        $this->assertSame('Bearer {{token}}', $authHeader['value']);
    }

    public function test_non_multi_tenant_urls_omit_organization_prefix(): void
    {
        $json = $this->runExportAndDecode(['--base-url' => 'http://localhost:8000/api']);

        $vars = collect($json['variable'])->pluck('key')->toArray();
        $this->assertNotContains('organization', $vars);

        $exportPostsFolder = collect($json['item'])->firstWhere('name', 'exportPosts');
        $indexFolder = collect($exportPostsFolder['item'])->firstWhere('name', 'Index');
        $listAll = $indexFolder['item'][0];
        $rawUrl = $listAll['request']['url']['raw'] ?? '';
        $this->assertStringNotContainsString('organization', $rawUrl);
        $this->assertStringContainsString('/exportPosts', $rawUrl);
    }

    public function test_store_request_has_body_from_validation_rules(): void
    {
        $json = $this->runExportAndDecode();

        $exportPostsFolder = collect($json['item'])->firstWhere('name', 'exportPosts');
        $this->assertNotNull($exportPostsFolder);
        $storeFolder = collect($exportPostsFolder['item'])->firstWhere('name', 'Store');
        $this->assertNotNull($storeFolder);
        $createRequest = $storeFolder['item'][0]['request'] ?? null;
        $this->assertNotNull($createRequest);
        $this->assertArrayHasKey('body', $createRequest);
        $this->assertSame('raw', $createRequest['body']['mode']);
        $body = json_decode($createRequest['body']['raw'], true);
        $this->assertIsArray($body);
        $this->assertArrayHasKey('title', $body);
    }

    public function test_collection_variables_include_base_url_and_model_id(): void
    {
        $json = $this->runExportAndDecode(['--base-url' => 'https://api.example.com/v1']);

        $vars = collect($json['variable'])->keyBy('key');
        $this->assertSame('https://api.example.com/v1', $vars['baseUrl']['value']);
        $this->assertArrayHasKey('modelId', $vars->toArray());
    }

    // ─── Single group: non-tenant (default, no prefix) ───

    public function test_single_non_tenant_group_has_flat_structure(): void
    {
        $json = $this->runExportWithConfig(
            ['posts' => ExportPostModel::class],
            ['default' => ['prefix' => '', 'middleware' => [], 'models' => '*']]
        );

        $names = array_column($json['item'], 'name');
        $this->assertContains('Authentication', $names);
        $this->assertContains('posts', $names);
        $this->assertNotContains('default', $names);
    }

    public function test_single_non_tenant_group_urls_have_no_prefix(): void
    {
        $json = $this->runExportWithConfig(
            ['posts' => ExportPostModel::class],
            ['default' => ['prefix' => '', 'middleware' => [], 'models' => '*']]
        );

        $postsFolder = collect($json['item'])->firstWhere('name', 'posts');
        $indexFolder = collect($postsFolder['item'])->firstWhere('name', 'Index');
        $rawUrl = $indexFolder['item'][0]['request']['url']['raw'] ?? '';
        $this->assertStringContainsString('{{baseUrl}}/posts', $rawUrl);
        $this->assertStringNotContainsString('{{organization}}', $rawUrl);
    }

    public function test_single_non_tenant_group_omits_organization_variable(): void
    {
        $json = $this->runExportWithConfig(
            ['posts' => ExportPostModel::class],
            ['default' => ['prefix' => '', 'middleware' => [], 'models' => '*']]
        );

        $varKeys = collect($json['variable'])->pluck('key')->toArray();
        $this->assertNotContains('organization', $varKeys);
    }

    // ─── Single group: tenant (with {organization} prefix) ───

    public function test_single_tenant_group_has_flat_structure(): void
    {
        $json = $this->runExportWithConfig(
            ['posts' => ExportPostModel::class],
            ['tenant' => ['prefix' => '{organization}', 'middleware' => ['resolve-org'], 'models' => '*']]
        );

        $names = array_column($json['item'], 'name');
        $this->assertContains('Authentication', $names);
        $this->assertContains('posts', $names);
        $this->assertNotContains('tenant', $names);
    }

    public function test_single_tenant_group_urls_have_organization_prefix(): void
    {
        $json = $this->runExportWithConfig(
            ['posts' => ExportPostModel::class],
            ['tenant' => ['prefix' => '{organization}', 'middleware' => ['resolve-org'], 'models' => '*']]
        );

        $postsFolder = collect($json['item'])->firstWhere('name', 'posts');
        $indexFolder = collect($postsFolder['item'])->firstWhere('name', 'Index');
        $rawUrl = $indexFolder['item'][0]['request']['url']['raw'] ?? '';
        $this->assertStringContainsString('{{baseUrl}}/{{organization}}/posts', $rawUrl);
    }

    public function test_single_tenant_group_includes_organization_variable(): void
    {
        $json = $this->runExportWithConfig(
            ['posts' => ExportPostModel::class],
            ['tenant' => ['prefix' => '{organization}', 'middleware' => ['resolve-org'], 'models' => '*']]
        );

        $varKeys = collect($json['variable'])->pluck('key')->toArray();
        $this->assertContains('organization', $varKeys);
    }

    // ─── Single group: literal prefix (e.g. admin) ───

    public function test_single_group_with_literal_prefix_has_flat_structure(): void
    {
        $json = $this->runExportWithConfig(
            ['posts' => ExportPostModel::class],
            ['admin' => ['prefix' => 'admin', 'middleware' => [], 'models' => '*']]
        );

        $names = array_column($json['item'], 'name');
        $this->assertContains('posts', $names);
        $this->assertNotContains('admin', $names);
    }

    public function test_single_group_with_literal_prefix_uses_prefix_in_urls(): void
    {
        $json = $this->runExportWithConfig(
            ['posts' => ExportPostModel::class],
            ['admin' => ['prefix' => 'admin', 'middleware' => [], 'models' => '*']]
        );

        $postsFolder = collect($json['item'])->firstWhere('name', 'posts');
        $indexFolder = collect($postsFolder['item'])->firstWhere('name', 'Index');
        $rawUrl = $indexFolder['item'][0]['request']['url']['raw'] ?? '';
        $this->assertStringContainsString('{{baseUrl}}/admin/posts', $rawUrl);
    }

    public function test_single_group_with_literal_prefix_omits_organization_variable(): void
    {
        $json = $this->runExportWithConfig(
            ['posts' => ExportPostModel::class],
            ['admin' => ['prefix' => 'admin', 'middleware' => [], 'models' => '*']]
        );

        $varKeys = collect($json['variable'])->pluck('key')->toArray();
        $this->assertNotContains('organization', $varKeys);
    }

    // ─── Multiple groups: tenant + public ───

    public function test_multiple_groups_creates_group_level_folders(): void
    {
        $json = $this->runExportWithConfig(
            ['posts' => ExportPostModel::class, 'categories' => ExportPostModelWithExcept::class],
            [
                'tenant' => ['prefix' => '{organization}', 'middleware' => ['resolve-org'], 'models' => '*'],
                'public' => ['prefix' => 'public', 'middleware' => [], 'models' => ['categories']],
            ]
        );

        $names = array_column($json['item'], 'name');
        $this->assertContains('Authentication', $names);
        $this->assertContains('tenant', $names);
        $this->assertContains('public', $names);
        $this->assertNotContains('posts', $names);
        $this->assertNotContains('categories', $names);
    }

    public function test_multiple_groups_tenant_folder_contains_all_models(): void
    {
        $json = $this->runExportWithConfig(
            ['posts' => ExportPostModel::class, 'categories' => ExportPostModelWithExcept::class],
            [
                'tenant' => ['prefix' => '{organization}', 'middleware' => ['resolve-org'], 'models' => '*'],
                'public' => ['prefix' => 'public', 'middleware' => [], 'models' => ['categories']],
            ]
        );

        $tenantFolder = collect($json['item'])->firstWhere('name', 'tenant');
        $this->assertNotNull($tenantFolder);
        $modelNames = array_column($tenantFolder['item'], 'name');
        $this->assertContains('posts', $modelNames);
        $this->assertContains('categories', $modelNames);
    }

    public function test_multiple_groups_public_folder_contains_only_specified_models(): void
    {
        $json = $this->runExportWithConfig(
            ['posts' => ExportPostModel::class, 'categories' => ExportPostModelWithExcept::class],
            [
                'tenant' => ['prefix' => '{organization}', 'middleware' => ['resolve-org'], 'models' => '*'],
                'public' => ['prefix' => 'public', 'middleware' => [], 'models' => ['categories']],
            ]
        );

        $publicFolder = collect($json['item'])->firstWhere('name', 'public');
        $this->assertNotNull($publicFolder);
        $modelNames = array_column($publicFolder['item'], 'name');
        $this->assertContains('categories', $modelNames);
        $this->assertNotContains('posts', $modelNames);
    }

    public function test_multiple_groups_tenant_urls_have_organization_prefix(): void
    {
        $json = $this->runExportWithConfig(
            ['posts' => ExportPostModel::class, 'categories' => ExportPostModelWithExcept::class],
            [
                'tenant' => ['prefix' => '{organization}', 'middleware' => ['resolve-org'], 'models' => '*'],
                'public' => ['prefix' => 'public', 'middleware' => [], 'models' => ['categories']],
            ]
        );

        $tenantFolder = collect($json['item'])->firstWhere('name', 'tenant');
        $postsFolder = collect($tenantFolder['item'])->firstWhere('name', 'posts');
        $indexFolder = collect($postsFolder['item'])->firstWhere('name', 'Index');
        $rawUrl = $indexFolder['item'][0]['request']['url']['raw'] ?? '';
        $this->assertStringContainsString('{{baseUrl}}/{{organization}}/posts', $rawUrl);
    }

    public function test_multiple_groups_public_urls_have_public_prefix(): void
    {
        $json = $this->runExportWithConfig(
            ['posts' => ExportPostModel::class, 'categories' => ExportPostModelWithExcept::class],
            [
                'tenant' => ['prefix' => '{organization}', 'middleware' => ['resolve-org'], 'models' => '*'],
                'public' => ['prefix' => 'public', 'middleware' => [], 'models' => ['categories']],
            ]
        );

        $publicFolder = collect($json['item'])->firstWhere('name', 'public');
        $categoriesFolder = collect($publicFolder['item'])->firstWhere('name', 'categories');
        $indexFolder = collect($categoriesFolder['item'])->firstWhere('name', 'Index');
        $rawUrl = $indexFolder['item'][0]['request']['url']['raw'] ?? '';
        $this->assertStringContainsString('{{baseUrl}}/public/categories', $rawUrl);
    }

    public function test_multiple_groups_includes_organization_variable_when_any_group_has_param_prefix(): void
    {
        $json = $this->runExportWithConfig(
            ['posts' => ExportPostModel::class, 'categories' => ExportPostModelWithExcept::class],
            [
                'tenant' => ['prefix' => '{organization}', 'middleware' => ['resolve-org'], 'models' => '*'],
                'public' => ['prefix' => 'public', 'middleware' => [], 'models' => ['categories']],
            ]
        );

        $varKeys = collect($json['variable'])->pluck('key')->toArray();
        $this->assertContains('organization', $varKeys);
    }

    public function test_multiple_groups_model_actions_nested_under_group_then_model(): void
    {
        $json = $this->runExportWithConfig(
            ['posts' => ExportPostModel::class, 'categories' => ExportPostModelWithExcept::class],
            [
                'tenant' => ['prefix' => '{organization}', 'middleware' => ['resolve-org'], 'models' => '*'],
                'public' => ['prefix' => 'public', 'middleware' => [], 'models' => ['categories']],
            ]
        );

        $tenantFolder = collect($json['item'])->firstWhere('name', 'tenant');
        $postsFolder = collect($tenantFolder['item'])->firstWhere('name', 'posts');
        $actionNames = array_column($postsFolder['item'], 'name');
        $this->assertContains('Index', $actionNames);
        $this->assertContains('Show', $actionNames);
        $this->assertContains('Store', $actionNames);
        $this->assertContains('Update', $actionNames);
        $this->assertContains('Destroy', $actionNames);
    }

    public function test_multiple_groups_except_actions_still_respected_per_model(): void
    {
        $json = $this->runExportWithConfig(
            ['posts' => ExportPostModel::class, 'categories' => ExportPostModelWithExcept::class],
            [
                'tenant' => ['prefix' => '{organization}', 'middleware' => ['resolve-org'], 'models' => '*'],
                'public' => ['prefix' => 'public', 'middleware' => [], 'models' => ['categories']],
            ]
        );

        $publicFolder = collect($json['item'])->firstWhere('name', 'public');
        $categoriesFolder = collect($publicFolder['item'])->firstWhere('name', 'categories');
        $actionNames = array_column($categoriesFolder['item'], 'name');
        $this->assertNotContains('Destroy', $actionNames);
        $this->assertNotContains('Update', $actionNames);
        $this->assertContains('Index', $actionNames);
    }

    // ─── Multiple groups: no tenant (admin + public) ───

    public function test_multiple_non_tenant_groups_creates_group_folders(): void
    {
        $json = $this->runExportWithConfig(
            ['posts' => ExportPostModel::class, 'categories' => ExportPostModelWithExcept::class],
            [
                'admin' => ['prefix' => 'admin', 'middleware' => ['admin-check'], 'models' => '*'],
                'public' => ['prefix' => '', 'middleware' => [], 'models' => ['categories']],
            ]
        );

        $names = array_column($json['item'], 'name');
        $this->assertContains('admin', $names);
        $this->assertContains('public', $names);
    }

    public function test_multiple_non_tenant_groups_omits_organization_variable(): void
    {
        $json = $this->runExportWithConfig(
            ['posts' => ExportPostModel::class, 'categories' => ExportPostModelWithExcept::class],
            [
                'admin' => ['prefix' => 'admin', 'middleware' => ['admin-check'], 'models' => '*'],
                'public' => ['prefix' => '', 'middleware' => [], 'models' => ['categories']],
            ]
        );

        $varKeys = collect($json['variable'])->pluck('key')->toArray();
        $this->assertNotContains('organization', $varKeys);
    }

    public function test_admin_group_urls_have_admin_prefix(): void
    {
        $json = $this->runExportWithConfig(
            ['posts' => ExportPostModel::class, 'categories' => ExportPostModelWithExcept::class],
            [
                'admin' => ['prefix' => 'admin', 'middleware' => ['admin-check'], 'models' => '*'],
                'public' => ['prefix' => '', 'middleware' => [], 'models' => ['categories']],
            ]
        );

        $adminFolder = collect($json['item'])->firstWhere('name', 'admin');
        $postsFolder = collect($adminFolder['item'])->firstWhere('name', 'posts');
        $indexFolder = collect($postsFolder['item'])->firstWhere('name', 'Index');
        $rawUrl = $indexFolder['item'][0]['request']['url']['raw'] ?? '';
        $this->assertStringContainsString('{{baseUrl}}/admin/posts', $rawUrl);
    }

    public function test_public_group_with_empty_prefix_has_no_prefix_in_urls(): void
    {
        $json = $this->runExportWithConfig(
            ['posts' => ExportPostModel::class, 'categories' => ExportPostModelWithExcept::class],
            [
                'admin' => ['prefix' => 'admin', 'middleware' => ['admin-check'], 'models' => '*'],
                'public' => ['prefix' => '', 'middleware' => [], 'models' => ['categories']],
            ]
        );

        $publicFolder = collect($json['item'])->firstWhere('name', 'public');
        $categoriesFolder = collect($publicFolder['item'])->firstWhere('name', 'categories');
        $indexFolder = collect($categoriesFolder['item'])->firstWhere('name', 'Index');
        $rawUrl = $indexFolder['item'][0]['request']['url']['raw'] ?? '';
        $this->assertStringContainsString('{{baseUrl}}/categories', $rawUrl);
    }

    // ─── Three groups ───

    public function test_three_groups_all_appear_as_folders(): void
    {
        $json = $this->runExportWithConfig(
            ['posts' => ExportPostModel::class, 'categories' => ExportPostModelWithExcept::class, 'comments' => ExportPostModelWithSoftDeletes::class],
            [
                'tenant' => ['prefix' => '{organization}', 'middleware' => ['resolve-org'], 'models' => '*'],
                'admin' => ['prefix' => 'admin', 'middleware' => ['admin-check'], 'models' => ['posts', 'comments']],
                'public' => ['prefix' => '', 'middleware' => [], 'models' => ['categories']],
            ]
        );

        $names = array_column($json['item'], 'name');
        $this->assertContains('Authentication', $names);
        $this->assertContains('tenant', $names);
        $this->assertContains('admin', $names);
        $this->assertContains('public', $names);
    }

    public function test_three_groups_each_has_correct_models(): void
    {
        $json = $this->runExportWithConfig(
            ['posts' => ExportPostModel::class, 'categories' => ExportPostModelWithExcept::class, 'comments' => ExportPostModelWithSoftDeletes::class],
            [
                'tenant' => ['prefix' => '{organization}', 'middleware' => ['resolve-org'], 'models' => '*'],
                'admin' => ['prefix' => 'admin', 'middleware' => ['admin-check'], 'models' => ['posts', 'comments']],
                'public' => ['prefix' => '', 'middleware' => [], 'models' => ['categories']],
            ]
        );

        $tenantFolder = collect($json['item'])->firstWhere('name', 'tenant');
        $tenantModels = array_column($tenantFolder['item'], 'name');
        $this->assertContains('posts', $tenantModels);
        $this->assertContains('categories', $tenantModels);
        $this->assertContains('comments', $tenantModels);

        $adminFolder = collect($json['item'])->firstWhere('name', 'admin');
        $adminModels = array_column($adminFolder['item'], 'name');
        $this->assertContains('posts', $adminModels);
        $this->assertContains('comments', $adminModels);
        $this->assertNotContains('categories', $adminModels);

        $publicFolder = collect($json['item'])->firstWhere('name', 'public');
        $publicModels = array_column($publicFolder['item'], 'name');
        $this->assertContains('categories', $publicModels);
        $this->assertNotContains('posts', $publicModels);
        $this->assertNotContains('comments', $publicModels);
    }

    public function test_three_groups_urls_use_correct_prefix_per_group(): void
    {
        $json = $this->runExportWithConfig(
            ['posts' => ExportPostModel::class, 'categories' => ExportPostModelWithExcept::class, 'comments' => ExportPostModelWithSoftDeletes::class],
            [
                'tenant' => ['prefix' => '{organization}', 'middleware' => ['resolve-org'], 'models' => '*'],
                'admin' => ['prefix' => 'admin', 'middleware' => ['admin-check'], 'models' => ['posts', 'comments']],
                'public' => ['prefix' => '', 'middleware' => [], 'models' => ['categories']],
            ]
        );

        // tenant: {organization} prefix
        $tenantFolder = collect($json['item'])->firstWhere('name', 'tenant');
        $postsFolder = collect($tenantFolder['item'])->firstWhere('name', 'posts');
        $rawUrl = collect($postsFolder['item'])->firstWhere('name', 'Index')['item'][0]['request']['url']['raw'];
        $this->assertStringContainsString('{{baseUrl}}/{{organization}}/posts', $rawUrl);

        // admin: admin prefix
        $adminFolder = collect($json['item'])->firstWhere('name', 'admin');
        $adminPostsFolder = collect($adminFolder['item'])->firstWhere('name', 'posts');
        $adminRawUrl = collect($adminPostsFolder['item'])->firstWhere('name', 'Index')['item'][0]['request']['url']['raw'];
        $this->assertStringContainsString('{{baseUrl}}/admin/posts', $adminRawUrl);

        // public: no prefix
        $publicFolder = collect($json['item'])->firstWhere('name', 'public');
        $catFolder = collect($publicFolder['item'])->firstWhere('name', 'categories');
        $publicRawUrl = collect($catFolder['item'])->firstWhere('name', 'Index')['item'][0]['request']['url']['raw'];
        $this->assertStringContainsString('{{baseUrl}}/categories', $publicRawUrl);
    }

    public function test_three_groups_soft_deletes_respected_in_group_context(): void
    {
        $json = $this->runExportWithConfig(
            ['posts' => ExportPostModel::class, 'categories' => ExportPostModelWithExcept::class, 'comments' => ExportPostModelWithSoftDeletes::class],
            [
                'tenant' => ['prefix' => '{organization}', 'middleware' => ['resolve-org'], 'models' => '*'],
                'admin' => ['prefix' => 'admin', 'middleware' => ['admin-check'], 'models' => ['posts', 'comments']],
                'public' => ['prefix' => '', 'middleware' => [], 'models' => ['categories']],
            ]
        );

        $adminFolder = collect($json['item'])->firstWhere('name', 'admin');
        $commentsFolder = collect($adminFolder['item'])->firstWhere('name', 'comments');
        $actionNames = array_column($commentsFolder['item'], 'name');
        $this->assertContains('Trashed', $actionNames);
        $this->assertContains('Restore', $actionNames);
        $this->assertContains('Force Delete', $actionNames);
    }

    // ─── Edge case: group with no matching models ───

    public function test_group_with_no_matching_models_is_excluded(): void
    {
        $json = $this->runExportWithConfig(
            ['posts' => ExportPostModel::class],
            [
                'tenant' => ['prefix' => '{organization}', 'middleware' => ['resolve-org'], 'models' => '*'],
                'driver' => ['prefix' => 'driver', 'middleware' => [], 'models' => ['nonexistent_model']],
            ]
        );

        $names = array_column($json['item'], 'name');
        $this->assertContains('tenant', $names);
        $this->assertNotContains('driver', $names);
    }

    // ─── Auth folder always first, even with multiple groups ───

    public function test_authentication_folder_is_first_with_multiple_groups(): void
    {
        $json = $this->runExportWithConfig(
            ['posts' => ExportPostModel::class, 'categories' => ExportPostModelWithExcept::class],
            [
                'tenant' => ['prefix' => '{organization}', 'middleware' => ['resolve-org'], 'models' => '*'],
                'public' => ['prefix' => 'public', 'middleware' => [], 'models' => ['categories']],
            ]
        );

        $this->assertSame('Authentication', $json['item'][0]['name']);
    }
}
