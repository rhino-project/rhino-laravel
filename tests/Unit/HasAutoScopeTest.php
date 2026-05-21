<?php

namespace Rhino\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rhino\Tests\TestCase;
use Rhino\Traits\HasAutoScope;

class AutoScopeTestModel extends Model
{
    use HasAutoScope;

    protected $table = 'auto_scope_items';
    protected $fillable = ['name'];
}

class HasAutoScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('auto_scope_items', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function test_boot_does_not_fail_when_no_scope_class_exists(): void
    {
        // The AutoScopeTestModel has no matching App\Models\Scopes\AutoScopeTestModelScope class
        // Boot should not throw
        $model = new AutoScopeTestModel();
        $this->assertInstanceOf(AutoScopeTestModel::class, $model);
    }

    public function test_model_can_be_created_with_auto_scope(): void
    {
        $model = AutoScopeTestModel::forceCreate(['name' => 'Test']);
        $this->assertNotNull($model->id);
        $this->assertEquals('Test', $model->name);
    }

    public function test_model_can_be_queried_with_auto_scope(): void
    {
        AutoScopeTestModel::forceCreate(['name' => 'Item A']);
        AutoScopeTestModel::forceCreate(['name' => 'Item B']);

        $items = AutoScopeTestModel::all();
        $this->assertCount(2, $items);
    }
}
