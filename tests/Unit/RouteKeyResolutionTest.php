<?php

namespace Rhino\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Rhino\Facades\Rhino;
use Rhino\Support\RhinoManager;
use Rhino\Tests\TestCase;

// --------------------------------------------------------------------------
// Test Models
// --------------------------------------------------------------------------

class RkResDefaultModel extends Model
{
    protected $table = 'rk_res_models';
}

class RkResStaticModel extends Model
{
    protected $table = 'rk_res_models';

    public static string $routeKey = 'hash_id';
}

class RkResEmptyStaticModel extends Model
{
    protected $table = 'rk_res_models';

    public static $routeKey = '';
}

class RkResNullStaticModel extends Model
{
    protected $table = 'rk_res_models';

    public static $routeKey = null;
}

/** $routeKey declared as an INSTANCE property must be ignored (contract is static). */
class RkResInstancePropModel extends Model
{
    protected $table = 'rk_res_models';

    public $routeKey = 'not-static';
}

class RkResCustomPkModel extends Model
{
    protected $table = 'rk_res_models';
    protected $primaryKey = 'uuid';
}

// --------------------------------------------------------------------------
// Tests
// --------------------------------------------------------------------------

class RouteKeyResolutionTest extends TestCase
{
    protected function manager(): RhinoManager
    {
        return app(RhinoManager::class);
    }

    public function test_default_resolves_to_eloquent_route_key_name(): void
    {
        $this->assertNull(config('rhino.route_key'));
        $this->assertSame('id', $this->manager()->routeKeyName(new RkResDefaultModel()));
    }

    public function test_model_static_route_key_wins(): void
    {
        $this->assertSame('hash_id', $this->manager()->routeKeyName(new RkResStaticModel()));
    }

    public function test_accepts_class_string_input(): void
    {
        $this->assertSame('hash_id', $this->manager()->routeKeyName(RkResStaticModel::class));
        $this->assertSame('id', $this->manager()->routeKeyName(RkResDefaultModel::class));
    }

    public function test_config_route_key_applies_when_model_has_no_static(): void
    {
        config(['rhino.route_key' => 'hash_id']);

        $this->assertSame('hash_id', $this->manager()->routeKeyName(new RkResDefaultModel()));
    }

    public function test_model_static_beats_config(): void
    {
        config(['rhino.route_key' => 'slug']);

        $this->assertSame('hash_id', $this->manager()->routeKeyName(new RkResStaticModel()));
    }

    public function test_config_null_falls_through_to_default(): void
    {
        config(['rhino.route_key' => null]);

        $this->assertSame('id', $this->manager()->routeKeyName(new RkResDefaultModel()));
    }

    public function test_config_id_falls_through_to_default(): void
    {
        config(['rhino.route_key' => 'id']);

        $this->assertSame('id', $this->manager()->routeKeyName(new RkResDefaultModel()));
    }

    public function test_config_empty_string_falls_through_to_default(): void
    {
        config(['rhino.route_key' => '']);

        $this->assertSame('id', $this->manager()->routeKeyName(new RkResDefaultModel()));
    }

    public function test_empty_static_falls_through_to_config(): void
    {
        config(['rhino.route_key' => 'hash_id']);

        $this->assertSame('hash_id', $this->manager()->routeKeyName(new RkResEmptyStaticModel()));
    }

    public function test_null_static_falls_through_to_config(): void
    {
        config(['rhino.route_key' => 'hash_id']);

        $this->assertSame('hash_id', $this->manager()->routeKeyName(new RkResNullStaticModel()));
    }

    public function test_empty_static_without_config_falls_through_to_default(): void
    {
        $this->assertSame('id', $this->manager()->routeKeyName(new RkResEmptyStaticModel()));
    }

    public function test_instance_route_key_property_is_ignored(): void
    {
        $this->assertSame('id', $this->manager()->routeKeyName(new RkResInstancePropModel()));
    }

    public function test_default_respects_custom_primary_key_via_get_route_key_name(): void
    {
        $this->assertSame('uuid', $this->manager()->routeKeyName(new RkResCustomPkModel()));
        $this->assertSame('uuid', $this->manager()->routeKeyName(RkResCustomPkModel::class));
    }

    public function test_facade_exposes_route_key_name(): void
    {
        $this->assertSame('hash_id', Rhino::routeKeyName(RkResStaticModel::class));
        $this->assertSame('id', Rhino::routeKeyName(RkResDefaultModel::class));
    }
}
