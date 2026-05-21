<?php

namespace Rhino\Tests\Feature;

use Illuminate\Console\OutputStyle;
use Illuminate\Support\Facades\File;
use Rhino\Commands\GenerateCommand;
use Rhino\Tests\TestCase;
use ReflectionMethod;
use ReflectionProperty;
use Symfony\Component\Console\Input\ArrayInput;
use Symfony\Component\Console\Output\BufferedOutput;

class GenerateCommandExtendedTest extends TestCase
{
    protected GenerateCommand $command;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->command = new GenerateCommand();
        $this->tempDir = sys_get_temp_dir() . '/rhino_generate_ext_test_' . uniqid();
        File::ensureDirectoryExists($this->tempDir);

        // Set stubPath to the real stubs directory
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

    protected function getProperty(string $property): mixed
    {
        $ref = new ReflectionProperty(GenerateCommand::class, $property);
        $ref->setAccessible(true);

        return $ref->getValue($this->command);
    }

    /**
     * Set up a buffered output on the command and return the underlying BufferedOutput
     * so we can fetch output after the method runs.
     */
    protected function setBufferedOutput(): BufferedOutput
    {
        $buffered = new BufferedOutput();
        $outputStyle = new OutputStyle(new ArrayInput([]), $buffered);
        $this->command->setOutput($outputStyle);

        // Also set up the components factory so $this->components works
        $refComponents = new ReflectionProperty(\Illuminate\Console\Command::class, 'components');
        $refComponents->setAccessible(true);
        $refComponents->setValue($this->command, new \Illuminate\Console\View\Components\Factory($outputStyle));

        return $buffered;
    }

    // ------------------------------------------------------------------
    // writeModelFile
    // ------------------------------------------------------------------

    public function test_write_model_file_creates_basic_model(): void
    {
        $modelsDir = $this->tempDir . '/Models';
        File::ensureDirectoryExists($modelsDir);
        $this->app->useAppPath($this->tempDir);

        $columns = [
            ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
            ['name' => 'content', 'type' => 'text', 'nullable' => true, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
        ];

        $this->invokeMethod('writeModelFile', ['Post', $columns, false, null, true, false]);

        $this->assertFileExists($modelsDir . '/Post.php');
        $content = File::get($modelsDir . '/Post.php');
        $this->assertStringContainsString("'title'", $content);
        $this->assertStringContainsString("'content'", $content);
        $this->assertStringContainsString('RhinoModel', $content);
        $this->assertStringContainsString('class Post extends', $content);
    }

    public function test_write_model_file_with_belongs_to_org(): void
    {
        $modelsDir = $this->tempDir . '/Models';
        File::ensureDirectoryExists($modelsDir);
        $this->app->useAppPath($this->tempDir);

        $columns = [
            ['name' => 'organization_id', 'type' => 'foreignId', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => true, 'foreignModel' => 'Organization'],
            ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
        ];

        $this->invokeMethod('writeModelFile', ['Post', $columns, true, null, true, false]);

        $content = File::get($modelsDir . '/Post.php');
        $this->assertStringContainsString('BelongsToOrganization', $content);
    }

    public function test_write_model_file_without_soft_deletes(): void
    {
        $modelsDir = $this->tempDir . '/Models';
        File::ensureDirectoryExists($modelsDir);
        $this->app->useAppPath($this->tempDir);

        $columns = [
            ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
        ];

        $this->invokeMethod('writeModelFile', ['Post', $columns, false, null, false, false]);

        $content = File::get($modelsDir . '/Post.php');
        $this->assertStringNotContainsString('use HasFactory, SoftDeletes, HasValidation', $content);
    }

    public function test_write_model_file_with_audit_trail(): void
    {
        $modelsDir = $this->tempDir . '/Models';
        File::ensureDirectoryExists($modelsDir);
        $this->app->useAppPath($this->tempDir);

        $columns = [
            ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
        ];

        $this->invokeMethod('writeModelFile', ['Post', $columns, false, null, true, true]);

        $content = File::get($modelsDir . '/Post.php');
        $this->assertStringContainsString('use Rhino\\Traits\\HasAuditTrail;', $content);
    }

    public function test_write_model_file_with_foreign_key_relationships(): void
    {
        $modelsDir = $this->tempDir . '/Models';
        File::ensureDirectoryExists($modelsDir);
        $this->app->useAppPath($this->tempDir);

        $columns = [
            ['name' => 'user_id', 'type' => 'foreignId', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => 'User'],
            ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
        ];

        $this->invokeMethod('writeModelFile', ['Post', $columns, false, null, true, false]);

        $content = File::get($modelsDir . '/Post.php');
        $this->assertStringContainsString("use App\\Models\\User;", $content);
        $this->assertStringContainsString('Relationships', $content);
        $this->assertStringContainsString('function user()', $content);
    }

    // ------------------------------------------------------------------
    // updateMigrationFile
    // ------------------------------------------------------------------

    public function test_update_migration_file_adds_columns(): void
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
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });
    }
};
PHP;
        $timestamp = now()->format('Y_m_d_His');
        File::put($migrationsDir . "/{$timestamp}_create_posts_table.php", $migrationContent);

        $columns = [
            ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
            ['name' => 'content', 'type' => 'text', 'nullable' => true, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
        ];

        $this->invokeMethod('updateMigrationFile', ['Post', $columns, true]);

        $updatedContent = File::get($migrationsDir . "/{$timestamp}_create_posts_table.php");
        $this->assertStringContainsString("\$table->string('title');", $updatedContent);
        $this->assertStringContainsString("\$table->text('content')->nullable();", $updatedContent);
        $this->assertStringContainsString('$table->softDeletes();', $updatedContent);
    }

    public function test_update_migration_file_without_soft_deletes(): void
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
        Schema::create('posts', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });
    }
};
PHP;
        $timestamp = now()->format('Y_m_d_His');
        File::put($migrationsDir . "/{$timestamp}_create_posts_table.php", $migrationContent);

        $columns = [
            ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
        ];

        $this->invokeMethod('updateMigrationFile', ['Post', $columns, false]);

        $updatedContent = File::get($migrationsDir . "/{$timestamp}_create_posts_table.php");
        $this->assertStringNotContainsString('$table->softDeletes();', $updatedContent);
    }

    public function test_update_migration_file_does_nothing_when_migration_missing(): void
    {
        $migrationsDir = $this->tempDir . '/migrations';
        File::ensureDirectoryExists($migrationsDir);
        $this->app->useDatabasePath($this->tempDir);

        // No exception should be thrown when migration doesn't exist
        $this->invokeMethod('updateMigrationFile', ['NonExistent', [], true]);
        $this->assertTrue(true); // No exception means success
    }

    // ------------------------------------------------------------------
    // updateFactoryFile
    // ------------------------------------------------------------------

    public function test_update_factory_file_adds_faker_definitions(): void
    {
        $factoriesDir = $this->tempDir . '/factories';
        File::ensureDirectoryExists($factoriesDir);
        $this->app->useDatabasePath($this->tempDir);

        $factoryContent = <<<'PHP'
<?php

namespace Database\Factories;

use App\Models\Post;
use Illuminate\Database\Eloquent\Factories\Factory;

class PostFactory extends Factory
{
    protected $model = Post::class;

    public function definition(): array
    {
        return [
        ];
    }
}
PHP;
        File::put($factoriesDir . '/PostFactory.php', $factoryContent);

        $columns = [
            ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
            ['name' => 'is_active', 'type' => 'boolean', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
        ];

        $this->invokeMethod('updateFactoryFile', ['Post', $columns]);

        $updatedContent = File::get($factoriesDir . '/PostFactory.php');
        $this->assertStringContainsString("'title'", $updatedContent);
        $this->assertStringContainsString("'is_active'", $updatedContent);
    }

    public function test_update_factory_file_does_nothing_when_factory_missing(): void
    {
        $factoriesDir = $this->tempDir . '/factories';
        File::ensureDirectoryExists($factoriesDir);
        $this->app->useDatabasePath($this->tempDir);

        // No exception should be thrown when factory doesn't exist
        $this->invokeMethod('updateFactoryFile', ['NonExistent', []]);
        $this->assertTrue(true);
    }

    // ------------------------------------------------------------------
    // createPolicyFile
    // ------------------------------------------------------------------

    public function test_create_policy_file_creates_policy(): void
    {
        $policiesDir = $this->tempDir . '/Policies';
        File::ensureDirectoryExists($policiesDir);
        $this->app->useAppPath($this->tempDir);

        $this->invokeMethod('createPolicyFile', ['Post']);

        $this->assertFileExists($policiesDir . '/PostPolicy.php');
        $content = File::get($policiesDir . '/PostPolicy.php');
        $this->assertStringContainsString('PostPolicy', $content);
        $this->assertStringContainsString('ResourcePolicy', $content);
    }

    // ------------------------------------------------------------------
    // createScopeFile
    // ------------------------------------------------------------------

    public function test_create_scope_file_creates_scope(): void
    {
        $scopesDir = $this->tempDir . '/Models/Scopes';
        File::ensureDirectoryExists($scopesDir);
        $this->app->useAppPath($this->tempDir);

        $this->invokeMethod('createScopeFile', ['Post']);

        $this->assertFileExists($scopesDir . '/PostScope.php');
        $content = File::get($scopesDir . '/PostScope.php');
        $this->assertStringContainsString('PostScope', $content);
    }

    // ------------------------------------------------------------------
    // createSeederFile
    // ------------------------------------------------------------------

    public function test_create_seeder_file_basic(): void
    {
        $seedersDir = $this->tempDir . '/seeders';
        File::ensureDirectoryExists($seedersDir);
        $this->app->useDatabasePath($this->tempDir);

        $this->invokeMethod('createSeederFile', ['Post', false, null]);

        $this->assertFileExists($seedersDir . '/PostSeeder.php');
        $content = File::get($seedersDir . '/PostSeeder.php');
        $this->assertStringContainsString('PostSeeder', $content);
    }

    public function test_create_seeder_file_with_belongs_to_org(): void
    {
        $seedersDir = $this->tempDir . '/seeders';
        File::ensureDirectoryExists($seedersDir);
        $this->app->useDatabasePath($this->tempDir);

        $this->invokeMethod('createSeederFile', ['Post', true, null]);

        $content = File::get($seedersDir . '/PostSeeder.php');
        $this->assertStringContainsString('Organization', $content);
        $this->assertStringContainsString('organization_id', $content);
    }

    public function test_create_seeder_file_with_owner_relation(): void
    {
        $seedersDir = $this->tempDir . '/seeders';
        File::ensureDirectoryExists($seedersDir);
        $this->app->useDatabasePath($this->tempDir);

        $this->invokeMethod('createSeederFile', ['Comment', false, 'post']);

        $content = File::get($seedersDir . '/CommentSeeder.php');
        $this->assertStringContainsString('Post', $content);
        $this->assertStringContainsString('post_id', $content);
    }

    // ------------------------------------------------------------------
    // addSeederToDatabaseSeeder
    // ------------------------------------------------------------------

    public function test_add_seeder_to_database_seeder_creates_call_block(): void
    {
        $seedersDir = $this->tempDir . '/seeders';
        File::ensureDirectoryExists($seedersDir);
        $this->app->useDatabasePath($this->tempDir);

        $databaseSeederContent = <<<'PHP'
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
        File::put($seedersDir . '/DatabaseSeeder.php', $databaseSeederContent);

        $this->invokeMethod('addSeederToDatabaseSeeder', ['Post']);

        $content = File::get($seedersDir . '/DatabaseSeeder.php');
        $this->assertStringContainsString('PostSeeder::class', $content);
        $this->assertStringContainsString('$this->call', $content);
    }

    public function test_add_seeder_to_database_seeder_appends_to_existing_call(): void
    {
        $seedersDir = $this->tempDir . '/seeders';
        File::ensureDirectoryExists($seedersDir);
        $this->app->useDatabasePath($this->tempDir);

        $databaseSeederContent = <<<'PHP'
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
        File::put($seedersDir . '/DatabaseSeeder.php', $databaseSeederContent);

        $this->invokeMethod('addSeederToDatabaseSeeder', ['Post']);

        $content = File::get($seedersDir . '/DatabaseSeeder.php');
        $this->assertStringContainsString('PostSeeder::class', $content);
        $this->assertStringContainsString('RoleSeeder::class', $content);
    }

    public function test_add_seeder_to_database_seeder_skips_if_already_registered(): void
    {
        $seedersDir = $this->tempDir . '/seeders';
        File::ensureDirectoryExists($seedersDir);
        $this->app->useDatabasePath($this->tempDir);

        $databaseSeederContent = <<<'PHP'
<?php

namespace Database\Seeders;

use Illuminate\Database\Seeder;

class DatabaseSeeder extends Seeder
{
    public function run(): void
    {
        $this->call([
            PostSeeder::class,
        ]);
    }
}
PHP;
        File::put($seedersDir . '/DatabaseSeeder.php', $databaseSeederContent);

        $this->invokeMethod('addSeederToDatabaseSeeder', ['Post']);

        $content = File::get($seedersDir . '/DatabaseSeeder.php');
        // Should only appear once
        $count = substr_count($content, 'PostSeeder::class');
        $this->assertSame(1, $count);
    }

    public function test_add_seeder_to_database_seeder_does_nothing_when_file_missing(): void
    {
        $this->app->useDatabasePath($this->tempDir);

        // No exception should be thrown
        $this->invokeMethod('addSeederToDatabaseSeeder', ['Post']);
        $this->assertTrue(true);
    }

    // ------------------------------------------------------------------
    // registerModelInConfig
    // ------------------------------------------------------------------

    public function test_register_model_in_config(): void
    {
        $configDir = $this->tempDir . '/config';
        File::ensureDirectoryExists($configDir);

        $configContent = <<<'PHP'
<?php

return [
    'models' => [
    ],
    'route_groups' => [
        'default' => [
            'prefix' => '',
            'middleware' => [],
            'models' => '*',
        ],
    ],
];
PHP;
        File::put($configDir . '/rhino.php', $configContent);

        // Override config_path
        $this->app->useConfigPath($configDir);

        $this->invokeMethod('registerModelInConfig', ['Post']);

        $content = File::get($configDir . '/rhino.php');
        $this->assertStringContainsString("'posts'", $content);
        $this->assertStringContainsString('\\App\\Models\\Post::class', $content);
    }

    public function test_register_model_in_config_skips_if_already_registered(): void
    {
        $configDir = $this->tempDir . '/config';
        File::ensureDirectoryExists($configDir);

        $configContent = <<<'PHP'
<?php

return [
    'models' => [
        'posts' => \App\Models\Post::class,
    ],
];
PHP;
        File::put($configDir . '/rhino.php', $configContent);
        $this->app->useConfigPath($configDir);

        $this->invokeMethod('registerModelInConfig', ['Post']);

        $content = File::get($configDir . '/rhino.php');
        $count = substr_count($content, "'posts'");
        $this->assertSame(1, $count);
    }

    public function test_register_model_in_config_does_nothing_when_file_missing(): void
    {
        $this->app->useConfigPath($this->tempDir . '/nonexistent');

        $this->invokeMethod('registerModelInConfig', ['Post']);
        $this->assertTrue(true);
    }

    // ------------------------------------------------------------------
    // findMigrationFile
    // ------------------------------------------------------------------

    public function test_find_migration_file_returns_matching_file(): void
    {
        $migrationsDir = $this->tempDir . '/migrations';
        File::ensureDirectoryExists($migrationsDir);
        $this->app->useDatabasePath($this->tempDir);

        File::put($migrationsDir . '/2024_01_01_000000_create_posts_table.php', '<?php // migration');

        $result = $this->invokeMethod('findMigrationFile', ['posts']);

        $this->assertNotNull($result);
        $this->assertStringContainsString('create_posts_table', $result);
    }

    public function test_find_migration_file_returns_null_when_not_found(): void
    {
        $migrationsDir = $this->tempDir . '/migrations';
        File::ensureDirectoryExists($migrationsDir);
        $this->app->useDatabasePath($this->tempDir);

        $result = $this->invokeMethod('findMigrationFile', ['nonexistent']);

        $this->assertNull($result);
    }

    // ------------------------------------------------------------------
    // isMultiTenantEnabled
    // ------------------------------------------------------------------

    public function test_is_multi_tenant_enabled_returns_false_when_no_config(): void
    {
        $this->app->useConfigPath($this->tempDir . '/nonexistent');

        $result = $this->invokeMethod('isMultiTenantEnabled', []);

        $this->assertFalse($result);
    }

    public function test_is_multi_tenant_enabled_returns_true_when_tenant_group_exists(): void
    {
        $configDir = $this->tempDir . '/config';
        File::ensureDirectoryExists($configDir);

        $configContent = <<<'PHP'
<?php

return [
    'models' => [],
    'route_groups' => [
        'tenant' => [
            'prefix' => '{organization}',
            'middleware' => [],
            'models' => '*',
        ],
    ],
];
PHP;
        File::put($configDir . '/rhino.php', $configContent);
        $this->app->useConfigPath($configDir);

        $result = $this->invokeMethod('isMultiTenantEnabled', []);

        $this->assertTrue($result);
    }

    public function test_is_multi_tenant_enabled_returns_false_when_no_tenant_group(): void
    {
        $configDir = $this->tempDir . '/config';
        File::ensureDirectoryExists($configDir);

        $configContent = <<<'PHP'
<?php

return [
    'models' => [],
    'route_groups' => [
        'default' => [
            'prefix' => '',
            'middleware' => [],
            'models' => '*',
        ],
    ],
];
PHP;
        File::put($configDir . '/rhino.php', $configContent);
        $this->app->useConfigPath($configDir);

        $result = $this->invokeMethod('isMultiTenantEnabled', []);

        $this->assertFalse($result);
    }

    // ------------------------------------------------------------------
    // getOrganizationIdentifierColumn
    // ------------------------------------------------------------------

    public function test_get_organization_identifier_column_defaults_to_id(): void
    {
        $this->app->useConfigPath($this->tempDir . '/nonexistent');

        $result = $this->invokeMethod('getOrganizationIdentifierColumn', []);

        $this->assertSame('id', $result);
    }

    public function test_get_organization_identifier_column_from_config(): void
    {
        $configDir = $this->tempDir . '/config';
        File::ensureDirectoryExists($configDir);

        $configContent = <<<'PHP'
<?php

return [
    'models' => [],
    'multi_tenant' => [
        'organization_identifier_column' => 'slug',
    ],
];
PHP;
        File::put($configDir . '/rhino.php', $configContent);
        $this->app->useConfigPath($configDir);

        $result = $this->invokeMethod('getOrganizationIdentifierColumn', []);

        $this->assertSame('slug', $result);
    }

    // ------------------------------------------------------------------
    // getExistingModels
    // ------------------------------------------------------------------

    public function test_get_existing_models_returns_empty_when_no_directory(): void
    {
        $this->app->useAppPath($this->tempDir . '/nonexistent');

        $result = $this->invokeMethod('getExistingModels', []);

        $this->assertSame([], $result);
    }

    public function test_get_existing_models_returns_model_list(): void
    {
        $modelsDir = $this->tempDir . '/Models';
        File::ensureDirectoryExists($modelsDir);
        File::put($modelsDir . '/Post.php', '<?php class Post {}');
        File::put($modelsDir . '/Comment.php', '<?php class Comment {}');
        File::put($modelsDir . '/NotPhp.txt', 'not a model');
        $this->app->useAppPath($this->tempDir);

        $result = $this->invokeMethod('getExistingModels', []);

        $this->assertArrayHasKey('Post', $result);
        $this->assertArrayHasKey('Comment', $result);
        $this->assertStringContainsString('posts table', $result['Post']);
        $this->assertStringContainsString('comments table', $result['Comment']);
        $this->assertArrayNotHasKey('NotPhp', $result);
    }

    // ------------------------------------------------------------------
    // getTestFramework
    // ------------------------------------------------------------------

    public function test_get_test_framework_defaults_to_pest(): void
    {
        $this->app->useConfigPath($this->tempDir . '/nonexistent');

        $result = $this->invokeMethod('getTestFramework', []);

        $this->assertSame('pest', $result);
    }

    public function test_get_test_framework_from_config(): void
    {
        $configDir = $this->tempDir . '/config';
        File::ensureDirectoryExists($configDir);

        $configContent = <<<'PHP'
<?php

return [
    'models' => [],
    'test_framework' => 'phpunit',
];
PHP;
        File::put($configDir . '/rhino.php', $configContent);
        $this->app->useConfigPath($configDir);

        $result = $this->invokeMethod('getTestFramework', []);

        $this->assertSame('phpunit', $result);
    }

    // ------------------------------------------------------------------
    // getRolesFromRoleModel
    // ------------------------------------------------------------------

    public function test_get_roles_from_role_model_returns_empty_when_no_file(): void
    {
        $this->app->useAppPath($this->tempDir . '/nonexistent');

        $result = $this->invokeMethod('getRolesFromRoleModel', []);

        $this->assertSame([], $result);
    }

    public function test_get_roles_from_role_model_extracts_roles(): void
    {
        $modelsDir = $this->tempDir . '/Models';
        File::ensureDirectoryExists($modelsDir);

        $roleContent = <<<'PHP'
<?php

namespace App\Models;

class Role extends Model
{
    public static $roles = [
        'admin',
        'editor',
        'viewer',
    ];
}
PHP;
        File::put($modelsDir . '/Role.php', $roleContent);
        $this->app->useAppPath($this->tempDir);

        $result = $this->invokeMethod('getRolesFromRoleModel', []);

        $this->assertContains('admin', $result);
        $this->assertContains('editor', $result);
        $this->assertContains('viewer', $result);
    }

    // ------------------------------------------------------------------
    // generateTestFile
    // ------------------------------------------------------------------

    public function test_generate_test_file_creates_pest_test(): void
    {
        $testDir = $this->tempDir . '/tests/Model';
        File::ensureDirectoryExists($testDir);

        // Override base_path to use tempDir
        $this->app->setBasePath($this->tempDir);

        // Create config for pest
        $configDir = $this->tempDir . '/config';
        File::ensureDirectoryExists($configDir);
        $configContent = "<?php\n\nreturn [\n    'models' => [],\n    'test_framework' => 'pest',\n];\n";
        File::put($configDir . '/rhino.php', $configContent);

        $columns = [
            ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
        ];

        $this->invokeMethod('generateTestFile', ['Post', $columns, [], false]);

        $this->assertFileExists($testDir . '/PostTest.php');
    }

    public function test_generate_test_file_creates_phpunit_test(): void
    {
        $testDir = $this->tempDir . '/tests/Model';
        File::ensureDirectoryExists($testDir);

        $this->app->setBasePath($this->tempDir);

        $configDir = $this->tempDir . '/config';
        File::ensureDirectoryExists($configDir);
        $configContent = "<?php\n\nreturn [\n    'models' => [],\n    'test_framework' => 'phpunit',\n];\n";
        File::put($configDir . '/rhino.php', $configContent);

        $columns = [
            ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
        ];

        $this->invokeMethod('generateTestFile', ['Post', $columns, [], false]);

        $this->assertFileExists($testDir . '/PostTest.php');
    }

    public function test_generate_test_file_with_multi_tenant(): void
    {
        $testDir = $this->tempDir . '/tests/Model';
        File::ensureDirectoryExists($testDir);

        $this->app->setBasePath($this->tempDir);

        $configDir = $this->tempDir . '/config';
        File::ensureDirectoryExists($configDir);
        $configContent = "<?php\n\nreturn [\n    'models' => [],\n    'test_framework' => 'pest',\n    'multi_tenant' => ['organization_identifier_column' => 'slug'],\n    'route_groups' => ['tenant' => ['prefix' => '{organization}']],\n];\n";
        File::put($configDir . '/rhino.php', $configContent);

        $columns = [];
        $roleAccess = ['admin' => 'editor', 'viewer' => 'viewer'];

        $this->invokeMethod('generateTestFile', ['Post', $columns, $roleAccess, true]);

        $this->assertFileExists($testDir . '/PostTest.php');
    }

    // ------------------------------------------------------------------
    // buildRoleTests
    // ------------------------------------------------------------------

    public function test_build_role_tests_returns_empty_for_empty_role_access(): void
    {
        $result = $this->invokeMethod('buildRoleTests', ['Post', 'posts', [], false, null, 'pest']);

        $this->assertSame('', $result);
    }

    public function test_build_role_tests_generates_pest_tests(): void
    {
        $roleAccess = [
            'editor' => 'editor',
            'viewer' => 'viewer',
        ];

        $result = $this->invokeMethod('buildRoleTests', ['Post', 'posts', $roleAccess, true, 'slug', 'pest']);

        $this->assertStringContainsString("it('allows editor", $result);
        $this->assertStringContainsString("it('allows viewer", $result);
        $this->assertStringContainsString("it('blocks viewer", $result);
        $this->assertStringContainsString('assertStatus(200)', $result);
        $this->assertStringContainsString('assertStatus(403)', $result);
    }

    public function test_build_role_tests_generates_phpunit_tests(): void
    {
        $roleAccess = [
            'editor' => 'editor',
            'viewer' => 'viewer',
        ];

        $result = $this->invokeMethod('buildRoleTests', ['Post', 'posts', $roleAccess, true, 'id', 'phpunit']);

        $this->assertStringContainsString('public function test_editor_can_access', $result);
        $this->assertStringContainsString('public function test_viewer_can_access', $result);
        $this->assertStringContainsString('public function test_viewer_is_blocked', $result);
    }

    public function test_build_role_tests_with_none_access(): void
    {
        $roleAccess = [
            'guest' => 'none',
        ];

        $result = $this->invokeMethod('buildRoleTests', ['Post', 'posts', $roleAccess, false, null, 'pest']);

        $this->assertStringContainsString("it('blocks guest", $result);
        $this->assertStringContainsString('assertStatus(403)', $result);
    }

    public function test_build_role_tests_with_writer_access(): void
    {
        $roleAccess = [
            'writer' => 'writer',
        ];

        $result = $this->invokeMethod('buildRoleTests', ['Post', 'posts', $roleAccess, false, null, 'pest']);

        $this->assertStringContainsString("it('allows writer", $result);
        $this->assertStringContainsString("it('blocks writer", $result);
        // Writer can't destroy
        $this->assertStringContainsString('deleteJson', $result);
    }

    public function test_build_role_tests_non_multi_tenant(): void
    {
        $roleAccess = ['viewer' => 'viewer'];

        $result = $this->invokeMethod('buildRoleTests', ['Post', 'posts', $roleAccess, false, null, 'pest']);

        $this->assertStringContainsString("'/api/posts'", $result);
        $this->assertStringNotContainsString('$org->', $result);
    }

    // ------------------------------------------------------------------
    // buildRelationshipTests
    // ------------------------------------------------------------------

    public function test_build_relationship_tests_returns_empty_for_no_fk_columns(): void
    {
        $columns = [
            ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
        ];

        $result = $this->invokeMethod('buildRelationshipTests', ['Post', $columns, 'pest']);

        $this->assertSame('', $result);
    }

    public function test_build_relationship_tests_generates_pest_tests(): void
    {
        $columns = [
            ['name' => 'user_id', 'type' => 'foreignId', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => 'User'],
        ];

        $result = $this->invokeMethod('buildRelationshipTests', ['Post', $columns, 'pest']);

        $this->assertStringContainsString("it('belongs to user'", $result);
        $this->assertStringContainsString('toBeInstanceOf', $result);
    }

    public function test_build_relationship_tests_generates_phpunit_tests(): void
    {
        $columns = [
            ['name' => 'category_id', 'type' => 'foreignId', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => 'Category'],
        ];

        $result = $this->invokeMethod('buildRelationshipTests', ['Post', $columns, 'phpunit']);

        $this->assertStringContainsString('public function test_it_belongs_to_category', $result);
        $this->assertStringContainsString('assertInstanceOf', $result);
    }

    // ------------------------------------------------------------------
    // columnToValidationRule (additional types not covered)
    // ------------------------------------------------------------------

    public function test_column_to_validation_rule_big_integer(): void
    {
        $column = ['name' => 'count', 'type' => 'bigInteger', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToValidationRule', [$column, 'items']);

        $this->assertSame('required|integer', $result);
    }

    public function test_column_to_validation_rule_datetime(): void
    {
        $column = ['name' => 'created', 'type' => 'datetime', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToValidationRule', [$column, 'items']);

        $this->assertSame('required|date', $result);
    }

    public function test_column_to_validation_rule_timestamp(): void
    {
        $column = ['name' => 'occurred_at', 'type' => 'timestamp', 'nullable' => true, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToValidationRule', [$column, 'events']);

        $this->assertSame('nullable|date', $result);
    }

    public function test_column_to_validation_rule_float(): void
    {
        $column = ['name' => 'rate', 'type' => 'float', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToValidationRule', [$column, 'rates']);

        $this->assertSame('required|numeric', $result);
    }

    // ------------------------------------------------------------------
    // columnToFakerValue (additional types not covered)
    // ------------------------------------------------------------------

    public function test_column_to_faker_value_big_integer(): void
    {
        $column = ['name' => 'big_count', 'type' => 'bigInteger', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->numberBetween(1, 10000)', $result);
    }

    public function test_column_to_faker_value_datetime(): void
    {
        $column = ['name' => 'occurred_at', 'type' => 'datetime', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->dateTime()', $result);
    }

    public function test_column_to_faker_value_timestamp(): void
    {
        $column = ['name' => 'logged_at', 'type' => 'timestamp', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->dateTime()', $result);
    }

    public function test_column_to_faker_value_float(): void
    {
        $column = ['name' => 'rate', 'type' => 'float', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->randomFloat(2, 0, 1000)', $result);
    }

    public function test_column_to_faker_value_uuid(): void
    {
        $column = ['name' => 'external_id', 'type' => 'uuid', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->uuid()', $result);
    }

    public function test_column_to_faker_value_date(): void
    {
        $column = ['name' => 'birth_date', 'type' => 'date', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->date()', $result);
    }

    public function test_column_to_faker_value_text(): void
    {
        $column = ['name' => 'notes', 'type' => 'text', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->paragraph()', $result);
    }

    public function test_column_to_faker_value_nullable_text(): void
    {
        $column = ['name' => 'notes', 'type' => 'text', 'nullable' => true, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->optional()->paragraph()', $result);
    }

    public function test_column_to_faker_value_unknown_type_defaults_to_word(): void
    {
        $column = ['name' => 'misc', 'type' => 'unknown_type', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->word()', $result);
    }

    // ------------------------------------------------------------------
    // columnToMigrationLine (additional types)
    // ------------------------------------------------------------------

    public function test_column_to_migration_line_foreign_id_without_model(): void
    {
        $column = ['name' => 'parent_id', 'type' => 'foreignId', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToMigrationLine', [$column]);

        $this->assertSame("\$table->foreignId('parent_id')->constrained()->cascadeOnDelete();", $result);
    }

    public function test_column_to_migration_line_foreign_id_unique(): void
    {
        $column = ['name' => 'profile_id', 'type' => 'foreignId', 'nullable' => false, 'unique' => true, 'default' => null, 'index' => false, 'foreignModel' => 'Profile'];

        $result = $this->invokeMethod('columnToMigrationLine', [$column]);

        $this->assertStringContainsString("->unique()", $result);
    }

    public function test_column_to_migration_line_text(): void
    {
        $column = ['name' => 'content', 'type' => 'text', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToMigrationLine', [$column]);

        $this->assertSame("\$table->text('content');", $result);
    }

    public function test_column_to_migration_line_json(): void
    {
        $column = ['name' => 'metadata', 'type' => 'json', 'nullable' => true, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToMigrationLine', [$column]);

        $this->assertSame("\$table->json('metadata')->nullable();", $result);
    }

    public function test_column_to_migration_line_uuid(): void
    {
        $column = ['name' => 'external_id', 'type' => 'uuid', 'nullable' => false, 'unique' => true, 'default' => null, 'index' => true, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToMigrationLine', [$column]);

        $this->assertSame("\$table->uuid('external_id')->unique()->index();", $result);
    }

    // ------------------------------------------------------------------
    // formatDefaultValue (additional types)
    // ------------------------------------------------------------------

    public function test_format_default_value_decimal(): void
    {
        $result = $this->invokeMethod('formatDefaultValue', ['0.00', 'decimal']);

        $this->assertSame('0.00', $result);
    }

    public function test_format_default_value_float(): void
    {
        $result = $this->invokeMethod('formatDefaultValue', ['1.5', 'float']);

        $this->assertSame('1.5', $result);
    }

    public function test_format_default_value_big_integer(): void
    {
        $result = $this->invokeMethod('formatDefaultValue', ['100', 'bigInteger']);

        $this->assertSame('100', $result);
    }

    // ------------------------------------------------------------------
    // printSelections (display method - just test it doesn't throw)
    // ------------------------------------------------------------------

    public function test_print_selections_model_type(): void
    {
        // printSelections writes to output; just ensure no exception
        $this->setBufferedOutput();

        $this->invokeMethod('printSelections', ['model', 'Post', 5]);
        $this->assertTrue(true);
    }

    public function test_print_selections_policy_type(): void
    {
        $this->setBufferedOutput();

        $this->invokeMethod('printSelections', ['policy', 'Post', null]);
        $this->assertTrue(true);
    }

    public function test_print_selections_scope_type(): void
    {
        $this->setBufferedOutput();

        $this->invokeMethod('printSelections', ['scope', 'Post', null]);
        $this->assertTrue(true);
    }

    public function test_print_selections_unknown_type(): void
    {
        $this->setBufferedOutput();

        $this->invokeMethod('printSelections', ['custom', 'Widget', 3]);
        $this->assertTrue(true);
    }

    // ------------------------------------------------------------------
    // printColumnsSummary
    // ------------------------------------------------------------------

    public function test_print_columns_summary_empty_columns(): void
    {
        $this->setBufferedOutput();

        $this->invokeMethod('printColumnsSummary', [[]]);
        $this->assertTrue(true);
    }

    public function test_print_columns_summary_with_columns(): void
    {
        $output = $this->setBufferedOutput();

        $columns = [
            ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false],
            ['name' => 'price', 'type' => 'decimal', 'nullable' => true, 'unique' => false, 'default' => '0.00', 'index' => false],
        ];

        $this->invokeMethod('printColumnsSummary', [$columns]);

        $out = $output->fetch();
        $this->assertStringContainsString('title', $out);
        $this->assertStringContainsString('price', $out);
    }

    // ------------------------------------------------------------------
    // printCreatedFiles
    // ------------------------------------------------------------------

    public function test_print_created_files(): void
    {
        $output = $this->setBufferedOutput();

        $options = ['policy' => true, 'factory_seeder' => true];
        $columns = [
            ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
        ];

        $this->invokeMethod('printCreatedFiles', ['Post', $columns, $options]);

        $out = $output->fetch();
        $this->assertStringContainsString('Post.php', $out);
        $this->assertStringContainsString('PostPolicy.php', $out);
        $this->assertStringContainsString('PostSeeder.php', $out);
        $this->assertStringContainsString('PostScope.php', $out);
    }

    public function test_print_created_files_without_optional_files(): void
    {
        $output = $this->setBufferedOutput();

        $this->invokeMethod('printCreatedFiles', ['Post', [], []]);

        $out = $output->fetch();
        $this->assertStringContainsString('Post.php', $out);
        $this->assertStringNotContainsString('PostPolicy.php', $out);
        $this->assertStringNotContainsString('PostSeeder.php', $out);
    }

    // ------------------------------------------------------------------
    // printModelNextSteps
    // ------------------------------------------------------------------

    public function test_print_model_next_steps_with_policy(): void
    {
        $output = $this->setBufferedOutput();

        $this->invokeMethod('printModelNextSteps', ['Post', ['policy' => true]]);

        $out = $output->fetch();
        $this->assertStringContainsString('Run migrations', $out);
        $this->assertStringContainsString('Post.php', $out);
    }

    public function test_print_model_next_steps_without_policy(): void
    {
        $output = $this->setBufferedOutput();

        $this->invokeMethod('printModelNextSteps', ['Post', ['policy' => false]]);

        $out = $output->fetch();
        $this->assertStringContainsString('Create a policy', $out);
    }

    // ------------------------------------------------------------------
    // printStyledHeader
    // ------------------------------------------------------------------

    public function test_print_styled_header_does_not_throw(): void
    {
        $this->setBufferedOutput();

        $this->invokeMethod('printStyledHeader', []);
        $this->assertTrue(true);
    }

    // ------------------------------------------------------------------
    // printRoleAccessSummary
    // ------------------------------------------------------------------

    public function test_print_role_access_summary(): void
    {
        $output = $this->setBufferedOutput();

        $roleAccess = [
            'admin' => 'editor',
            'viewer' => 'viewer',
            'writer' => 'writer',
            'guest' => 'none',
        ];

        $this->invokeMethod('printRoleAccessSummary', ['Post', $roleAccess]);

        $out = $output->fetch();
        $this->assertStringContainsString('admin', $out);
        $this->assertStringContainsString('viewer', $out);
    }

    // ------------------------------------------------------------------
    // columnToValidationRule — additional type coverage
    // ------------------------------------------------------------------

    public function test_column_to_validation_rule_string(): void
    {
        $column = ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToValidationRule', [$column, 'posts']);

        $this->assertSame('required|string|max:255', $result);
    }

    public function test_column_to_validation_rule_text(): void
    {
        $column = ['name' => 'body', 'type' => 'text', 'nullable' => true, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToValidationRule', [$column, 'posts']);

        $this->assertSame('nullable|string', $result);
    }

    public function test_column_to_validation_rule_integer(): void
    {
        $column = ['name' => 'count', 'type' => 'integer', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToValidationRule', [$column, 'items']);

        $this->assertSame('required|integer', $result);
    }

    public function test_column_to_validation_rule_boolean(): void
    {
        $column = ['name' => 'is_active', 'type' => 'boolean', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToValidationRule', [$column, 'items']);

        $this->assertSame('required|boolean', $result);
    }

    public function test_column_to_validation_rule_date(): void
    {
        $column = ['name' => 'birth_date', 'type' => 'date', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToValidationRule', [$column, 'people']);

        $this->assertSame('required|date', $result);
    }

    public function test_column_to_validation_rule_decimal(): void
    {
        $column = ['name' => 'price', 'type' => 'decimal', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToValidationRule', [$column, 'products']);

        $this->assertSame('required|numeric', $result);
    }

    public function test_column_to_validation_rule_json(): void
    {
        $column = ['name' => 'metadata', 'type' => 'json', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToValidationRule', [$column, 'items']);

        $this->assertSame('required|array', $result);
    }

    public function test_column_to_validation_rule_uuid(): void
    {
        $column = ['name' => 'external_id', 'type' => 'uuid', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToValidationRule', [$column, 'items']);

        $this->assertSame('required|uuid', $result);
    }

    public function test_column_to_validation_rule_foreign_id_with_model(): void
    {
        $column = ['name' => 'user_id', 'type' => 'foreignId', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => 'User'];

        $result = $this->invokeMethod('columnToValidationRule', [$column, 'posts']);

        $this->assertSame('required|integer|exists:users,id', $result);
    }

    public function test_column_to_validation_rule_foreign_id_without_model(): void
    {
        $column = ['name' => 'parent_id', 'type' => 'foreignId', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToValidationRule', [$column, 'items']);

        $this->assertSame('required|integer', $result);
    }

    public function test_column_to_validation_rule_unique_column(): void
    {
        $column = ['name' => 'email', 'type' => 'string', 'nullable' => false, 'unique' => true, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToValidationRule', [$column, 'users']);

        $this->assertStringContainsString('unique:users,email', $result);
        $this->assertStringContainsString('required', $result);
    }

    // ------------------------------------------------------------------
    // columnToMigrationLine — additional coverage
    // ------------------------------------------------------------------

    public function test_column_to_migration_line_decimal(): void
    {
        $column = ['name' => 'price', 'type' => 'decimal', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToMigrationLine', [$column]);

        $this->assertSame("\$table->decimal('price', 8, 2);", $result);
    }

    public function test_column_to_migration_line_with_default_string(): void
    {
        $column = ['name' => 'status', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => 'active', 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToMigrationLine', [$column]);

        $this->assertStringContainsString("->default('active')", $result);
    }

    public function test_column_to_migration_line_with_default_integer(): void
    {
        $column = ['name' => 'count', 'type' => 'integer', 'nullable' => false, 'unique' => false, 'default' => '0', 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToMigrationLine', [$column]);

        $this->assertStringContainsString('->default(0)', $result);
    }

    public function test_column_to_migration_line_with_default_boolean_true(): void
    {
        $column = ['name' => 'is_active', 'type' => 'boolean', 'nullable' => false, 'unique' => false, 'default' => 'true', 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToMigrationLine', [$column]);

        $this->assertStringContainsString('->default(true)', $result);
    }

    public function test_column_to_migration_line_with_default_boolean_false(): void
    {
        $column = ['name' => 'is_active', 'type' => 'boolean', 'nullable' => false, 'unique' => false, 'default' => 'false', 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToMigrationLine', [$column]);

        $this->assertStringContainsString('->default(false)', $result);
    }

    public function test_column_to_migration_line_with_index(): void
    {
        $column = ['name' => 'status', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => true, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToMigrationLine', [$column]);

        $this->assertStringContainsString('->index()', $result);
    }

    public function test_column_to_migration_line_foreign_id_with_nullable_and_index(): void
    {
        $column = ['name' => 'user_id', 'type' => 'foreignId', 'nullable' => true, 'unique' => false, 'default' => null, 'index' => true, 'foreignModel' => 'User'];

        $result = $this->invokeMethod('columnToMigrationLine', [$column]);

        $this->assertStringContainsString("->constrained('users')", $result);
        $this->assertStringContainsString('->nullable()', $result);
        $this->assertStringContainsString('->index()', $result);
    }

    public function test_column_to_migration_line_boolean(): void
    {
        $column = ['name' => 'is_active', 'type' => 'boolean', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToMigrationLine', [$column]);

        $this->assertSame("\$table->boolean('is_active');", $result);
    }

    public function test_column_to_migration_line_integer(): void
    {
        $column = ['name' => 'count', 'type' => 'integer', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToMigrationLine', [$column]);

        $this->assertSame("\$table->integer('count');", $result);
    }

    public function test_column_to_migration_line_big_integer(): void
    {
        $column = ['name' => 'amount', 'type' => 'bigInteger', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToMigrationLine', [$column]);

        $this->assertSame("\$table->bigInteger('amount');", $result);
    }

    public function test_column_to_migration_line_datetime(): void
    {
        $column = ['name' => 'published_at', 'type' => 'datetime', 'nullable' => true, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToMigrationLine', [$column]);

        $this->assertSame("\$table->datetime('published_at')->nullable();", $result);
    }

    public function test_column_to_migration_line_timestamp(): void
    {
        $column = ['name' => 'logged_at', 'type' => 'timestamp', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToMigrationLine', [$column]);

        $this->assertSame("\$table->timestamp('logged_at');", $result);
    }

    public function test_column_to_migration_line_float(): void
    {
        $column = ['name' => 'rate', 'type' => 'float', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToMigrationLine', [$column]);

        $this->assertSame("\$table->float('rate');", $result);
    }

    // ------------------------------------------------------------------
    // formatDefaultValue — additional coverage
    // ------------------------------------------------------------------

    public function test_format_default_value_integer(): void
    {
        $result = $this->invokeMethod('formatDefaultValue', ['42', 'integer']);

        $this->assertSame('42', $result);
    }

    public function test_format_default_value_boolean_true(): void
    {
        $result = $this->invokeMethod('formatDefaultValue', ['true', 'boolean']);

        $this->assertSame('true', $result);
    }

    public function test_format_default_value_boolean_one(): void
    {
        $result = $this->invokeMethod('formatDefaultValue', ['1', 'boolean']);

        $this->assertSame('true', $result);
    }

    public function test_format_default_value_boolean_false(): void
    {
        $result = $this->invokeMethod('formatDefaultValue', ['false', 'boolean']);

        $this->assertSame('false', $result);
    }

    public function test_format_default_value_string(): void
    {
        $result = $this->invokeMethod('formatDefaultValue', ['active', 'string']);

        $this->assertSame("'active'", $result);
    }

    // ------------------------------------------------------------------
    // columnToFakerValue — name-based faker values
    // ------------------------------------------------------------------

    public function test_column_to_faker_value_name_field(): void
    {
        $column = ['name' => 'name', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->name()', $result);
    }

    public function test_column_to_faker_value_email_field(): void
    {
        $column = ['name' => 'email', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->safeEmail()', $result);
    }

    public function test_column_to_faker_value_phone_field(): void
    {
        $column = ['name' => 'phone', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->phoneNumber()', $result);
    }

    public function test_column_to_faker_value_address_field(): void
    {
        $column = ['name' => 'address', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->address()', $result);
    }

    public function test_column_to_faker_value_city_field(): void
    {
        $column = ['name' => 'city', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->city()', $result);
    }

    public function test_column_to_faker_value_country_field(): void
    {
        $column = ['name' => 'country', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->country()', $result);
    }

    public function test_column_to_faker_value_zip_code_field(): void
    {
        $column = ['name' => 'zip_code', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->postcode()', $result);
    }

    public function test_column_to_faker_value_url_field(): void
    {
        $column = ['name' => 'url', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->url()', $result);
    }

    public function test_column_to_faker_value_website_field(): void
    {
        $column = ['name' => 'website', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->url()', $result);
    }

    public function test_column_to_faker_value_title_field(): void
    {
        $column = ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->sentence(3)', $result);
    }

    public function test_column_to_faker_value_description_field(): void
    {
        $column = ['name' => 'description', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->paragraph()', $result);
    }

    public function test_column_to_faker_value_slug_field(): void
    {
        $column = ['name' => 'slug', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->slug()', $result);
    }

    public function test_column_to_faker_value_price_field(): void
    {
        $column = ['name' => 'price', 'type' => 'decimal', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->randomFloat(2, 1, 1000)', $result);
    }

    public function test_column_to_faker_value_quantity_field(): void
    {
        $column = ['name' => 'quantity', 'type' => 'integer', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->numberBetween(1, 100)', $result);
    }

    public function test_column_to_faker_value_is_prefixed_field(): void
    {
        $column = ['name' => 'is_published', 'type' => 'boolean', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->boolean()', $result);
    }

    public function test_column_to_faker_value_first_name_field(): void
    {
        $column = ['name' => 'first_name', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->firstName()', $result);
    }

    public function test_column_to_faker_value_last_name_field(): void
    {
        $column = ['name' => 'last_name', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->lastName()', $result);
    }

    public function test_column_to_faker_value_content_field(): void
    {
        $column = ['name' => 'content', 'type' => 'text', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->paragraph()', $result);
    }

    public function test_column_to_faker_value_body_field(): void
    {
        $column = ['name' => 'body', 'type' => 'text', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->paragraph()', $result);
    }

    public function test_column_to_faker_value_full_name_field(): void
    {
        $column = ['name' => 'full_name', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->name()', $result);
    }

    public function test_column_to_faker_value_phone_number_field(): void
    {
        $column = ['name' => 'phone_number', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->phoneNumber()', $result);
    }

    public function test_column_to_faker_value_postal_code_field(): void
    {
        $column = ['name' => 'postal_code', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->postcode()', $result);
    }

    public function test_column_to_faker_value_amount_field(): void
    {
        $column = ['name' => 'amount', 'type' => 'decimal', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->randomFloat(2, 1, 1000)', $result);
    }

    public function test_column_to_faker_value_cost_field(): void
    {
        $column = ['name' => 'cost', 'type' => 'decimal', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->randomFloat(2, 1, 1000)', $result);
    }

    public function test_column_to_faker_value_count_field(): void
    {
        $column = ['name' => 'count', 'type' => 'integer', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->numberBetween(1, 100)', $result);
    }

    public function test_column_to_faker_value_nullable_name_based(): void
    {
        $column = ['name' => 'email', 'type' => 'string', 'nullable' => true, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->optional()->safeEmail()', $result);
    }

    public function test_column_to_faker_value_integer_type(): void
    {
        $column = ['name' => 'priority', 'type' => 'integer', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->numberBetween(1, 100)', $result);
    }

    public function test_column_to_faker_value_json_type(): void
    {
        $column = ['name' => 'metadata', 'type' => 'json', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('[]', $result);
    }

    public function test_column_to_faker_value_nullable_json(): void
    {
        $column = ['name' => 'metadata', 'type' => 'json', 'nullable' => true, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        // json nullable should still return [] (not optional)
        $this->assertSame('[]', $result);
    }

    public function test_column_to_faker_value_nullable_boolean(): void
    {
        $column = ['name' => 'is_verified', 'type' => 'boolean', 'nullable' => true, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        // boolean is name-based (is_ prefix)
        $this->assertSame('fake()->optional()->boolean()', $result);
    }

    public function test_column_to_faker_value_foreign_id_without_model(): void
    {
        $column = ['name' => 'parent_id', 'type' => 'foreignId', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->numberBetween(1, 10)', $result);
    }

    public function test_column_to_faker_value_foreign_id_with_model(): void
    {
        $column = ['name' => 'user_id', 'type' => 'foreignId', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => 'User'];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('\\App\\Models\\User::factory()', $result);
    }

    public function test_column_to_faker_value_nullable_string(): void
    {
        $column = ['name' => 'notes_field', 'type' => 'string', 'nullable' => true, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->optional()->sentence(3)', $result);
    }

    public function test_column_to_faker_value_nullable_integer(): void
    {
        $column = ['name' => 'priority', 'type' => 'integer', 'nullable' => true, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->optional()->numberBetween(1, 100)', $result);
    }

    // ------------------------------------------------------------------
    // buildRelationshipMethods
    // ------------------------------------------------------------------

    public function test_build_relationship_methods_with_foreign_keys(): void
    {
        $columns = [
            ['name' => 'user_id', 'type' => 'foreignId', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => 'User'],
            ['name' => 'category_id', 'type' => 'foreignId', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => 'Category'],
        ];

        $result = $this->invokeMethod('buildRelationshipMethods', [$columns]);

        $this->assertStringContainsString('function user()', $result);
        $this->assertStringContainsString('function category()', $result);
        $this->assertStringContainsString('belongsTo(User::class)', $result);
        $this->assertStringContainsString('belongsTo(Category::class)', $result);
    }

    public function test_build_relationship_methods_empty_for_no_fks(): void
    {
        $columns = [
            ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
        ];

        $result = $this->invokeMethod('buildRelationshipMethods', [$columns]);

        $this->assertSame('', $result);
    }

    public function test_build_relationship_methods_skips_fk_without_model(): void
    {
        $columns = [
            ['name' => 'parent_id', 'type' => 'foreignId', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
        ];

        $result = $this->invokeMethod('buildRelationshipMethods', [$columns]);

        $this->assertSame('', $result);
    }

    // ------------------------------------------------------------------
    // roleAccessToPermissions
    // ------------------------------------------------------------------

    public function test_role_access_to_permissions_editor(): void
    {
        $result = $this->invokeMethod('roleAccessToPermissions', ['posts', 'editor']);

        $this->assertSame(['posts.*'], $result);
    }

    public function test_role_access_to_permissions_viewer(): void
    {
        $result = $this->invokeMethod('roleAccessToPermissions', ['posts', 'viewer']);

        $this->assertSame(['posts.index', 'posts.show'], $result);
    }

    public function test_role_access_to_permissions_writer(): void
    {
        $result = $this->invokeMethod('roleAccessToPermissions', ['posts', 'writer']);

        $this->assertSame(['posts.index', 'posts.show', 'posts.store', 'posts.update'], $result);
    }

    public function test_role_access_to_permissions_none(): void
    {
        $result = $this->invokeMethod('roleAccessToPermissions', ['posts', 'none']);

        $this->assertSame([], $result);
    }

    public function test_role_access_to_permissions_unknown(): void
    {
        $result = $this->invokeMethod('roleAccessToPermissions', ['posts', 'unknown']);

        $this->assertSame([], $result);
    }

    // ------------------------------------------------------------------
    // permissionsToPhpArray
    // ------------------------------------------------------------------

    public function test_permissions_to_php_array_empty(): void
    {
        $result = $this->invokeMethod('permissionsToPhpArray', [[]]);

        $this->assertSame('[]', $result);
    }

    public function test_permissions_to_php_array_single(): void
    {
        $result = $this->invokeMethod('permissionsToPhpArray', [['posts.*']]);

        $this->assertSame("['posts.*']", $result);
    }

    public function test_permissions_to_php_array_multiple(): void
    {
        $result = $this->invokeMethod('permissionsToPhpArray', [['posts.index', 'posts.show']]);

        $this->assertSame("['posts.index', 'posts.show']", $result);
    }

    // ------------------------------------------------------------------
    // getStub and replacePlaceholders
    // ------------------------------------------------------------------

    public function test_get_stub_returns_content(): void
    {
        $result = $this->invokeMethod('getStub', ['model']);

        $this->assertNotEmpty($result);
        $this->assertStringContainsString('class', $result);
    }

    public function test_replace_placeholders_replaces_all_tokens(): void
    {
        $stub = 'Hello {{ name }}, you are a {{ role }}.';

        $result = $this->invokeMethod('replacePlaceholders', [$stub, ['name' => 'Alice', 'role' => 'admin']]);

        $this->assertSame('Hello Alice, you are a admin.', $result);
    }

    public function test_replace_placeholders_ignores_missing_keys(): void
    {
        $stub = 'Hello {{ name }}, {{ unknown }}.';

        $result = $this->invokeMethod('replacePlaceholders', [$stub, ['name' => 'Bob']]);

        $this->assertSame('Hello Bob, {{ unknown }}.', $result);
    }

    // ------------------------------------------------------------------
    // arrayToPhpString and assocArrayToPhpString
    // ------------------------------------------------------------------

    public function test_array_to_php_string_empty(): void
    {
        $result = $this->invokeMethod('arrayToPhpString', [[], 8]);

        $this->assertSame('[]', $result);
    }

    public function test_array_to_php_string_with_items(): void
    {
        $result = $this->invokeMethod('arrayToPhpString', [['title', 'content'], 8]);

        $this->assertStringContainsString("'title'", $result);
        $this->assertStringContainsString("'content'", $result);
        $this->assertStringStartsWith('[', $result);
    }

    public function test_assoc_array_to_php_string_empty(): void
    {
        $result = $this->invokeMethod('assocArrayToPhpString', [[], 8]);

        $this->assertSame('[]', $result);
    }

    public function test_assoc_array_to_php_string_with_items(): void
    {
        $result = $this->invokeMethod('assocArrayToPhpString', [['title' => 'required|string', 'body' => 'nullable|string'], 8]);

        $this->assertStringContainsString("'title' => 'required|string'", $result);
        $this->assertStringContainsString("'body' => 'nullable|string'", $result);
    }

    // ------------------------------------------------------------------
    // getColumnTypeDisplay
    // ------------------------------------------------------------------

    public function test_get_column_type_display_decimal(): void
    {
        $column = ['name' => 'price', 'type' => 'decimal', 'nullable' => false, 'unique' => false, 'default' => null];

        $result = $this->invokeMethod('getColumnTypeDisplay', [$column]);

        $this->assertSame('decimal(8,2)', $result);
    }

    public function test_get_column_type_display_string(): void
    {
        $column = ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null];

        $result = $this->invokeMethod('getColumnTypeDisplay', [$column]);

        $this->assertSame('string', $result);
    }

    // ------------------------------------------------------------------
    // getColumnModifier
    // ------------------------------------------------------------------

    public function test_get_column_modifier_required(): void
    {
        $column = ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null];

        $result = $this->invokeMethod('getColumnModifier', [$column]);

        $this->assertSame('required', $result);
    }

    public function test_get_column_modifier_nullable(): void
    {
        $column = ['name' => 'body', 'type' => 'text', 'nullable' => true, 'unique' => false, 'default' => null];

        $result = $this->invokeMethod('getColumnModifier', [$column]);

        $this->assertSame('nullable', $result);
    }

    public function test_get_column_modifier_foreign_id_constrained(): void
    {
        $column = ['name' => 'user_id', 'type' => 'foreignId', 'nullable' => false, 'unique' => false, 'default' => null];

        $result = $this->invokeMethod('getColumnModifier', [$column]);

        $this->assertSame('constrained', $result);
    }

    public function test_get_column_modifier_nullable_foreign_id(): void
    {
        $column = ['name' => 'user_id', 'type' => 'foreignId', 'nullable' => true, 'unique' => false, 'default' => null];

        $result = $this->invokeMethod('getColumnModifier', [$column]);

        $this->assertSame('constrained, nullable', $result);
    }

    public function test_get_column_modifier_unique(): void
    {
        $column = ['name' => 'email', 'type' => 'string', 'nullable' => false, 'unique' => true, 'default' => null];

        $result = $this->invokeMethod('getColumnModifier', [$column]);

        $this->assertSame('required, unique', $result);
    }

    public function test_get_column_modifier_with_default(): void
    {
        $column = ['name' => 'status', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => 'active'];

        $result = $this->invokeMethod('getColumnModifier', [$column]);

        $this->assertSame('required, default:active', $result);
    }

    public function test_get_column_modifier_all_modifiers(): void
    {
        $column = ['name' => 'code', 'type' => 'string', 'nullable' => true, 'unique' => true, 'default' => 'ABC'];

        $result = $this->invokeMethod('getColumnModifier', [$column]);

        $this->assertSame('nullable, unique, default:ABC', $result);
    }

    // ------------------------------------------------------------------
    // printCreatedFiles with migration present
    // ------------------------------------------------------------------

    public function test_print_created_files_with_migration_file(): void
    {
        $migrationsDir = $this->tempDir . '/migrations';
        File::ensureDirectoryExists($migrationsDir);
        $this->app->useDatabasePath($this->tempDir);

        File::put($migrationsDir . '/2024_01_01_000000_create_posts_table.php', '<?php // migration');

        $output = $this->setBufferedOutput();
        $this->invokeMethod('printCreatedFiles', ['Post', [['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null]], ['policy' => true, 'factory_seeder' => true]]);

        $out = $output->fetch();
        $this->assertStringContainsString('create_posts_table', $out);
    }

    // ------------------------------------------------------------------
    // getRolesFromRoleModel with no matching pattern
    // ------------------------------------------------------------------

    public function test_get_roles_from_role_model_returns_empty_when_no_roles_array(): void
    {
        $modelsDir = $this->tempDir . '/Models';
        File::ensureDirectoryExists($modelsDir);

        $roleContent = <<<'PHP'
<?php

namespace App\Models;

class Role extends Model
{
    protected $fillable = ['name'];
}
PHP;
        File::put($modelsDir . '/Role.php', $roleContent);
        $this->app->useAppPath($this->tempDir);

        $result = $this->invokeMethod('getRolesFromRoleModel', []);

        $this->assertSame([], $result);
    }

    // ------------------------------------------------------------------
    // updateMigrationFile with foreignId columns
    // ------------------------------------------------------------------

    public function test_update_migration_file_with_foreign_id_columns(): void
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
        Schema::create('comments', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });
    }
};
PHP;
        $timestamp = now()->format('Y_m_d_His');
        File::put($migrationsDir . "/{$timestamp}_create_comments_table.php", $migrationContent);

        $columns = [
            ['name' => 'post_id', 'type' => 'foreignId', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => true, 'foreignModel' => 'Post'],
            ['name' => 'body', 'type' => 'text', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
        ];

        $this->invokeMethod('updateMigrationFile', ['Comment', $columns, true]);

        $updatedContent = File::get($migrationsDir . "/{$timestamp}_create_comments_table.php");
        $this->assertStringContainsString("foreignId('post_id')", $updatedContent);
        $this->assertStringContainsString("constrained('posts')", $updatedContent);
        $this->assertStringContainsString("text('body')", $updatedContent);
    }

    // ------------------------------------------------------------------
    // updateFactoryFile with various column types
    // ------------------------------------------------------------------

    public function test_update_factory_file_with_various_types(): void
    {
        $factoriesDir = $this->tempDir . '/factories';
        File::ensureDirectoryExists($factoriesDir);
        $this->app->useDatabasePath($this->tempDir);

        $factoryContent = <<<'PHP'
<?php

namespace Database\Factories;

use App\Models\Product;
use Illuminate\Database\Eloquent\Factories\Factory;

class ProductFactory extends Factory
{
    protected $model = Product::class;

    public function definition(): array
    {
        return [
        ];
    }
}
PHP;
        File::put($factoriesDir . '/ProductFactory.php', $factoryContent);

        $columns = [
            ['name' => 'name', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
            ['name' => 'price', 'type' => 'decimal', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
            ['name' => 'is_active', 'type' => 'boolean', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
            ['name' => 'metadata', 'type' => 'json', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
        ];

        $this->invokeMethod('updateFactoryFile', ['Product', $columns]);

        $updatedContent = File::get($factoriesDir . '/ProductFactory.php');
        $this->assertStringContainsString("'name'", $updatedContent);
        $this->assertStringContainsString("'price'", $updatedContent);
        $this->assertStringContainsString("'is_active'", $updatedContent);
        $this->assertStringContainsString("'metadata'", $updatedContent);
    }

    // ------------------------------------------------------------------
    // registerModelInConfig — legacy array() syntax
    // ------------------------------------------------------------------

    public function test_register_model_in_config_legacy_array_syntax(): void
    {
        $configDir = $this->tempDir . '/config';
        File::ensureDirectoryExists($configDir);

        $configContent = <<<'PHP'
<?php

return array(
    'models' => array(
    ),
);
PHP;
        File::put($configDir . '/rhino.php', $configContent);
        $this->app->useConfigPath($configDir);

        $this->invokeMethod('registerModelInConfig', ['Article']);

        $content = File::get($configDir . '/rhino.php');
        $this->assertStringContainsString("'articles'", $content);
    }

    // ------------------------------------------------------------------
    // writeModelFile with owner relation
    // ------------------------------------------------------------------

    public function test_write_model_file_with_owner_relation(): void
    {
        $modelsDir = $this->tempDir . '/Models';
        File::ensureDirectoryExists($modelsDir);
        $this->app->useAppPath($this->tempDir);

        $columns = [
            ['name' => 'blog_id', 'type' => 'foreignId', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => true, 'foreignModel' => 'Blog'],
            ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
        ];

        $this->invokeMethod('writeModelFile', ['BlogPost', $columns, false, 'blog', true, false]);

        $content = File::get($modelsDir . '/BlogPost.php');
        $this->assertStringContainsString("use App\\Models\\Blog;", $content);
        $this->assertStringContainsString('function blog()', $content);
    }

    // ------------------------------------------------------------------
    // generateTestFile with relationships and phpunit multi-tenant
    // ------------------------------------------------------------------

    public function test_generate_test_file_phpunit_multi_tenant(): void
    {
        $testDir = $this->tempDir . '/tests/Model';
        File::ensureDirectoryExists($testDir);

        $this->app->setBasePath($this->tempDir);

        $configDir = $this->tempDir . '/config';
        File::ensureDirectoryExists($configDir);
        $configContent = "<?php\n\nreturn [\n    'models' => [],\n    'test_framework' => 'phpunit',\n    'multi_tenant' => ['organization_identifier_column' => 'id'],\n    'route_groups' => ['tenant' => ['prefix' => '{organization}']],\n];\n";
        File::put($configDir . '/rhino.php', $configContent);

        $columns = [
            ['name' => 'user_id', 'type' => 'foreignId', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => 'User'],
        ];
        $roleAccess = ['admin' => 'editor', 'viewer' => 'viewer'];

        $this->invokeMethod('generateTestFile', ['Comment', $columns, $roleAccess, true]);

        $this->assertFileExists($testDir . '/CommentTest.php');
        $content = File::get($testDir . '/CommentTest.php');
        $this->assertStringContainsString('Comment', $content);
    }

    // ------------------------------------------------------------------
    // generateTestFile with relationships (pest, non-tenant)
    // ------------------------------------------------------------------

    public function test_generate_test_file_pest_non_tenant_with_relationships(): void
    {
        $testDir = $this->tempDir . '/tests/Model';
        File::ensureDirectoryExists($testDir);

        $this->app->setBasePath($this->tempDir);

        $configDir = $this->tempDir . '/config';
        File::ensureDirectoryExists($configDir);
        $configContent = "<?php\n\nreturn [\n    'models' => [],\n    'test_framework' => 'pest',\n];\n";
        File::put($configDir . '/rhino.php', $configContent);

        $columns = [
            ['name' => 'author_id', 'type' => 'foreignId', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => 'User'],
        ];

        $this->invokeMethod('generateTestFile', ['Article', $columns, [], false]);

        $this->assertFileExists($testDir . '/ArticleTest.php');
        $content = File::get($testDir . '/ArticleTest.php');
        $this->assertStringContainsString('Article', $content);
    }

    // ------------------------------------------------------------------
    // printColumnsSummary with foreignId column
    // ------------------------------------------------------------------

    public function test_print_columns_summary_with_foreign_key_column(): void
    {
        $output = $this->setBufferedOutput();

        $columns = [
            ['name' => 'user_id', 'type' => 'foreignId', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false],
            ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => true, 'default' => null, 'index' => false],
        ];

        $this->invokeMethod('printColumnsSummary', [$columns]);

        $out = $output->fetch();
        $this->assertStringContainsString('user_id', $out);
        $this->assertStringContainsString('title', $out);
    }

    // ------------------------------------------------------------------
    // getOrganizationIdentifierColumn — missing key fallback
    // ------------------------------------------------------------------

    public function test_get_organization_identifier_column_falls_back_when_key_missing(): void
    {
        $configDir = $this->tempDir . '/config';
        File::ensureDirectoryExists($configDir);

        $configContent = <<<'PHP'
<?php

return [
    'models' => [],
    'multi_tenant' => [],
];
PHP;
        File::put($configDir . '/rhino.php', $configContent);
        $this->app->useConfigPath($configDir);

        $result = $this->invokeMethod('getOrganizationIdentifierColumn', []);

        $this->assertSame('id', $result);
    }

    // ------------------------------------------------------------------
    // columnToFakerValue — boolean type (non-nullable, no name match)
    // ------------------------------------------------------------------

    public function test_column_to_faker_value_plain_boolean(): void
    {
        $column = [
            'name' => 'is_active',
            'type' => 'boolean',
            'nullable' => false,
            'unique' => false,
            'index' => false,
            'default' => null,
            'foreignModel' => null,
        ];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->boolean()', $result);
    }

    // ------------------------------------------------------------------
    // buildRoleTests — unknown/default access level
    // ------------------------------------------------------------------

    public function test_build_role_tests_with_unknown_access(): void
    {
        $roleAccess = [
            'custom_role' => 'superuser',
        ];

        $result = $this->invokeMethod('buildRoleTests', [
            'Article', 'articles', $roleAccess, true, 'id', 'pest',
        ]);

        // Unknown access level should map to empty arrays (default)
        // so no test content generated for that role
        $this->assertIsString($result);
    }

    // ------------------------------------------------------------------
    // generatePolicy — file creation and output
    // ------------------------------------------------------------------

    public function test_generate_policy_creates_policy_file_and_returns_zero(): void
    {
        $policiesDir = $this->tempDir . '/app/Policies';
        File::ensureDirectoryExists($policiesDir);

        // Set the app path to our temp dir
        $this->app->useAppPath($this->tempDir . '/app');

        $buffered = $this->setBufferedOutput();

        $result = $this->invokeMethod('generatePolicy', ['Article']);

        $this->assertSame(0, $result);
        $this->assertFileExists($policiesDir . '/ArticlePolicy.php');

        $content = File::get($policiesDir . '/ArticlePolicy.php');
        $this->assertStringContainsString('ArticlePolicy', $content);
        $this->assertStringContainsString('Article', $content);
    }

    public function test_generate_policy_appends_policy_suffix_when_passed_in_name(): void
    {
        $policiesDir = $this->tempDir . '/app/Policies';
        File::ensureDirectoryExists($policiesDir);

        $this->app->useAppPath($this->tempDir . '/app');
        $this->setBufferedOutput();

        $result = $this->invokeMethod('generatePolicy', ['PostPolicy']);

        $this->assertSame(0, $result);
        $this->assertFileExists($policiesDir . '/PostPolicy.php');
    }

    // ------------------------------------------------------------------
    // generateScope — file creation and output
    // ------------------------------------------------------------------

    public function test_generate_scope_creates_scope_file_and_returns_zero(): void
    {
        $scopesDir = $this->tempDir . '/app/Models/Scopes';
        File::ensureDirectoryExists($scopesDir);

        $this->app->useAppPath($this->tempDir . '/app');
        $buffered = $this->setBufferedOutput();

        $result = $this->invokeMethod('generateScope', ['Article']);

        $this->assertSame(0, $result);
        $this->assertFileExists($scopesDir . '/ArticleScope.php');

        $content = File::get($scopesDir . '/ArticleScope.php');
        $this->assertStringContainsString('ArticleScope', $content);
    }

    public function test_generate_scope_appends_scope_suffix_when_passed_in_name(): void
    {
        $scopesDir = $this->tempDir . '/app/Models/Scopes';
        File::ensureDirectoryExists($scopesDir);

        $this->app->useAppPath($this->tempDir . '/app');
        $this->setBufferedOutput();

        $result = $this->invokeMethod('generateScope', ['PostScope']);

        $this->assertSame(0, $result);
        $this->assertFileExists($scopesDir . '/PostScope.php');
    }

    // ------------------------------------------------------------------
    // writeModelFile — with all options combined
    // ------------------------------------------------------------------

    public function test_write_model_file_with_all_options(): void
    {
        $modelsDir = $this->tempDir . '/app/Models';
        File::ensureDirectoryExists($modelsDir);
        $this->app->useAppPath($this->tempDir . '/app');

        $columns = [
            ['name' => 'organization_id', 'type' => 'foreignId', 'nullable' => false, 'unique' => false, 'index' => true, 'default' => null, 'foreignModel' => 'Organization'],
            ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => true, 'index' => false, 'default' => null, 'foreignModel' => null],
            ['name' => 'body', 'type' => 'text', 'nullable' => true, 'unique' => false, 'index' => false, 'default' => null, 'foreignModel' => null],
        ];

        $this->invokeMethod('writeModelFile', [
            'Article', $columns, true, null, true, true,
        ]);

        $this->assertFileExists($modelsDir . '/Article.php');
        $content = File::get($modelsDir . '/Article.php');
        $this->assertStringContainsString('BelongsToOrganization', $content);
        $this->assertStringContainsString('HasAuditTrail', $content);
        // SoftDeletes is included in RhinoModel base class, so the generated model
        // inherits it via RhinoModel rather than adding the trait directly
        $this->assertStringContainsString('RhinoModel', $content);
    }

    // ------------------------------------------------------------------
    // columnToFakerValue — nullable boolean should not use optional()
    // ------------------------------------------------------------------

    public function test_column_to_faker_value_nullable_boolean_field_with_is_prefix(): void
    {
        // 'is_enabled' matches nameBasedFakerValue 'is_' prefix, which returns 'boolean()'
        // Since nullable is true, it goes through the optional path for name-based values
        $column = [
            'name' => 'is_enabled',
            'type' => 'boolean',
            'nullable' => true,
            'unique' => false,
            'index' => false,
            'default' => null,
            'foreignModel' => null,
        ];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);
        $this->assertSame('fake()->optional()->boolean()', $result);
    }

    public function test_column_to_faker_value_non_nullable_boolean_no_name_match(): void
    {
        // Use a name that does NOT match any nameBasedFakerValue pattern
        $column = [
            'name' => 'active',
            'type' => 'boolean',
            'nullable' => false,
            'unique' => false,
            'index' => false,
            'default' => null,
            'foreignModel' => null,
        ];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);
        $this->assertSame('fake()->boolean()', $result);
    }

    public function test_column_to_faker_value_nullable_boolean_no_name_match(): void
    {
        // A nullable boolean that doesn't match name patterns should still return boolean()
        // without optional() because boolean is excluded from the optional() wrapping
        $column = [
            'name' => 'verified',
            'type' => 'boolean',
            'nullable' => true,
            'unique' => false,
            'index' => false,
            'default' => null,
            'foreignModel' => null,
        ];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);
        $this->assertSame('fake()->boolean()', $result);
    }

    // ------------------------------------------------------------------
    // columnToMigrationLine — string type
    // ------------------------------------------------------------------

    public function test_column_to_migration_line_string(): void
    {
        $column = [
            'name' => 'title',
            'type' => 'string',
            'nullable' => false,
            'unique' => false,
            'index' => false,
            'default' => null,
            'foreignModel' => null,
        ];

        $result = $this->invokeMethod('columnToMigrationLine', [$column]);
        $this->assertStringContainsString("string('title')", $result);
    }

    // ------------------------------------------------------------------
    // generateTestFile — pest, multi-tenant, with role access and columns
    // ------------------------------------------------------------------

    public function test_generate_test_file_pest_multi_tenant_with_role_access(): void
    {
        $testsDir = $this->tempDir . '/tests/Model';
        File::ensureDirectoryExists($testsDir);

        $this->app->setBasePath($this->tempDir);
        $this->app['config']->set('rhino.test_framework', 'pest');
        $this->setBufferedOutput();

        $columns = [
            ['name' => 'category_id', 'type' => 'foreignId', 'nullable' => false, 'unique' => false, 'index' => true, 'default' => null, 'foreignModel' => 'Category'],
        ];

        $roleAccess = [
            'admin' => 'editor',
            'editor' => 'writer',
            'viewer' => 'viewer',
        ];

        $this->invokeMethod('generateTestFile', ['Article', $columns, $roleAccess, true]);

        $this->assertFileExists($testsDir . '/ArticleTest.php');
        $content = File::get($testsDir . '/ArticleTest.php');
        $this->assertStringContainsString('Article', $content);
    }

    // ------------------------------------------------------------------
    // buildRoleTests — non-multi-tenant phpunit with viewer blocked endpoints
    // ------------------------------------------------------------------

    public function test_build_role_tests_phpunit_non_tenant_with_blocked(): void
    {
        $roleAccess = [
            'viewer' => 'viewer',
        ];

        $result = $this->invokeMethod('buildRoleTests', [
            'Post', 'posts', $roleAccess, false, null, 'phpunit',
        ]);

        $this->assertStringContainsString('test_viewer_is_blocked', $result);
        $this->assertStringContainsString('assertStatus(403)', $result);
    }

    // ------------------------------------------------------------------
    // buildRoleTests — pest multi-tenant with all access levels
    // ------------------------------------------------------------------

    public function test_build_role_tests_pest_multi_tenant_all_access_levels(): void
    {
        $roleAccess = [
            'admin' => 'editor',
            'editor' => 'writer',
            'viewer' => 'viewer',
            'guest' => 'none',
        ];

        $result = $this->invokeMethod('buildRoleTests', [
            'Article', 'articles', $roleAccess, true, 'id', 'pest',
        ]);

        $this->assertStringContainsString('admin', $result);
        $this->assertStringContainsString('editor', $result);
        $this->assertStringContainsString('viewer', $result);
        // 'none' access should not generate allowed tests
        $this->assertStringContainsString('guest', $result);
    }

    // ------------------------------------------------------------------
    // columnToValidationRule — nullable column
    // ------------------------------------------------------------------

    public function test_column_to_validation_rule_nullable_string(): void
    {
        $column = [
            'name' => 'subtitle',
            'type' => 'string',
            'nullable' => true,
            'unique' => false,
            'index' => false,
            'default' => null,
            'foreignModel' => null,
        ];

        $result = $this->invokeMethod('columnToValidationRule', [$column, 'articles']);
        $this->assertStringContainsString('nullable', $result);
        $this->assertStringContainsString('string', $result);
    }

    // ------------------------------------------------------------------
    // updateMigrationFile — with mixed column types including nullable FK
    // ------------------------------------------------------------------

    public function test_update_migration_file_with_nullable_foreign_id(): void
    {
        $migrationsDir = $this->tempDir . '/database/migrations';
        File::ensureDirectoryExists($migrationsDir);
        $this->app->useDatabasePath($this->tempDir . '/database');

        $migrationContent = <<<'PHP'
<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration {
    public function up(): void
    {
        Schema::create('reviews', function (Blueprint $table) {
            $table->id();
            $table->timestamps();
        });
    }
};
PHP;
        File::put($migrationsDir . '/2024_01_01_000000_create_reviews_table.php', $migrationContent);

        $columns = [
            ['name' => 'user_id', 'type' => 'foreignId', 'nullable' => true, 'unique' => false, 'index' => true, 'default' => null, 'foreignModel' => 'User'],
            ['name' => 'rating', 'type' => 'integer', 'nullable' => false, 'unique' => false, 'index' => false, 'default' => '0', 'foreignModel' => null],
        ];

        $this->invokeMethod('updateMigrationFile', ['Review', $columns, true]);

        $content = File::get($migrationsDir . '/2024_01_01_000000_create_reviews_table.php');
        $this->assertStringContainsString('foreignId', $content);
        $this->assertStringContainsString('integer', $content);
        $this->assertStringContainsString('softDeletes', $content);
    }
}
