<?php

namespace Rhino\Tests\Unit;

use Rhino\Support\ComputedAttributeSpec;
use Rhino\Tests\TestCase;

/**
 * The declaration-detection rule. A value is an extended spec if and only if it
 * is an array carrying params/optional/using. Everything else stays a LEGACY
 * declaration — that is what keeps `'version' => 3` and `'tags' => ['a','b']`
 * literal values rather than silently becoming parameter lists.
 */
class ComputedAttributeSpecTest extends TestCase
{
    public function test_a_callable_declaration_stays_legacy(): void
    {
        $fn = fn ($record, $user) => 1;

        $specs = ComputedAttributeSpec::normalize(['count' => $fn]);

        $this->assertSame(['params' => [], 'optional' => [], 'using' => $fn], $specs['count']);
    }

    public function test_a_scalar_literal_declaration_stays_legacy(): void
    {
        $specs = ComputedAttributeSpec::normalize(['version' => 3, 'label' => 'v3']);

        $this->assertSame([], $specs['version']['params']);
        $this->assertSame(3, $specs['version']['using']);
        $this->assertSame([], $specs['label']['params']);
        $this->assertSame('v3', $specs['label']['using']);
    }

    public function test_a_plain_list_declaration_stays_a_literal_and_is_not_a_parameter_list(): void
    {
        // This is the reason computed attributes deliberately have NO list
        // shorthand: `['a','b']` is a legal value today.
        $specs = ComputedAttributeSpec::normalize(['tags' => ['a', 'b']]);

        $this->assertSame([], $specs['tags']['params']);
        $this->assertSame(['a', 'b'], $specs['tags']['using']);
    }

    public function test_a_map_without_the_reserved_keys_stays_a_literal(): void
    {
        $specs = ComputedAttributeSpec::normalize(['meta' => ['color' => 'red', 'size' => 2]]);

        $this->assertSame([], $specs['meta']['params']);
        $this->assertSame(['color' => 'red', 'size' => 2], $specs['meta']['using']);
    }

    public function test_a_map_carrying_params_is_an_extended_spec(): void
    {
        $fn = fn ($query, $user, $from, $to) => 1;

        $specs = ComputedAttributeSpec::normalize([
            'revenue' => ['params' => ['from', 'to'], 'using' => $fn],
        ]);

        $this->assertSame(['from', 'to'], $specs['revenue']['params']);
        $this->assertSame([], $specs['revenue']['optional']);
        $this->assertSame($fn, $specs['revenue']['using']);
    }

    public function test_a_map_carrying_only_using_is_an_extended_spec_with_no_parameters(): void
    {
        $fn = fn ($query, $user) => 1;

        $specs = ComputedAttributeSpec::normalize(['count' => ['using' => $fn]]);

        $this->assertSame([], $specs['count']['params']);
        $this->assertSame($fn, $specs['count']['using']);
    }

    public function test_optional_entries_that_are_not_declared_parameters_are_discarded(): void
    {
        $specs = ComputedAttributeSpec::normalize([
            'revenue' => ['params' => ['from', 'to'], 'optional' => ['to', 'ghost']],
        ]);

        $this->assertSame(['to'], $specs['revenue']['optional']);
    }

    public function test_parameter_names_are_stringified(): void
    {
        $specs = ComputedAttributeSpec::normalize(['weird' => ['params' => [1, 2]]]);

        $this->assertSame(['1', '2'], $specs['weird']['params']);
    }

    public function test_numeric_keys_stay_attribute_names(): void
    {
        // Unlike scopes, a numeric key is NOT "a parameterless declaration named
        // by its value" — computed attributes are always name => value.
        $specs = ComputedAttributeSpec::normalize(['archived']);

        // PHP re-coerces the numeric string key back to an int on the way in.
        $this->assertSame([0], array_keys($specs));
        $this->assertSame('archived', $specs['0']['using']);
    }

    public function test_is_spec_detection(): void
    {
        $this->assertFalse(ComputedAttributeSpec::isSpec('x'));
        $this->assertFalse(ComputedAttributeSpec::isSpec(3));
        $this->assertFalse(ComputedAttributeSpec::isSpec(null));
        $this->assertFalse(ComputedAttributeSpec::isSpec(['a', 'b']));
        $this->assertFalse(ComputedAttributeSpec::isSpec(['color' => 'red']));
        $this->assertTrue(ComputedAttributeSpec::isSpec(['params' => []]));
        $this->assertTrue(ComputedAttributeSpec::isSpec(['optional' => []]));
        $this->assertTrue(ComputedAttributeSpec::isSpec(['using' => fn () => 1]));
    }

    public function test_names_lists_every_declaration(): void
    {
        $this->assertSame(
            ['a', 'b'],
            ComputedAttributeSpec::names(['a' => fn () => 1, 'b' => ['params' => ['x']]])
        );
    }

    public function test_requires_arguments_only_when_a_parameter_is_mandatory(): void
    {
        $this->assertFalse(ComputedAttributeSpec::requiresArguments(['params' => [], 'optional' => []]));
        $this->assertFalse(ComputedAttributeSpec::requiresArguments(['params' => ['a'], 'optional' => ['a']]));
        $this->assertTrue(ComputedAttributeSpec::requiresArguments(['params' => ['a'], 'optional' => []]));
        $this->assertTrue(ComputedAttributeSpec::requiresArguments(['params' => ['a', 'b'], 'optional' => ['b']]));
    }
}
