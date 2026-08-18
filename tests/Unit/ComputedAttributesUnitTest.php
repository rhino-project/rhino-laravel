<?php

namespace Rhino\Tests\Unit;

use Illuminate\Database\Eloquent\Model;
use Rhino\Tests\TestCase;
use Rhino\Traits\HidableColumns;

class UnitComputedModel extends Model
{
    use HidableColumns;

    protected $table = 'unit_computed';

    protected $guarded = [];

    public $timestamps = false;

    public function rhinoComputedAttributes(): array
    {
        return ['legacy' => 'always'];
    }

    public function rhinoRecordComputedAttributes(): array
    {
        return [
            'shout' => fn ($record, $user) => strtoupper((string) $record->name),
            'viewer' => fn ($record, $user) => $user,
            'literal' => 'not-a-callable',
        ];
    }
}

class UnitPlainModel extends Model
{
    use HidableColumns;

    protected $table = 'unit_computed';

    protected $guarded = [];

    public $timestamps = false;
}

class ComputedAttributesUnitTest extends TestCase
{
    private function model(): UnitComputedModel
    {
        $model = new UnitComputedModel();
        $model->id = 1;
        $model->name = 'ada';
        $model->exists = true;

        return $model;
    }

    public function test_defaults_are_empty_on_a_model_that_declares_nothing(): void
    {
        $plain = new UnitPlainModel();

        $this->assertSame([], $plain->rhinoComputedAttributes());
        $this->assertSame([], $plain->rhinoRecordComputedAttributes());
        $this->assertSame([], UnitPlainModel::rhinoCollectionComputedAttributes());
    }

    public function test_legacy_single_argument_call_is_unchanged(): void
    {
        $result = $this->model()->asRhinoJson(null);

        $this->assertSame('always', $result['legacy']);
        $this->assertArrayNotHasKey('shout', $result);
    }

    public function test_no_argument_call_is_unchanged(): void
    {
        $result = $this->model()->asRhinoJson();

        $this->assertSame('always', $result['legacy']);
        $this->assertArrayNotHasKey('shout', $result);
    }

    public function test_requested_attribute_is_evaluated(): void
    {
        $result = $this->model()->asRhinoJson(null, ['shout']);

        $this->assertSame('ADA', $result['shout']);
    }

    public function test_unrequested_attributes_stay_absent(): void
    {
        $result = $this->model()->asRhinoJson(null, ['shout']);

        $this->assertArrayNotHasKey('viewer', $result);
        $this->assertArrayNotHasKey('literal', $result);
    }

    public function test_unknown_names_are_ignored_rather_than_invoked(): void
    {
        $result = $this->model()->asRhinoJson(null, ['nope', 'shout']);

        $this->assertArrayNotHasKey('nope', $result);
        $this->assertSame('ADA', $result['shout']);
    }

    public function test_non_string_names_are_ignored(): void
    {
        $result = $this->model()->asRhinoJson(null, [['array'], 42, 'shout']);

        $this->assertSame('ADA', $result['shout']);
        $this->assertCount(1, array_intersect_key($result, ['shout' => true]));
    }

    public function test_non_callable_declarations_are_returned_verbatim(): void
    {
        $result = $this->model()->asRhinoJson(null, ['literal']);

        $this->assertSame('not-a-callable', $result['literal']);
    }

    public function test_user_is_passed_through_to_the_callable(): void
    {
        $user = (object) ['id' => 42];

        $result = $this->model()->asRhinoJson($user, ['viewer']);

        $this->assertSame($user, $result['viewer']);
    }

    public function test_requesting_on_a_model_without_declarations_is_a_no_op(): void
    {
        $plain = new UnitPlainModel();
        $plain->id = 1;
        $plain->name = 'ada';

        $result = $plain->asRhinoJson(null, ['shout']);

        $this->assertArrayNotHasKey('shout', $result);
    }

    public function test_empty_selection_evaluates_nothing(): void
    {
        $result = $this->model()->asRhinoJson(null, []);

        $this->assertArrayNotHasKey('shout', $result);
        $this->assertArrayNotHasKey('viewer', $result);
    }
}
