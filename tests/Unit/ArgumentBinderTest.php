<?php

namespace Rhino\Tests\Unit;

use Rhino\Exceptions\InvalidComputedAttributeArguments;
use Rhino\Exceptions\InvalidScopeArguments;
use Rhino\Support\ArgumentBinder;
use Rhino\Support\ComputedAttributeSpec;
use Rhino\Support\ScopeSpec;
use Rhino\Tests\TestCase;

/**
 * The binder is shared by named scopes and computed attributes. These tests
 * pin BOTH subjects: the algorithm must behave identically, and each feature
 * must keep its own error wording and its own exception type.
 */
class ArgumentBinderTest extends TestCase
{
    private function spec(array $params, array $optional = []): array
    {
        return ['params' => $params, 'optional' => $optional];
    }

    private function bindComputed(string $name, array $spec, $raw): array
    {
        return ComputedAttributeSpec::bind($name, $spec, $raw);
    }

    // ------------------------------------------------------------------
    // Happy paths
    // ------------------------------------------------------------------

    public function test_empty_raw_binds_no_arguments(): void
    {
        $this->assertSame([], $this->bindComputed('headcount', $this->spec([]), ''));
        $this->assertSame([], $this->bindComputed('headcount', $this->spec([]), null));
    }

    public function test_bare_value_binds_to_the_single_declared_parameter(): void
    {
        $this->assertSame(['2026-01-01'], $this->bindComputed('since', $this->spec(['from']), '2026-01-01'));
    }

    public function test_named_arguments_bind_in_declared_order(): void
    {
        $this->assertSame(
            ['a', 'b'],
            $this->bindComputed('revenue', $this->spec(['from', 'to']), ['to' => 'b', 'from' => 'a'])
        );
    }

    public function test_omitted_optional_parameter_drops_off_the_end(): void
    {
        $this->assertSame(
            ['a'],
            $this->bindComputed('revenue', $this->spec(['from', 'to'], ['to']), ['from' => 'a'])
        );
    }

    public function test_omitted_middle_optional_parameter_is_passed_as_null(): void
    {
        $this->assertSame(
            ['a', null, 'c'],
            $this->bindComputed('window', $this->spec(['from', 'mid', 'to'], ['mid']), ['from' => 'a', 'to' => 'c'])
        );
    }

    public function test_true_and_false_coerce_to_real_booleans(): void
    {
        $this->assertSame([true], $this->bindComputed('flagged', $this->spec(['on']), 'true'));
        $this->assertSame([false], $this->bindComputed('flagged', $this->spec(['on']), 'FALSE'));
        $this->assertSame(['yes'], $this->bindComputed('flagged', $this->spec(['on']), 'yes'));
    }

    public function test_coercion_never_applies_to_names(): void
    {
        // 'true' as a PARAMETER name stays a string key, and binds normally.
        $this->assertSame(['x'], $this->bindComputed('weird', $this->spec(['true']), ['true' => 'x']));
    }

    public function test_coerce_leaves_non_strings_alone(): void
    {
        $this->assertSame(3, ArgumentBinder::coerce(3));
        $this->assertNull(ArgumentBinder::coerce(null));
    }

    // ------------------------------------------------------------------
    // The four argument errors, under the Computed attribute subject
    // ------------------------------------------------------------------

    public function test_missing_required_parameter_is_named(): void
    {
        $this->expectException(InvalidComputedAttributeArguments::class);
        $this->expectExceptionMessage("Computed attribute 'revenue' requires parameter 'to'");

        $this->bindComputed('revenue', $this->spec(['from', 'to']), ['from' => 'a']);
    }

    public function test_unknown_parameter_is_named(): void
    {
        $this->expectException(InvalidComputedAttributeArguments::class);
        $this->expectExceptionMessage("Computed attribute 'revenue' does not accept parameter 'nope'");

        $this->bindComputed('revenue', $this->spec(['from', 'to']), ['from' => 'a', 'to' => 'b', 'nope' => 'x']);
    }

    public function test_bare_value_for_a_multi_parameter_attribute_requires_named_parameters(): void
    {
        $this->expectException(InvalidComputedAttributeArguments::class);
        $this->expectExceptionMessage("Computed attribute 'revenue' requires named parameters");

        $this->bindComputed('revenue', $this->spec(['from', 'to']), '2026-01-01');
    }

    public function test_positional_list_requires_named_parameters(): void
    {
        $this->expectException(InvalidComputedAttributeArguments::class);
        $this->expectExceptionMessage("Computed attribute 'revenue' requires named parameters");

        $this->bindComputed('revenue', $this->spec(['from', 'to']), ['a', 'b']);
    }

    public function test_non_scalar_argument_value_requires_named_parameters(): void
    {
        $this->expectException(InvalidComputedAttributeArguments::class);
        $this->expectExceptionMessage("Computed attribute 'revenue' requires named parameters");

        $this->bindComputed('revenue', $this->spec(['from', 'to']), ['from' => ['deep' => 1], 'to' => 'b']);
    }

    public function test_arguments_sent_to_a_parameterless_attribute_are_refused(): void
    {
        $this->expectException(InvalidComputedAttributeArguments::class);
        $this->expectExceptionMessage("Computed attribute 'headcount' does not accept arguments");

        $this->bindComputed('headcount', $this->spec([]), '5');
    }

    public function test_named_arguments_sent_to_a_parameterless_attribute_are_refused(): void
    {
        $this->expectException(InvalidComputedAttributeArguments::class);
        $this->expectExceptionMessage("Computed attribute 'headcount' does not accept arguments");

        $this->bindComputed('headcount', $this->spec([]), ['from' => 'a']);
    }

    // ------------------------------------------------------------------
    // The scope subject is unchanged by the extraction
    // ------------------------------------------------------------------

    public function test_scope_messages_still_say_scope(): void
    {
        $cases = [
            [['params' => ['from', 'to'], 'optional' => []], ['from' => 'a'], "Scope 'window' requires parameter 'to'"],
            [['params' => ['from'], 'optional' => []], ['nope' => 'a'], "Scope 'window' does not accept parameter 'nope'"],
            [['params' => ['from', 'to'], 'optional' => []], 'a', "Scope 'window' requires named parameters"],
            [['params' => [], 'optional' => []], 'a', "Scope 'window' does not accept arguments"],
        ];

        foreach ($cases as [$spec, $raw, $message]) {
            try {
                ScopeSpec::bind('window', $spec, $raw);
                $this->fail("Expected InvalidScopeArguments for: {$message}");
            } catch (InvalidScopeArguments $e) {
                $this->assertSame($message, $e->getMessage());
            }
        }
    }

    public function test_scope_and_computed_subjects_raise_different_exception_types(): void
    {
        try {
            ScopeSpec::bind('window', ['params' => [], 'optional' => []], 'a');
            $this->fail('Expected InvalidScopeArguments');
        } catch (InvalidScopeArguments $e) {
            $this->assertNotInstanceOf(InvalidComputedAttributeArguments::class, $e);
        }

        try {
            ComputedAttributeSpec::bind('window', ['params' => [], 'optional' => []], 'a');
            $this->fail('Expected InvalidComputedAttributeArguments');
        } catch (InvalidComputedAttributeArguments $e) {
            $this->assertNotInstanceOf(InvalidScopeArguments::class, $e);
        }
    }

    // ------------------------------------------------------------------
    // normalizeParams
    // ------------------------------------------------------------------

    public function test_normalize_params_stringifies_and_prunes_optional(): void
    {
        $this->assertSame(
            ['params' => ['from', 'to'], 'optional' => ['to']],
            ArgumentBinder::normalizeParams(['from', 'to'], ['to', 'ghost'])
        );
    }

    public function test_normalize_params_tolerates_nulls(): void
    {
        $this->assertSame(
            ['params' => [], 'optional' => []],
            ArgumentBinder::normalizeParams(null, null)
        );
    }
}
