<?php

namespace Rhino\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rhino\Tests\TestCase;
use Rhino\Traits\HasUuid;

class HasUuidTestModel extends Model
{
    use HasUuid;

    protected $table = 'has_uuid_items';
    protected $fillable = ['name', 'uuid'];
    public $timestamps = true;
}

class HasUuidTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        Schema::create('has_uuid_items', function (Blueprint $table) {
            $table->id();
            $table->string('uuid')->nullable();
            $table->string('name');
            $table->timestamps();
        });
    }

    public function test_generates_a_uuid_on_create(): void
    {
        $model = HasUuidTestModel::forceCreate(['name' => 'a']);
        $this->assertMatchesRegularExpression(
            '/^[0-9a-f]{8}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{4}-[0-9a-f]{12}$/i',
            $model->uuid
        );
    }

    public function test_preserves_a_preset_uuid(): void
    {
        $model = HasUuidTestModel::forceCreate(['name' => 'a', 'uuid' => 'preset-uuid-value']);
        $this->assertSame('preset-uuid-value', $model->uuid);
    }

    public function test_does_not_change_the_uuid_on_update(): void
    {
        $model = HasUuidTestModel::forceCreate(['name' => 'a']);
        $original = $model->uuid;
        $model->update(['name' => 'b']);
        $this->assertSame($original, $model->fresh()->uuid);
    }

    public function test_generates_distinct_uuids(): void
    {
        $a = HasUuidTestModel::forceCreate(['name' => 'a']);
        $b = HasUuidTestModel::forceCreate(['name' => 'b']);
        $this->assertNotSame($a->uuid, $b->uuid);
    }
}
