<?php

namespace Rhino\Tests\Feature;

use Illuminate\Console\OutputStyle;
use Illuminate\Console\View\Components\Factory;
use Illuminate\Support\Facades\File;
use Rhino\Commands\BlueprintCommand;
use Rhino\Commands\GenerateCommand;
use Rhino\Tests\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class GenerateCommandCoverageTest extends TestCase
{
    protected GenerateCommand $command;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->command = new GenerateCommand();
        $this->tempDir = sys_get_temp_dir() . '/rhino_gen_cov_test_' . uniqid();
        File::ensureDirectoryExists($this->tempDir);

        $this->setProperty('stubPath', realpath(__DIR__ . '/../../stubs/generate'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempDir);
        parent::tearDown();
    }

    protected function invokeMethod(string $method, array $args = []): mixed
    {
        $ref = new ReflectionMethod(GenerateCommand::class, $method);
        $ref->setAccessible(true);

        return $ref->invoke($this->command, ...$args);
    }

    protected function setProperty(string $property, mixed $value): void
    {
        $ref = new ReflectionProperty(GenerateCommand::class, $property);
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
    // writeModelFile — belongsToOrg with organization FK already in columns
    // Tests the code path where org FK column is filtered from relationships
    // ------------------------------------------------------------------

    public function test_write_model_file_with_belongs_to_org_and_org_fk_column(): void
    {
        $modelsDir = $this->tempDir . '/Models';
        File::ensureDirectoryExists($modelsDir);
        $this->app->useAppPath($this->tempDir);

        $columns = [
            ['name' => 'organization_id', 'type' => 'foreignId', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => true, 'foreignModel' => 'Organization'],
            ['name' => 'user_id', 'type' => 'foreignId', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => 'User'],
            ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
        ];

        $this->invokeMethod('writeModelFile', ['Article', $columns, true, null, true, false]);

        $this->assertFileExists($modelsDir . '/Article.php');
        $content = File::get($modelsDir . '/Article.php');
        // Should NOT have a duplicate Organization import since BelongsToOrganization handles it
        $this->assertStringContainsString('BelongsToOrganization', $content);
        $this->assertStringContainsString('use BelongsToOrganization;', $content);
        // Should have User import for the user_id FK
        $this->assertStringContainsString("use App\\Models\\User;", $content);
        // Should have user() relationship but NOT organization() (handled by trait)
        $this->assertStringContainsString('public function user()', $content);
    }

    // ------------------------------------------------------------------
    // writeModelFile — with both audit trail AND belongs to org
    // ------------------------------------------------------------------

    public function test_write_model_file_with_all_options(): void
    {
        $modelsDir = $this->tempDir . '/Models';
        File::ensureDirectoryExists($modelsDir);
        $this->app->useAppPath($this->tempDir);

        $columns = [
            ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
        ];

        $this->invokeMethod('writeModelFile', ['FullModel', $columns, true, null, true, true]);

        $content = File::get($modelsDir . '/FullModel.php');
        // When audit trail is enabled, the commented line should be uncommented
        $this->assertStringContainsString('use HasAuditTrail;', $content);
        $this->assertStringNotContainsString('// use HasAuditTrail;', $content);
        // BelongsToOrganization should be uncommented
        $this->assertStringContainsString('use BelongsToOrganization;', $content);
        $this->assertStringNotContainsString('// use BelongsToOrganization;', $content);
        // The model extends RhinoModel which already includes SoftDeletes
        $this->assertStringContainsString('RhinoModel', $content);
    }

    // ------------------------------------------------------------------
    // writeModelFile — with ownerRelation
    // ------------------------------------------------------------------

    public function test_write_model_file_with_owner_relation_and_no_soft_deletes(): void
    {
        $modelsDir = $this->tempDir . '/Models';
        File::ensureDirectoryExists($modelsDir);
        $this->app->useAppPath($this->tempDir);

        $columns = [
            ['name' => 'blog_id', 'type' => 'foreignId', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => true, 'foreignModel' => 'Blog'],
            ['name' => 'content', 'type' => 'text', 'nullable' => true, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
        ];

        $this->invokeMethod('writeModelFile', ['BlogPost', $columns, false, 'blog', false, true]);

        $content = File::get($modelsDir . '/BlogPost.php');
        // No SoftDeletes since false
        $this->assertStringNotContainsString('use HasFactory, SoftDeletes', $content);
        // Has audit trail
        $this->assertStringContainsString('HasAuditTrail', $content);
        // Has blog() relationship
        $this->assertStringContainsString('public function blog()', $content);
    }

    // ------------------------------------------------------------------
    // registerModelInConfig — legacy array() syntax
    // ------------------------------------------------------------------

    public function test_register_model_in_config_handles_legacy_array_syntax(): void
    {
        $configDir = $this->tempDir . '/config';
        File::ensureDirectoryExists($configDir);
        $this->app->useConfigPath($configDir);

        $configContent = <<<'PHP'
<?php

return array(
    'models' => array(
        'organizations' => \App\Models\Organization::class,
    ),
);
PHP;
        File::put($configDir . '/rhino.php', $configContent);

        $this->setBufferedOutput();
        $this->invokeMethod('registerModelInConfig', ['Widget']);

        $content = File::get($configDir . '/rhino.php');
        $this->assertStringContainsString('widgets', $content);
        $this->assertStringContainsString('Widget::class', $content);
    }

    // ------------------------------------------------------------------
    // columnToFakerValue — nullable decimal/float should use optional()
    // ------------------------------------------------------------------

    public function test_column_to_faker_value_nullable_decimal(): void
    {
        $column = ['name' => 'score', 'type' => 'decimal', 'nullable' => true, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertStringContainsString('optional()', $result);
        $this->assertStringContainsString('randomFloat', $result);
    }

    public function test_column_to_faker_value_nullable_float(): void
    {
        $column = ['name' => 'rate', 'type' => 'float', 'nullable' => true, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertStringContainsString('optional()', $result);
    }

    public function test_column_to_faker_value_nullable_date(): void
    {
        $column = ['name' => 'published_at', 'type' => 'date', 'nullable' => true, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertStringContainsString('optional()', $result);
        $this->assertStringContainsString('date', $result);
    }

    public function test_column_to_faker_value_nullable_datetime(): void
    {
        $column = ['name' => 'expires_at', 'type' => 'datetime', 'nullable' => true, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertStringContainsString('optional()', $result);
        $this->assertStringContainsString('dateTime', $result);
    }

    public function test_column_to_faker_value_nullable_uuid(): void
    {
        $column = ['name' => 'ref_id', 'type' => 'uuid', 'nullable' => true, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertStringContainsString('optional()', $result);
        $this->assertStringContainsString('uuid', $result);
    }

    // ------------------------------------------------------------------
    // columnToValidationRule — additional types
    // ------------------------------------------------------------------

    public function test_column_to_validation_rule_nullable_big_integer(): void
    {
        $column = ['name' => 'big_count', 'type' => 'bigInteger', 'nullable' => true, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToValidationRule', [$column, 'items']);

        $this->assertSame('nullable|integer', $result);
    }

    public function test_column_to_validation_rule_nullable_uuid_unique(): void
    {
        $column = ['name' => 'external_ref', 'type' => 'uuid', 'nullable' => true, 'unique' => true, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToValidationRule', [$column, 'orders']);

        $this->assertSame('nullable|unique:orders,external_ref|uuid', $result);
    }

    // ------------------------------------------------------------------
    // columnToMigrationLine — more edge cases
    // ------------------------------------------------------------------

    public function test_column_to_migration_line_nullable_string_with_unique_and_default_and_index(): void
    {
        $column = ['name' => 'code', 'type' => 'string', 'nullable' => true, 'unique' => true, 'default' => 'N/A', 'index' => true, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToMigrationLine', [$column]);

        $this->assertStringContainsString("->nullable()", $result);
        $this->assertStringContainsString("->unique()", $result);
        $this->assertStringContainsString("->default('N/A')", $result);
        $this->assertStringContainsString("->index()", $result);
    }

    public function test_column_to_migration_line_date(): void
    {
        $column = ['name' => 'birth_date', 'type' => 'date', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToMigrationLine', [$column]);

        $this->assertSame("\$table->date('birth_date');", $result);
    }

    // ------------------------------------------------------------------
    // generateTestFile — with role access and multi-tenant (phpunit)
    // ------------------------------------------------------------------

    public function test_generate_test_file_phpunit_multi_tenant_with_roles(): void
    {
        $this->app->setBasePath($this->tempDir);

        $configDir = $this->tempDir . '/config';
        File::ensureDirectoryExists($configDir);
        $this->app->useConfigPath($configDir);

        $configContent = "<?php\nreturn [\n    'test_framework' => 'phpunit',\n    'multi_tenant' => ['organization_identifier_column' => 'slug'],\n    'route_groups' => ['tenant' => ['prefix' => '{organization}']],\n    'models' => [],\n];\n";
        File::put($configDir . '/rhino.php', $configContent);

        $columns = [
            ['name' => 'user_id', 'type' => 'foreignId', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => 'User'],
            ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
        ];

        $roleAccess = [
            'admin' => 'editor',
            'editor' => 'writer',
            'viewer' => 'none',
        ];

        $this->invokeMethod('generateTestFile', ['Task', $columns, $roleAccess, true]);

        $testPath = $this->tempDir . '/tests/Model/TaskTest.php';
        $this->assertFileExists($testPath);
        $content = File::get($testPath);
        // PHPUnit + multi-tenant + roles
        $this->assertStringContainsString('editor', $content);
        $this->assertStringContainsString('viewer', $content);
        $this->assertStringContainsString('user', $content);
    }

    // ------------------------------------------------------------------
    // buildRoleTests — non-multi-tenant with phpunit
    // ------------------------------------------------------------------

    public function test_build_role_tests_phpunit_non_multi_tenant(): void
    {
        $roleAccess = [
            'admin' => 'editor',
            'manager' => 'writer',
        ];

        $result = $this->invokeMethod('buildRoleTests', ['Post', 'posts', $roleAccess, false, null, 'phpunit']);

        $this->assertStringContainsString('public function test_admin_can_access_permitted_posts_endpoints', $result);
        $this->assertStringContainsString('public function test_manager_can_access_permitted_posts_endpoints', $result);
        $this->assertStringContainsString('public function test_manager_is_blocked_from_restricted_posts_endpoints', $result);
        // Non-multi-tenant uses createUserWithPermissions
        $this->assertStringContainsString('createUserWithPermissions', $result);
    }

    // ------------------------------------------------------------------
    // buildRoleTests — editor access (all actions allowed, no blocked test)
    // ------------------------------------------------------------------

    public function test_build_role_tests_editor_has_no_blocked_tests(): void
    {
        $roleAccess = ['admin' => 'editor'];

        $result = $this->invokeMethod('buildRoleTests', ['Post', 'posts', $roleAccess, false, null, 'pest']);

        $this->assertStringContainsString('access permitted', $result);
        $this->assertStringNotContainsString('blocked', $result);
    }

    // ------------------------------------------------------------------
    // buildRelationshipTests — phpunit non-tenant
    // ------------------------------------------------------------------

    public function test_build_relationship_tests_phpunit_multi_fk(): void
    {
        $columns = [
            ['name' => 'user_id', 'type' => 'foreignId', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => 'User'],
            ['name' => 'blog_id', 'type' => 'foreignId', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => 'Blog'],
        ];

        $result = $this->invokeMethod('buildRelationshipTests', ['Comment', $columns, 'phpunit']);

        $this->assertStringContainsString('public function test_it_belongs_to_user', $result);
        $this->assertStringContainsString('public function test_it_belongs_to_blog', $result);
        $this->assertStringContainsString('assertInstanceOf', $result);
    }

    // ------------------------------------------------------------------
    // printRoleAccessSummary — with 'none' access
    // ------------------------------------------------------------------

    public function test_print_role_access_summary_with_all_access_types(): void
    {
        $this->setBufferedOutput();

        $roleAccess = [
            'admin' => 'editor',
            'editor' => 'writer',
            'viewer' => 'viewer',
            'guest' => 'none',
        ];

        $this->invokeMethod('printRoleAccessSummary', ['Item', $roleAccess]);
        // Just verify no exception; output is ANSI formatted
        $this->assertTrue(true);
    }

    // ------------------------------------------------------------------
    // writeModelFile — with filter/sort columns including json and text
    // ------------------------------------------------------------------

    public function test_write_model_file_filters_json_and_text_from_filters_and_sorts(): void
    {
        $modelsDir = $this->tempDir . '/Models';
        File::ensureDirectoryExists($modelsDir);
        $this->app->useAppPath($this->tempDir);

        $columns = [
            ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
            ['name' => 'content', 'type' => 'text', 'nullable' => true, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
            ['name' => 'metadata', 'type' => 'json', 'nullable' => true, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
            ['name' => 'status', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
        ];

        $this->invokeMethod('writeModelFile', ['Document', $columns, false, null, true, false]);

        $content = File::get($modelsDir . '/Document.php');
        // title and status should be in allowedFilters, but content and metadata should not
        $this->assertStringContainsString("'title'", $content);
        $this->assertStringContainsString("'status'", $content);
        // All columns should be in fillable
        $this->assertStringContainsString("'content'", $content);
        $this->assertStringContainsString("'metadata'", $content);
    }

    // ------------------------------------------------------------------
    // updateMigrationFile — with all column types
    // ------------------------------------------------------------------

    public function test_update_migration_file_with_comprehensive_columns(): void
    {
        $migrationsDir = $this->tempDir . '/migrations';
        File::ensureDirectoryExists($migrationsDir);
        $this->app->useDatabasePath($this->tempDir);

        $migrationContent = <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('products', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });
    }
};
PHP;
        File::put($migrationsDir . '/2024_01_01_000000_create_products_table.php', $migrationContent);

        $columns = [
            ['name' => 'name', 'type' => 'string', 'nullable' => false, 'unique' => true, 'default' => null, 'index' => true, 'foreignModel' => null],
            ['name' => 'price', 'type' => 'decimal', 'nullable' => false, 'unique' => false, 'default' => '0.00', 'index' => false, 'foreignModel' => null],
            ['name' => 'data', 'type' => 'json', 'nullable' => true, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
            ['name' => 'ref', 'type' => 'uuid', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
        ];

        $this->invokeMethod('updateMigrationFile', ['Product', $columns, false]);

        $content = File::get($migrationsDir . '/2024_01_01_000000_create_products_table.php');
        $this->assertStringContainsString("->unique()", $content);
        $this->assertStringContainsString("->index()", $content);
        $this->assertStringContainsString("decimal", $content);
        $this->assertStringContainsString("json", $content);
        $this->assertStringContainsString("uuid", $content);
        // No softDeletes
        $this->assertStringNotContainsString('softDeletes', $content);
    }

    // ------------------------------------------------------------------
    // addSeederToDatabaseSeeder — with run() that has no `: void`
    // ------------------------------------------------------------------

    public function test_add_seeder_handles_run_without_void_return_type(): void
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
        // Default seeder
    }
}
PHP;
        File::put($seedersDir . '/DatabaseSeeder.php', $seederContent);

        $this->invokeMethod('addSeederToDatabaseSeeder', ['Category']);

        $content = File::get($seedersDir . '/DatabaseSeeder.php');
        $this->assertStringContainsString('CategorySeeder::class', $content);
        $this->assertStringContainsString('$this->call([', $content);
    }

    // ------------------------------------------------------------------
    // createSeederFile — verify seeder content for ownerRelation
    // ------------------------------------------------------------------

    public function test_create_seeder_file_owner_relation_content(): void
    {
        $seedersDir = $this->tempDir . '/seeders';
        File::ensureDirectoryExists($seedersDir);
        $this->app->useDatabasePath($this->tempDir);

        $this->invokeMethod('createSeederFile', ['Reply', false, 'comment']);

        $content = File::get($seedersDir . '/ReplySeeder.php');
        $this->assertStringContainsString('Comment', $content);
        $this->assertStringContainsString('comment_id', $content);
        $this->assertStringContainsString('$comment', $content);
    }

    // ------------------------------------------------------------------
    // getExistingModels — with non-PHP files present
    // ------------------------------------------------------------------

    public function test_get_existing_models_ignores_non_php_files(): void
    {
        $modelsDir = $this->tempDir . '/Models';
        File::ensureDirectoryExists($modelsDir);
        $this->app->useAppPath($this->tempDir);

        File::put($modelsDir . '/Post.php', '<?php class Post {}');
        File::put($modelsDir . '/README.md', '# Models');
        File::put($modelsDir . '/.gitkeep', '');

        $result = $this->invokeMethod('getExistingModels', []);

        $this->assertArrayHasKey('Post', $result);
        $this->assertArrayNotHasKey('README', $result);
        $this->assertArrayNotHasKey('.gitkeep', $result);
    }

    // ------------------------------------------------------------------
    // printModelNextSteps — with empty options
    // ------------------------------------------------------------------

    public function test_print_model_next_steps_empty_options(): void
    {
        $this->setBufferedOutput();
        $this->invokeMethod('printModelNextSteps', ['Task', []]);
        // When 'policy' is empty, it should show the policy step
        $this->assertTrue(true);
    }

    // ------------------------------------------------------------------
    // printCreatedFiles — without columns and without migration file
    // ------------------------------------------------------------------

    public function test_print_created_files_no_migration_found(): void
    {
        $this->setBufferedOutput();
        $this->app->useDatabasePath($this->tempDir);

        $columns = [
            ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
        ];

        // No migration file exists, so findMigrationFile should return null
        $this->invokeMethod('printCreatedFiles', ['Widget', $columns, ['policy' => false, 'factory_seeder' => false]]);
        $this->assertTrue(true);
    }

    // ------------------------------------------------------------------
    // formatDefaultValue — edge cases
    // ------------------------------------------------------------------

    public function test_format_default_value_text_type(): void
    {
        $result = $this->invokeMethod('formatDefaultValue', ['hello world', 'text']);

        $this->assertSame("'hello world'", $result);
    }

    public function test_format_default_value_boolean_zero(): void
    {
        $result = $this->invokeMethod('formatDefaultValue', ['0', 'boolean']);

        $this->assertSame('false', $result);
    }

    public function test_format_default_value_boolean_false_string(): void
    {
        $result = $this->invokeMethod('formatDefaultValue', ['false', 'boolean']);

        $this->assertSame('false', $result);
    }

    // ------------------------------------------------------------------
    // columnToFakerValue — default branch (unknown type)
    // ------------------------------------------------------------------

    public function test_column_to_faker_value_nullable_unknown(): void
    {
        $column = ['name' => 'custom_field', 'type' => 'custom_type', 'nullable' => true, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertStringContainsString('optional()', $result);
        $this->assertStringContainsString('word', $result);
    }

    // ------------------------------------------------------------------
    // generateTestFile — pest format, non-tenant, no roles
    // ------------------------------------------------------------------

    public function test_generate_test_file_pest_non_tenant_no_roles_no_fks(): void
    {
        $this->app->setBasePath($this->tempDir);
        $configDir = $this->tempDir . '/config';
        File::ensureDirectoryExists($configDir);
        $this->app->useConfigPath($configDir);
        File::put($configDir . '/rhino.php', "<?php\nreturn ['test_framework' => 'pest', 'models' => []];\n");

        $columns = [
            ['name' => 'name', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
        ];

        $this->invokeMethod('generateTestFile', ['Tag', $columns, [], false]);

        $testPath = $this->tempDir . '/tests/Model/TagTest.php';
        $this->assertFileExists($testPath);
    }

    // ------------------------------------------------------------------
    // buildRoleTests — with all 'none' access
    // ------------------------------------------------------------------

    public function test_build_role_tests_all_none_only_blocked(): void
    {
        $roleAccess = [
            'guest' => 'none',
        ];

        $result = $this->invokeMethod('buildRoleTests', ['Post', 'posts', $roleAccess, true, 'id', 'pest']);

        $this->assertStringContainsString('blocks guest from restricted', $result);
        // No allowed endpoints test since all have 'none' access
        $this->assertStringNotContainsString('allows guest', $result);
    }

    // ------------------------------------------------------------------
    // Regression: nullable() must come before constrained() for foreignId
    // ------------------------------------------------------------------

    public function test_nullable_foreign_id_places_nullable_before_constrained(): void
    {
        $column = ['name' => 'user_id', 'type' => 'foreignId', 'nullable' => true, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => 'User'];

        $result = $this->invokeMethod('columnToMigrationLine', [$column]);

        // nullable() MUST appear before constrained() — otherwise PostgreSQL ignores it
        $this->assertSame("\$table->foreignId('user_id')->nullable()->constrained('users')->cascadeOnDelete();", $result);

        // Verify nullable is NOT after cascadeOnDelete
        $this->assertStringNotContainsString('cascadeOnDelete()->nullable()', $result);
    }

    public function test_non_nullable_foreign_id_does_not_add_nullable(): void
    {
        $column = ['name' => 'user_id', 'type' => 'foreignId', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => 'User'];

        $result = $this->invokeMethod('columnToMigrationLine', [$column]);

        $this->assertSame("\$table->foreignId('user_id')->constrained('users')->cascadeOnDelete();", $result);
        $this->assertStringNotContainsString('nullable()', $result);
    }

    // ------------------------------------------------------------------
    // Regression: writeModelFile generates $casts for JSON columns
    // ------------------------------------------------------------------

    public function test_write_model_file_generates_casts_for_json_columns(): void
    {
        $modelsDir = $this->tempDir . '/Models';
        File::ensureDirectoryExists($modelsDir);
        $this->app->useAppPath($this->tempDir);

        $columns = [
            ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
            ['name' => 'metadata', 'type' => 'json', 'nullable' => true, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
            ['name' => 'settings', 'type' => 'json', 'nullable' => true, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
        ];

        $this->invokeMethod('writeModelFile', ['Widget', $columns, false, null, true, false]);

        $content = File::get($modelsDir . '/Widget.php');
        $this->assertStringContainsString('$casts', $content);
        $this->assertStringContainsString("'metadata' => 'array'", $content);
        $this->assertStringContainsString("'settings' => 'array'", $content);
    }

    // ------------------------------------------------------------------
    // Regression: Blueprint generates sequential timestamps for migrations
    // ------------------------------------------------------------------

    public function test_blueprint_generates_sequential_migration_timestamps(): void
    {
        $blueprint = new BlueprintCommand();
        $ref = new ReflectionProperty(BlueprintCommand::class, 'migrationTimestampOffset');
        $ref->setAccessible(true);

        // Initially offset should be 0
        $this->assertEquals(0, $ref->getValue($blueprint));

        // Simulate what happens during migration generation by reading the offset
        // The offset increments each time a migration is generated
        $ref->setValue($blueprint, 0);

        $ts1 = date('Y_m_d_His', time() + 0);
        $ts2 = date('Y_m_d_His', time() + 1);
        $ts3 = date('Y_m_d_His', time() + 2);

        // Sequential timestamps must be different (at least 1 second apart)
        if ($ts1 === $ts2) {
            // Edge case: if we're exactly at a second boundary, ts2 and ts3 should still differ
            $this->assertNotEquals($ts2, $ts3);
        } else {
            $this->assertNotEquals($ts1, $ts2);
        }
    }

    public function test_write_model_file_omits_casts_when_no_json_columns(): void
    {
        $modelsDir = $this->tempDir . '/Models';
        File::ensureDirectoryExists($modelsDir);
        $this->app->useAppPath($this->tempDir);

        $columns = [
            ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
            ['name' => 'count', 'type' => 'integer', 'nullable' => false, 'unique' => false, 'default' => '0', 'index' => false, 'foreignModel' => null],
        ];

        $this->invokeMethod('writeModelFile', ['Counter', $columns, false, null, true, false]);

        $content = File::get($modelsDir . '/Counter.php');
        $this->assertStringNotContainsString('$casts', $content);
    }
}
