<?php

namespace Rhino\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Scope;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\File;
use Illuminate\Support\Facades\Schema;
use Rhino\Scopes\ScopedDB;
use Rhino\Scopes\ScopedQueryBuilder;
use Rhino\Tests\TestCase;

// --------------------------------------------------------------------------
// Test Scope
// --------------------------------------------------------------------------

class TestActiveScope implements Scope
{
    public function apply($builder, $model): void
    {
        if ($builder instanceof \Illuminate\Database\Query\Builder) {
            $builder->where('active', true);
        } else {
            $builder->getQuery()->where('active', true);
        }
    }
}

// --------------------------------------------------------------------------
// Test Model for Scope
// --------------------------------------------------------------------------

class ScopedTestItem extends Model
{
    protected $table = 'scoped_items';
    protected $fillable = ['name', 'active'];
    public $timestamps = false;
}

class ScopedDBTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('scoped_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->boolean('active')->default(true);
        });
    }

    // ======================================================================
    // ScopedQueryBuilder
    // ======================================================================

    public function test_scoped_query_builder_applies_scope_on_construct(): void
    {
        ScopedTestItem::insert([
            ['name' => 'Active Item', 'active' => true],
            ['name' => 'Inactive Item', 'active' => false],
        ]);

        $scope = new TestActiveScope();
        $model = new ScopedTestItem();

        $builder = new ScopedQueryBuilder(
            $this->app['db']->connection(),
            null,
            null,
            $model,
            $scope
        );

        $builder->from('scoped_items');
        $results = $builder->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Active Item', $results[0]->name);
    }

    public function test_scoped_query_builder_works_without_scope(): void
    {
        ScopedTestItem::insert([
            ['name' => 'Item A', 'active' => true],
            ['name' => 'Item B', 'active' => false],
        ]);

        $model = new ScopedTestItem();

        $builder = new ScopedQueryBuilder(
            $this->app['db']->connection(),
            null,
            null,
            $model,
            null
        );

        $builder->from('scoped_items');
        $results = $builder->get();

        $this->assertCount(2, $results);
    }

    // ======================================================================
    // ScopedDB::table
    // ======================================================================

    public function test_scoped_db_table_throws_when_table_not_in_scoped_tables(): void
    {
        // Create an empty Scopes directory so getScopedTables returns empty array
        $scopesPath = app_path('Models/Scopes');
        File::ensureDirectoryExists($scopesPath);

        try {
            $this->expectException(\Exception::class);
            $this->expectExceptionMessage("Scoped table 'unknown_table' not found");
            ScopedDB::table('unknown_table');
        } finally {
            File::deleteDirectory(app_path('Models'));
        }
    }

    public function test_scoped_db_get_scoped_tables_throws_when_no_directory(): void
    {
        $this->expectException(\Exception::class);
        ScopedDB::getScopedTables();
    }

    public function test_scoped_db_get_scoped_tables_returns_empty_for_empty_directory(): void
    {
        $scopesPath = app_path('Models/Scopes');
        File::ensureDirectoryExists($scopesPath);

        $result = ScopedDB::getScopedTables();

        try {
            $result = ScopedDB::getScopedTables();
            $this->assertIsArray($result);
            $this->assertEmpty($result);
        } finally {
            File::deleteDirectory(app_path('Models'));
        }
    }

    // ======================================================================
    // ScopedQueryBuilder integration
    // ======================================================================

    public function test_scoped_query_builder_supports_where_clauses(): void
    {
        ScopedTestItem::insert([
            ['name' => 'Apple', 'active' => true],
            ['name' => 'Banana', 'active' => true],
            ['name' => 'Cherry', 'active' => false],
        ]);

        $scope = new TestActiveScope();
        $model = new ScopedTestItem();

        $builder = new ScopedQueryBuilder(
            $this->app['db']->connection(),
            null,
            null,
            $model,
            $scope
        );

        $builder->from('scoped_items')->where('name', 'Apple');
        $results = $builder->get();

        $this->assertCount(1, $results);
        $this->assertEquals('Apple', $results[0]->name);
    }

    public function test_scoped_query_builder_scope_filters_inactive_records(): void
    {
        ScopedTestItem::insert([
            ['name' => 'Active 1', 'active' => true],
            ['name' => 'Active 2', 'active' => true],
            ['name' => 'Inactive 1', 'active' => false],
            ['name' => 'Inactive 2', 'active' => false],
            ['name' => 'Inactive 3', 'active' => false],
        ]);

        $scope = new TestActiveScope();
        $model = new ScopedTestItem();

        $builder = new ScopedQueryBuilder(
            $this->app['db']->connection(),
            null,
            null,
            $model,
            $scope
        );

        $builder->from('scoped_items');
        $count = $builder->count();

        $this->assertEquals(2, $count);
    }
}
