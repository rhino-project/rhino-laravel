<?php

namespace Rhino\Tests\Feature;

use Illuminate\Support\Facades\File;
use Rhino\Commands\GenerateCommand;
use Rhino\Tests\TestCase;
use ReflectionMethod;
use ReflectionProperty;

class GenerateCommandTest extends TestCase
{
    protected GenerateCommand $command;

    protected string $tempDir;

    protected function setUp(): void
    {
        parent::setUp();

        $this->command = new GenerateCommand();
        $this->tempDir = sys_get_temp_dir() . '/rhino_generate_test_' . uniqid();
        File::ensureDirectoryExists($this->tempDir);

        // Set stubPath to the real stubs directory
        $this->setProperty('stubPath', realpath(__DIR__ . '/../../stubs/generate'));
    }

    protected function tearDown(): void
    {
        File::deleteDirectory($this->tempDir);

        parent::tearDown();
    }

    /**
     * Invoke a protected/private method on the command instance.
     */
    protected function invokeMethod(string $method, array $args = []): mixed
    {
        $ref = new ReflectionMethod(GenerateCommand::class, $method);
        $ref->setAccessible(true);

        return $ref->invoke($this->command, ...$args);
    }

    /**
     * Set a protected property on the command instance.
     */
    protected function setProperty(string $property, mixed $value): void
    {
        $ref = new ReflectionProperty(GenerateCommand::class, $property);
        $ref->setAccessible(true);
        $ref->setValue($this->command, $value);
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

    public function test_role_access_to_permissions_unknown_defaults_to_empty(): void
    {
        $result = $this->invokeMethod('roleAccessToPermissions', ['posts', 'unknown']);

        $this->assertSame([], $result);
    }

    // ------------------------------------------------------------------
    // columnToValidationRule
    // ------------------------------------------------------------------

    public function test_column_to_validation_rule_required_string(): void
    {
        $column = ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToValidationRule', [$column, 'posts']);

        $this->assertSame('required|string|max:255', $result);
    }

    public function test_column_to_validation_rule_nullable_text(): void
    {
        $column = ['name' => 'content', 'type' => 'text', 'nullable' => true, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToValidationRule', [$column, 'posts']);

        $this->assertSame('nullable|string', $result);
    }

    public function test_column_to_validation_rule_unique_string(): void
    {
        $column = ['name' => 'slug', 'type' => 'string', 'nullable' => false, 'unique' => true, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToValidationRule', [$column, 'posts']);

        $this->assertSame('required|unique:posts,slug|string|max:255', $result);
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
        $column = ['name' => 'published_at', 'type' => 'date', 'nullable' => true, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToValidationRule', [$column, 'posts']);

        $this->assertSame('nullable|date', $result);
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

        $result = $this->invokeMethod('columnToValidationRule', [$column, 'posts']);

        $this->assertSame('required|array', $result);
    }

    public function test_column_to_validation_rule_uuid(): void
    {
        $column = ['name' => 'external_id', 'type' => 'uuid', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToValidationRule', [$column, 'items']);

        $this->assertSame('required|uuid', $result);
    }

    public function test_column_to_validation_rule_foreign_id(): void
    {
        $column = ['name' => 'user_id', 'type' => 'foreignId', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => 'User'];

        $result = $this->invokeMethod('columnToValidationRule', [$column, 'posts']);

        $this->assertSame('required|integer|exists:users,id', $result);
    }

    public function test_column_to_validation_rule_foreign_id_without_model(): void
    {
        $column = ['name' => 'parent_id', 'type' => 'foreignId', 'nullable' => true, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToValidationRule', [$column, 'posts']);

        $this->assertSame('nullable|integer', $result);
    }

    // ------------------------------------------------------------------
    // buildRelationshipMethods
    // ------------------------------------------------------------------

    public function test_build_relationship_methods_with_foreign_keys(): void
    {
        $columns = [
            ['name' => 'user_id', 'type' => 'foreignId', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => 'User'],
            ['name' => 'category_id', 'type' => 'foreignId', 'nullable' => true, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => 'Category'],
        ];

        $result = $this->invokeMethod('buildRelationshipMethods', [$columns]);

        $this->assertStringContainsString('public function user()', $result);
        $this->assertStringContainsString('return $this->belongsTo(User::class)', $result);
        $this->assertStringContainsString('public function category()', $result);
        $this->assertStringContainsString('return $this->belongsTo(Category::class)', $result);
    }

    public function test_build_relationship_methods_with_no_foreign_keys(): void
    {
        $columns = [
            ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
        ];

        $result = $this->invokeMethod('buildRelationshipMethods', [$columns]);

        $this->assertEmpty($result);
    }

    public function test_build_relationship_methods_skips_non_foreign_columns(): void
    {
        $columns = [
            ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
            ['name' => 'author_id', 'type' => 'foreignId', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => 'User'],
            ['name' => 'content', 'type' => 'text', 'nullable' => true, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null],
        ];

        $result = $this->invokeMethod('buildRelationshipMethods', [$columns]);

        $this->assertStringContainsString('public function author()', $result);
        $this->assertStringContainsString('return $this->belongsTo(User::class)', $result);
        $this->assertStringNotContainsString('public function title()', $result);
        $this->assertStringNotContainsString('public function content()', $result);
    }

    // ------------------------------------------------------------------
    // getColumnTypeDisplay
    // ------------------------------------------------------------------

    public function test_get_column_type_display_decimal(): void
    {
        $column = ['type' => 'decimal'];

        $result = $this->invokeMethod('getColumnTypeDisplay', [$column]);

        $this->assertSame('decimal(8,2)', $result);
    }

    public function test_get_column_type_display_other_types(): void
    {
        $types = ['string', 'text', 'integer', 'boolean', 'date', 'json', 'uuid', 'foreignId'];

        foreach ($types as $type) {
            $result = $this->invokeMethod('getColumnTypeDisplay', [['type' => $type]]);
            $this->assertSame($type, $result);
        }
    }

    // ------------------------------------------------------------------
    // getColumnModifier
    // ------------------------------------------------------------------

    public function test_get_column_modifier_required(): void
    {
        $column = ['type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false];

        $result = $this->invokeMethod('getColumnModifier', [$column]);

        $this->assertSame('required', $result);
    }

    public function test_get_column_modifier_nullable(): void
    {
        $column = ['type' => 'string', 'nullable' => true, 'unique' => false, 'default' => null, 'index' => false];

        $result = $this->invokeMethod('getColumnModifier', [$column]);

        $this->assertSame('nullable', $result);
    }

    public function test_get_column_modifier_with_unique_and_default(): void
    {
        $column = ['type' => 'string', 'nullable' => false, 'unique' => true, 'default' => 'draft', 'index' => false];

        $result = $this->invokeMethod('getColumnModifier', [$column]);

        $this->assertSame('required, unique, default:draft', $result);
    }

    public function test_get_column_modifier_foreign_id(): void
    {
        $column = ['type' => 'foreignId', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false];

        $result = $this->invokeMethod('getColumnModifier', [$column]);

        $this->assertSame('constrained', $result);
    }

    public function test_get_column_modifier_foreign_id_nullable(): void
    {
        $column = ['type' => 'foreignId', 'nullable' => true, 'unique' => false, 'default' => null, 'index' => false];

        $result = $this->invokeMethod('getColumnModifier', [$column]);

        $this->assertSame('constrained, nullable', $result);
    }

    // ------------------------------------------------------------------
    // columnToMigrationLine
    // ------------------------------------------------------------------

    public function test_column_to_migration_line_string(): void
    {
        $column = ['name' => 'title', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToMigrationLine', [$column]);

        $this->assertSame("\$table->string('title');", $result);
    }

    public function test_column_to_migration_line_nullable_with_default(): void
    {
        $column = ['name' => 'status', 'type' => 'string', 'nullable' => true, 'unique' => false, 'default' => 'draft', 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToMigrationLine', [$column]);

        $this->assertSame("\$table->string('status')->nullable()->default('draft');", $result);
    }

    public function test_column_to_migration_line_unique_with_index(): void
    {
        $column = ['name' => 'slug', 'type' => 'string', 'nullable' => false, 'unique' => true, 'default' => null, 'index' => true, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToMigrationLine', [$column]);

        $this->assertSame("\$table->string('slug')->unique()->index();", $result);
    }

    public function test_column_to_migration_line_decimal(): void
    {
        $column = ['name' => 'price', 'type' => 'decimal', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToMigrationLine', [$column]);

        $this->assertSame("\$table->decimal('price', 8, 2);", $result);
    }

    public function test_column_to_migration_line_foreign_id(): void
    {
        $column = ['name' => 'user_id', 'type' => 'foreignId', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => 'User'];

        $result = $this->invokeMethod('columnToMigrationLine', [$column]);

        $this->assertSame("\$table->foreignId('user_id')->constrained('users')->cascadeOnDelete();", $result);
    }

    public function test_column_to_migration_line_foreign_id_nullable_with_index(): void
    {
        $column = ['name' => 'category_id', 'type' => 'foreignId', 'nullable' => true, 'unique' => false, 'default' => null, 'index' => true, 'foreignModel' => 'Category'];

        $result = $this->invokeMethod('columnToMigrationLine', [$column]);

        $this->assertSame("\$table->foreignId('category_id')->nullable()->constrained('categories')->cascadeOnDelete()->index();", $result);
    }

    public function test_column_to_migration_line_boolean_with_default(): void
    {
        $column = ['name' => 'is_active', 'type' => 'boolean', 'nullable' => false, 'unique' => false, 'default' => 'true', 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToMigrationLine', [$column]);

        $this->assertSame("\$table->boolean('is_active')->default(true);", $result);
    }

    public function test_column_to_migration_line_integer_default(): void
    {
        $column = ['name' => 'count', 'type' => 'integer', 'nullable' => false, 'unique' => false, 'default' => '0', 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToMigrationLine', [$column]);

        $this->assertSame("\$table->integer('count')->default(0);", $result);
    }

    // ------------------------------------------------------------------
    // columnToFakerValue
    // ------------------------------------------------------------------

    public function test_column_to_faker_value_name_based(): void
    {
        $column = ['name' => 'email', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->safeEmail()', $result);
    }

    public function test_column_to_faker_value_nullable_name_based(): void
    {
        $column = ['name' => 'phone', 'type' => 'string', 'nullable' => true, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->optional()->phoneNumber()', $result);
    }

    public function test_column_to_faker_value_type_based_string(): void
    {
        $column = ['name' => 'some_field', 'type' => 'string', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->sentence(3)', $result);
    }

    public function test_column_to_faker_value_foreign_id_with_model(): void
    {
        $column = ['name' => 'user_id', 'type' => 'foreignId', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => 'User'];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('\App\Models\User::factory()', $result);
    }

    public function test_column_to_faker_value_foreign_id_without_model(): void
    {
        $column = ['name' => 'parent_id', 'type' => 'foreignId', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->numberBetween(1, 10)', $result);
    }

    public function test_column_to_faker_value_json(): void
    {
        $column = ['name' => 'metadata', 'type' => 'json', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('[]', $result);
    }

    public function test_column_to_faker_value_nullable_integer(): void
    {
        $column = ['name' => 'score', 'type' => 'integer', 'nullable' => true, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->optional()->numberBetween(1, 100)', $result);
    }

    public function test_column_to_faker_value_boolean_not_optional(): void
    {
        // 'is_featured' matches is_* pattern in nameBasedFakerValue, returns 'boolean()'
        // nullable wraps it in optional()
        $column = ['name' => 'is_featured', 'type' => 'boolean', 'nullable' => true, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->optional()->boolean()', $result);
    }

    public function test_column_to_faker_value_boolean_non_nullable(): void
    {
        $column = ['name' => 'is_active', 'type' => 'boolean', 'nullable' => false, 'unique' => false, 'default' => null, 'index' => false, 'foreignModel' => null];

        $result = $this->invokeMethod('columnToFakerValue', [$column]);

        $this->assertSame('fake()->boolean()', $result);
    }

    // ------------------------------------------------------------------
    // nameBasedFakerValue
    // ------------------------------------------------------------------

    public function test_name_based_faker_value_known_names(): void
    {
        $expectations = [
            'name' => 'name()',
            'full_name' => 'name()',
            'first_name' => 'firstName()',
            'last_name' => 'lastName()',
            'email' => 'safeEmail()',
            'phone' => 'phoneNumber()',
            'phone_number' => 'phoneNumber()',
            'address' => 'address()',
            'city' => 'city()',
            'country' => 'country()',
            'zip_code' => 'postcode()',
            'postal_code' => 'postcode()',
            'url' => 'url()',
            'website' => 'url()',
            'title' => 'sentence(3)',
            'description' => 'paragraph()',
            'content' => 'paragraph()',
            'body' => 'paragraph()',
            'slug' => 'slug()',
            'price' => 'randomFloat(2, 1, 1000)',
            'amount' => 'randomFloat(2, 1, 1000)',
            'cost' => 'randomFloat(2, 1, 1000)',
            'quantity' => 'numberBetween(1, 100)',
            'count' => 'numberBetween(1, 100)',
            'is_active' => 'boolean()',
            'is_featured' => 'boolean()',
        ];

        foreach ($expectations as $name => $expected) {
            $result = $this->invokeMethod('nameBasedFakerValue', [$name]);
            $this->assertSame($expected, $result, "Failed for column name: {$name}");
        }
    }

    public function test_name_based_faker_value_unknown_returns_null(): void
    {
        $result = $this->invokeMethod('nameBasedFakerValue', ['random_field']);

        $this->assertNull($result);
    }

    // ------------------------------------------------------------------
    // arrayToPhpString
    // ------------------------------------------------------------------

    public function test_array_to_php_string_empty(): void
    {
        $result = $this->invokeMethod('arrayToPhpString', [[]]);

        $this->assertSame('[]', $result);
    }

    public function test_array_to_php_string_with_items(): void
    {
        $result = $this->invokeMethod('arrayToPhpString', [['title', 'content', 'status']]);

        $this->assertStringContainsString("'title'", $result);
        $this->assertStringContainsString("'content'", $result);
        $this->assertStringContainsString("'status'", $result);
        $this->assertStringStartsWith('[', $result);
        $this->assertStringEndsWith(']', $result);
    }

    public function test_array_to_php_string_with_custom_indent(): void
    {
        $result = $this->invokeMethod('arrayToPhpString', [['a', 'b'], 4]);

        // With indent of 4, inner items should have 4+4=8 spaces
        $this->assertStringContainsString("        'a'", $result);
    }

    // ------------------------------------------------------------------
    // assocArrayToPhpString
    // ------------------------------------------------------------------

    public function test_assoc_array_to_php_string_empty(): void
    {
        $result = $this->invokeMethod('assocArrayToPhpString', [[]]);

        $this->assertSame('[]', $result);
    }

    public function test_assoc_array_to_php_string_with_items(): void
    {
        $result = $this->invokeMethod('assocArrayToPhpString', [['title' => 'required|string', 'content' => 'nullable|string']]);

        $this->assertStringContainsString("'title' => 'required|string'", $result);
        $this->assertStringContainsString("'content' => 'nullable|string'", $result);
        $this->assertStringStartsWith('[', $result);
        $this->assertStringEndsWith(']', $result);
    }

    // ------------------------------------------------------------------
    // replacePlaceholders
    // ------------------------------------------------------------------

    public function test_replace_placeholders(): void
    {
        $stub = 'Hello {{ name }}, welcome to {{ app }}!';
        $result = $this->invokeMethod('replacePlaceholders', [$stub, ['name' => 'World', 'app' => 'Rhino']]);

        $this->assertSame('Hello World, welcome to Rhino!', $result);
    }

    public function test_replace_placeholders_leaves_unknown_placeholders(): void
    {
        $stub = '{{ known }} and {{ unknown }}';
        $result = $this->invokeMethod('replacePlaceholders', [$stub, ['known' => 'value']]);

        $this->assertSame('value and {{ unknown }}', $result);
    }

    // ------------------------------------------------------------------
    // formatDefaultValue
    // ------------------------------------------------------------------

    public function test_format_default_value_integer(): void
    {
        $result = $this->invokeMethod('formatDefaultValue', ['0', 'integer']);

        $this->assertSame('0', $result);
    }

    public function test_format_default_value_boolean_true(): void
    {
        $result = $this->invokeMethod('formatDefaultValue', ['true', 'boolean']);

        $this->assertSame('true', $result);
    }

    public function test_format_default_value_boolean_false(): void
    {
        $result = $this->invokeMethod('formatDefaultValue', ['0', 'boolean']);

        $this->assertSame('false', $result);
    }

    public function test_format_default_value_string(): void
    {
        $result = $this->invokeMethod('formatDefaultValue', ['draft', 'string']);

        $this->assertSame("'draft'", $result);
    }

    // ------------------------------------------------------------------
    // permissionsToPhpArray
    // ------------------------------------------------------------------

    public function test_permissions_to_php_array_empty(): void
    {
        $result = $this->invokeMethod('permissionsToPhpArray', [[]]);

        $this->assertSame('[]', $result);
    }

    public function test_permissions_to_php_array_with_items(): void
    {
        $result = $this->invokeMethod('permissionsToPhpArray', [['posts.index', 'posts.show']]);

        $this->assertSame("['posts.index', 'posts.show']", $result);
    }

    public function test_permissions_to_php_array_wildcard(): void
    {
        $result = $this->invokeMethod('permissionsToPhpArray', [['posts.*']]);

        $this->assertSame("['posts.*']", $result);
    }

    // ------------------------------------------------------------------
    // getStub (reads real stub files)
    // ------------------------------------------------------------------

    public function test_get_stub_returns_model_stub_content(): void
    {
        $result = $this->invokeMethod('getStub', ['model']);

        $this->assertStringContainsString('namespace App\Models', $result);
        $this->assertStringContainsString('{{ class }}', $result);
    }

    public function test_get_stub_returns_policy_stub_content(): void
    {
        $result = $this->invokeMethod('getStub', ['policy']);

        $this->assertStringContainsString('{{ policyName }}', $result);
        $this->assertStringContainsString('ResourcePolicy', $result);
    }

    public function test_get_stub_returns_scope_stub_content(): void
    {
        $result = $this->invokeMethod('getStub', ['scope']);

        $this->assertStringContainsString('{{ scopeName }}', $result);
    }
}
