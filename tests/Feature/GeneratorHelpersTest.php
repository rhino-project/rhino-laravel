<?php

namespace Rhino\Tests\Feature;

use ReflectionMethod;
use Rhino\Commands\BlueprintCommand;
use Rhino\Tests\TestCase;

/**
 * Unit coverage for the shared GeneratorHelpers trait (string/format helpers used
 * by the generator commands). Invoked via reflection on a BlueprintCommand host.
 * No production code changes.
 */
class GeneratorHelpersTest extends TestCase
{
    private BlueprintCommand $command;

    protected function setUp(): void
    {
        parent::setUp();
        $this->command = new BlueprintCommand();
    }

    private function invoke(string $method, array $args = []): mixed
    {
        $ref = new ReflectionMethod(BlueprintCommand::class, $method);
        $ref->setAccessible(true);

        return $ref->invoke($this->command, ...$args);
    }

    /** Build a complete column array with sane defaults. */
    private function col(array $overrides): array
    {
        return array_merge([
            'name' => 'field',
            'type' => 'string',
            'nullable' => false,
            'unique' => false,
            'index' => false,
            'default' => null,
            'foreignModel' => null,
            'precision' => null,
            'scale' => null,
        ], $overrides);
    }

    // ── replacePlaceholders ────────────────────────────────────────────

    public function test_replace_placeholders(): void
    {
        $this->assertSame('Hello John', $this->invoke('replacePlaceholders', ['Hello {{ name }}', ['name' => 'John']]));
        $this->assertSame('X and Y', $this->invoke('replacePlaceholders', ['{{ a }} and {{ b }}', ['a' => 'X', 'b' => 'Y']]));
        $this->assertSame('val val', $this->invoke('replacePlaceholders', ['{{ x }} {{ x }}', ['x' => 'val']]));
        $this->assertSame('{{ key }}', $this->invoke('replacePlaceholders', ['{{ key }}', []]));
    }

    // ── arrayToPhpString / assocArrayToPhpString ───────────────────────

    public function test_array_to_php_string(): void
    {
        $this->assertSame('[]', $this->invoke('arrayToPhpString', [[]]));
        $out = $this->invoke('arrayToPhpString', [['a', 'b'], 8]);
        $this->assertStringContainsString("'a',", $out);
        $this->assertStringContainsString("'b',", $out);
        $this->assertStringStartsWith('[', $out);
    }

    public function test_assoc_array_to_php_string(): void
    {
        $this->assertSame('[]', $this->invoke('assocArrayToPhpString', [[]]));
        $out = $this->invoke('assocArrayToPhpString', [['name' => 'John', 'age' => '30'], 8]);
        $this->assertStringContainsString("'name' => 'John',", $out);
        $this->assertStringContainsString("'age' => '30',", $out);
    }

    // ── columnToValidationRule ─────────────────────────────────────────

    public function test_column_to_validation_rule(): void
    {
        $this->assertSame('required|string|max:255', $this->invoke('columnToValidationRule', [$this->col(['name' => 'title']), 'posts']));
        $this->assertSame('nullable|uuid', $this->invoke('columnToValidationRule', [$this->col(['name' => 'ref', 'type' => 'uuid', 'nullable' => true]), 'posts']));
        $this->assertSame('required|unique:posts,slug|string|max:255', $this->invoke('columnToValidationRule', [$this->col(['name' => 'slug', 'unique' => true]), 'posts']));
        $this->assertSame('required|integer', $this->invoke('columnToValidationRule', [$this->col(['name' => 'n', 'type' => 'integer']), 'posts']));
        $this->assertSame('required|boolean', $this->invoke('columnToValidationRule', [$this->col(['name' => 'b', 'type' => 'boolean']), 'posts']));
        $this->assertSame('nullable|numeric', $this->invoke('columnToValidationRule', [$this->col(['name' => 'p', 'type' => 'decimal', 'nullable' => true]), 'posts']));
        $this->assertSame('required|array', $this->invoke('columnToValidationRule', [$this->col(['name' => 'meta', 'type' => 'json']), 'posts']));
        $this->assertSame('required|integer|exists:users,id', $this->invoke('columnToValidationRule', [$this->col(['name' => 'user_id', 'type' => 'foreignId', 'foreignModel' => 'User']), 'posts']));
        $this->assertSame('required|date', $this->invoke('columnToValidationRule', [$this->col(['name' => 'd', 'type' => 'datetime']), 'posts']));
    }

    // ── columnToMigrationLine ──────────────────────────────────────────

    public function test_column_to_migration_line(): void
    {
        $this->assertSame("\$table->string('title');", $this->invoke('columnToMigrationLine', [$this->col(['name' => 'title'])]));
        $this->assertSame(
            "\$table->string('email')->unique()->default('x@y.z')->index();",
            $this->invoke('columnToMigrationLine', [$this->col(['name' => 'email', 'unique' => true, 'index' => true, 'default' => 'x@y.z'])])
        );
        $this->assertSame("\$table->decimal('price', 8, 2);", $this->invoke('columnToMigrationLine', [$this->col(['name' => 'price', 'type' => 'decimal'])]));
        $this->assertSame("\$table->decimal('amt', 12, 4);", $this->invoke('columnToMigrationLine', [$this->col(['name' => 'amt', 'type' => 'decimal', 'precision' => 12, 'scale' => 4])]));
        $this->assertSame(
            "\$table->foreignId('user_id')->constrained('users')->cascadeOnDelete();",
            $this->invoke('columnToMigrationLine', [$this->col(['name' => 'user_id', 'type' => 'foreignId', 'foreignModel' => 'User'])])
        );
        $this->assertSame(
            "\$table->foreignId('org_id')->nullable()->constrained('organizations')->cascadeOnDelete();",
            $this->invoke('columnToMigrationLine', [$this->col(['name' => 'org_id', 'type' => 'foreignId', 'foreignModel' => 'Organization', 'nullable' => true])])
        );
        $this->assertSame(
            "\$table->foreignId('thing_id')->constrained()->cascadeOnDelete();",
            $this->invoke('columnToMigrationLine', [$this->col(['name' => 'thing_id', 'type' => 'foreignId'])])
        );
    }

    // ── formatDefaultValue ─────────────────────────────────────────────

    public function test_format_default_value(): void
    {
        $this->assertSame('100', $this->invoke('formatDefaultValue', ['100', 'integer']));
        $this->assertSame('3.14', $this->invoke('formatDefaultValue', ['3.14', 'decimal']));
        $this->assertSame('true', $this->invoke('formatDefaultValue', ['1', 'boolean']));
        $this->assertSame('true', $this->invoke('formatDefaultValue', ['true', 'boolean']));
        $this->assertSame('false', $this->invoke('formatDefaultValue', ['0', 'boolean']));
        $this->assertSame("'hello'", $this->invoke('formatDefaultValue', ['hello', 'string']));
        $this->assertSame("'it\\'s'", $this->invoke('formatDefaultValue', ["it's", 'string']));
    }

    // ── columnToFakerValue / nameBasedFakerValue ───────────────────────

    public function test_column_to_faker_value_by_name_and_type(): void
    {
        $this->assertSame('fake()->safeEmail()', $this->invoke('columnToFakerValue', [$this->col(['name' => 'email'])]));
        $this->assertSame('fake()->optional()->safeEmail()', $this->invoke('columnToFakerValue', [$this->col(['name' => 'email', 'nullable' => true])]));
        $this->assertSame('fake()->boolean()', $this->invoke('columnToFakerValue', [$this->col(['name' => 'is_active', 'type' => 'boolean'])]));
        $this->assertSame('\\App\\Models\\User::factory()', $this->invoke('columnToFakerValue', [$this->col(['name' => 'user_id', 'type' => 'foreignId', 'foreignModel' => 'User'])]));
        $this->assertSame('fake()->numberBetween(1, 10)', $this->invoke('columnToFakerValue', [$this->col(['name' => 'ref_id', 'type' => 'foreignId'])]));
        $this->assertSame('[]', $this->invoke('columnToFakerValue', [$this->col(['name' => 'meta', 'type' => 'json', 'nullable' => true])]));
        $this->assertSame('fake()->uuid()', $this->invoke('columnToFakerValue', [$this->col(['name' => 'token', 'type' => 'uuid'])]));
        $this->assertSame('fake()->word()', $this->invoke('columnToFakerValue', [$this->col(['name' => 'misc', 'type' => 'custom'])]));
        $this->assertSame('fake()->optional()->paragraph()', $this->invoke('columnToFakerValue', [$this->col(['name' => 'notes', 'type' => 'text', 'nullable' => true])]));
    }

    public function test_name_based_faker_value(): void
    {
        $this->assertSame('name()', $this->invoke('nameBasedFakerValue', ['full_name']));
        $this->assertSame('firstName()', $this->invoke('nameBasedFakerValue', ['first_name']));
        $this->assertSame('postcode()', $this->invoke('nameBasedFakerValue', ['zip_code']));
        $this->assertSame('paragraph()', $this->invoke('nameBasedFakerValue', ['description']));
        $this->assertSame('randomFloat(2, 1, 1000)', $this->invoke('nameBasedFakerValue', ['price']));
        $this->assertSame('boolean()', $this->invoke('nameBasedFakerValue', ['is_published']));
        $this->assertNull($this->invoke('nameBasedFakerValue', ['unknown_field']));
    }

    // ── buildRelationshipMethods ───────────────────────────────────────

    public function test_build_relationship_methods_from_columns(): void
    {
        $out = $this->invoke('buildRelationshipMethods', [[$this->col(['name' => 'user_id', 'type' => 'foreignId', 'foreignModel' => 'User'])], [], false]);
        $this->assertStringContainsString('public function user()', $out);
        $this->assertStringContainsString('belongsTo(User::class)', $out);
    }

    public function test_build_relationship_methods_filters_org_when_belongs_to_org(): void
    {
        $out = $this->invoke('buildRelationshipMethods', [[$this->col(['name' => 'organization_id', 'type' => 'foreignId', 'foreignModel' => 'Organization'])], [], true]);
        $this->assertSame('', $out);
    }

    public function test_build_relationship_methods_from_explicit_relationships(): void
    {
        $out = $this->invoke('buildRelationshipMethods', [[], [['type' => 'hasMany', 'model' => 'Post']], false]);
        $this->assertStringContainsString('public function posts()', $out);
        $this->assertStringContainsString('hasMany(Post::class)', $out);

        $out2 = $this->invoke('buildRelationshipMethods', [[], [['type' => 'belongsToMany', 'model' => 'Tag', 'foreign_key' => 'item_tag']], false]);
        $this->assertStringContainsString('belongsToMany(Tag::class, \'item_tag\')', $out2);
    }
}
