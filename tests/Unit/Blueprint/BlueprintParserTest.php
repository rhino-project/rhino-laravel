<?php

namespace Rhino\Tests\Unit\Blueprint;

use Rhino\Blueprint\BlueprintParser;
use PHPUnit\Framework\TestCase;

class BlueprintParserTest extends TestCase
{
    protected BlueprintParser $parser;
    protected string $fixturesDir;

    protected function setUp(): void
    {
        parent::setUp();
        $this->parser = new BlueprintParser();
        $this->fixturesDir = __DIR__ . '/fixtures';

        // Create fixtures directory
        if (!is_dir($this->fixturesDir)) {
            mkdir($this->fixturesDir, 0755, true);
        }
    }

    protected function tearDown(): void
    {
        // Clean up fixture files
        $files = glob($this->fixturesDir . '/*.yaml');
        foreach ($files as $file) {
            unlink($file);
        }

        if (is_dir($this->fixturesDir)) {
            rmdir($this->fixturesDir);
        }

        parent::tearDown();
    }

    protected function createFixture(string $filename, string $content): string
    {
        $path = $this->fixturesDir . '/' . $filename;
        file_put_contents($path, $content);
        return $path;
    }

    // ---------------------------------------------------------------
    // parseRoles() tests
    // ---------------------------------------------------------------

    public function test_parses_valid_roles_file(): void
    {
        $path = $this->createFixture('_roles.yaml', <<<YAML
roles:
  owner:
    name: Owner
    description: "Full access owner"
  admin:
    name: Admin
    description: "Operational administrator"
  viewer:
    name: Viewer
    description: "Read-only access"
YAML);

        $roles = $this->parser->parseRoles($path);

        $this->assertCount(3, $roles);
        $this->assertArrayHasKey('owner', $roles);
        $this->assertArrayHasKey('admin', $roles);
        $this->assertArrayHasKey('viewer', $roles);

        $this->assertEquals('Owner', $roles['owner']['name']);
        $this->assertEquals('Full access owner', $roles['owner']['description']);
        $this->assertEquals('Admin', $roles['admin']['name']);
    }

    public function test_throws_on_missing_roles_key(): void
    {
        $path = $this->createFixture('_roles.yaml', <<<YAML
something_else:
  admin:
    name: Admin
YAML);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("missing 'roles' key");

        $this->parser->parseRoles($path);
    }

    public function test_throws_on_nonexistent_file(): void
    {
        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('not found');

        $this->parser->parseRoles('/nonexistent/path/_roles.yaml');
    }

    public function test_throws_on_empty_file(): void
    {
        $path = $this->createFixture('_roles.yaml', '');

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('empty');

        $this->parser->parseRoles($path);
    }

    public function test_throws_on_invalid_yaml_syntax(): void
    {
        $path = $this->createFixture('_roles.yaml', <<<YAML
roles:
  admin:
    name: Admin
    description: "missing close quote
    this: is broken [[[
YAML);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage('Invalid YAML');

        $this->parser->parseRoles($path);
    }

    public function test_defaults_role_name_from_slug(): void
    {
        $path = $this->createFixture('_roles.yaml', <<<YAML
roles:
  admin:
    description: "Administrator"
YAML);

        $roles = $this->parser->parseRoles($path);

        $this->assertEquals('Admin', $roles['admin']['name']);
    }

    // ---------------------------------------------------------------
    // parseModel() tests
    // ---------------------------------------------------------------

    public function test_parses_minimal_model_blueprint(): void
    {
        $path = $this->createFixture('posts.yaml', <<<YAML
model: Post

columns:
  title:
    type: string
    nullable: false
YAML);

        $blueprint = $this->parser->parseModel($path);

        $this->assertEquals('Post', $blueprint['model']);
        $this->assertEquals('posts', $blueprint['slug']);
        $this->assertEquals('posts', $blueprint['table']);
        $this->assertCount(1, $blueprint['columns']);
        $this->assertEquals('title', $blueprint['columns'][0]['name']);
        $this->assertEquals('string', $blueprint['columns'][0]['type']);
        $this->assertFalse($blueprint['columns'][0]['nullable']);
    }

    public function test_auto_derives_slug_from_model_name(): void
    {
        $path = $this->createFixture('blog_posts.yaml', <<<YAML
model: BlogPost

columns:
  title:
    type: string
YAML);

        $blueprint = $this->parser->parseModel($path);

        $this->assertEquals('BlogPost', $blueprint['model']);
        $this->assertEquals('blog_posts', $blueprint['slug']);
    }

    public function test_respects_explicit_slug(): void
    {
        $path = $this->createFixture('posts.yaml', <<<YAML
model: Post
slug: custom_posts

columns:
  title:
    type: string
YAML);

        $blueprint = $this->parser->parseModel($path);

        $this->assertEquals('custom_posts', $blueprint['slug']);
    }

    public function test_parses_full_model_with_permissions(): void
    {
        $path = $this->createFixture('contracts.yaml', <<<YAML
model: Contract
slug: contracts

options:
  belongs_to_organization: true
  soft_deletes: true
  audit_trail: true

columns:
  title:
    type: string
    nullable: false
    filterable: true
    sortable: true
  total_value:
    type: decimal
    nullable: true
    precision: 10
    scale: 2
  status:
    type: string
    nullable: false
    default: "draft"
  uploaded_by:
    type: foreignId
    foreign_model: User

relationships:
  - type: belongsTo
    model: User
    foreign_key: uploaded_by

permissions:
  owner:
    actions: [index, show, store, update, destroy]
    show_fields: "*"
    create_fields: "*"
    update_fields: "*"
  viewer:
    actions: [index, show]
    show_fields:
      - id
      - title
      - status
    create_fields: []
    update_fields: []
    hidden_fields:
      - total_value
YAML);

        $blueprint = $this->parser->parseModel($path);

        // Model & slug
        $this->assertEquals('Contract', $blueprint['model']);
        $this->assertEquals('contracts', $blueprint['slug']);

        // Options
        $this->assertTrue($blueprint['options']['belongs_to_organization']);
        $this->assertTrue($blueprint['options']['soft_deletes']);
        $this->assertTrue($blueprint['options']['audit_trail']);

        // Columns
        $this->assertCount(4, $blueprint['columns']);
        $this->assertEquals('title', $blueprint['columns'][0]['name']);
        $this->assertTrue($blueprint['columns'][0]['filterable']);
        $this->assertEquals('decimal', $blueprint['columns'][1]['type']);
        $this->assertEquals('User', $blueprint['columns'][3]['foreignModel']);

        // Relationships
        $this->assertCount(1, $blueprint['relationships']);
        $this->assertEquals('belongsTo', $blueprint['relationships'][0]['type']);

        // Permissions
        $this->assertArrayHasKey('owner', $blueprint['permissions']);
        $this->assertArrayHasKey('viewer', $blueprint['permissions']);

        // Owner has wildcard
        $this->assertEquals(['*'], $blueprint['permissions']['owner']['show_fields']);
        $this->assertEquals(['*'], $blueprint['permissions']['owner']['create_fields']);

        // Viewer has restricted fields
        $this->assertEquals(['id', 'title', 'status'], $blueprint['permissions']['viewer']['show_fields']);
        $this->assertEquals([], $blueprint['permissions']['viewer']['create_fields']);
        $this->assertEquals(['total_value'], $blueprint['permissions']['viewer']['hidden_fields']);
    }

    public function test_normalizes_options_with_defaults(): void
    {
        $path = $this->createFixture('posts.yaml', <<<YAML
model: Post

columns:
  title:
    type: string
YAML);

        $blueprint = $this->parser->parseModel($path);

        $this->assertFalse($blueprint['options']['belongs_to_organization']);
        $this->assertTrue($blueprint['options']['soft_deletes']); // default true
        $this->assertFalse($blueprint['options']['audit_trail']);
        $this->assertNull($blueprint['options']['owner']);
        $this->assertEquals([], $blueprint['options']['except_actions']);
    }

    public function test_normalizes_column_defaults(): void
    {
        $path = $this->createFixture('posts.yaml', <<<YAML
model: Post

columns:
  title:
    type: string
YAML);

        $blueprint = $this->parser->parseModel($path);
        $col = $blueprint['columns'][0];

        $this->assertFalse($col['nullable']);
        $this->assertFalse($col['unique']);
        $this->assertFalse($col['index']);
        $this->assertNull($col['default']);
        $this->assertFalse($col['filterable']);
        $this->assertFalse($col['sortable']);
        $this->assertFalse($col['searchable']);
        $this->assertNull($col['foreignModel']);
    }

    public function test_throws_on_missing_model_key(): void
    {
        $path = $this->createFixture('posts.yaml', <<<YAML
slug: posts
columns:
  title:
    type: string
YAML);

        $this->expectException(\RuntimeException::class);
        $this->expectExceptionMessage("missing 'model' key");

        $this->parser->parseModel($path);
    }

    public function test_handles_wildcard_show_fields(): void
    {
        $path = $this->createFixture('posts.yaml', <<<YAML
model: Post

columns:
  title:
    type: string

permissions:
  admin:
    actions: [index, show]
    show_fields: "*"
YAML);

        $blueprint = $this->parser->parseModel($path);

        $this->assertEquals(['*'], $blueprint['permissions']['admin']['show_fields']);
    }

    public function test_handles_empty_create_fields(): void
    {
        $path = $this->createFixture('posts.yaml', <<<YAML
model: Post

columns:
  title:
    type: string

permissions:
  viewer:
    actions: [index, show]
    create_fields: []
    update_fields: []
YAML);

        $blueprint = $this->parser->parseModel($path);

        $this->assertEquals([], $blueprint['permissions']['viewer']['create_fields']);
        $this->assertEquals([], $blueprint['permissions']['viewer']['update_fields']);
    }

    // ---------------------------------------------------------------
    // computeFileHash() tests
    // ---------------------------------------------------------------

    public function test_computes_consistent_file_hash(): void
    {
        $path = $this->createFixture('test.yaml', "model: Test\n");

        $hash1 = $this->parser->computeFileHash($path);
        $hash2 = $this->parser->computeFileHash($path);

        $this->assertEquals($hash1, $hash2);
        $this->assertNotEmpty($hash1);
        $this->assertEquals(64, strlen($hash1)); // SHA-256 = 64 hex chars
    }

    public function test_hash_changes_when_content_changes(): void
    {
        $path = $this->createFixture('test.yaml', "model: Test\n");
        $hash1 = $this->parser->computeFileHash($path);

        file_put_contents($path, "model: TestChanged\n");
        $hash2 = $this->parser->computeFileHash($path);

        $this->assertNotEquals($hash1, $hash2);
    }
}
