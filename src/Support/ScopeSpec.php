<?php

namespace Rhino\Support;

use Rhino\Exceptions\InvalidScopeArguments;

/**
 * Parses a model's `$allowedScopes` declaration and binds the arguments a
 * client sent for `?scope[name][param]=value` to the scope's parameters.
 *
 * Declaration forms (all may be mixed in one array):
 *
 *     public static $allowedScopes = [
 *         'archived',                                        // no parameters
 *         'since' => 'date',                                 // one parameter
 *         'between' => ['from', 'to'],                       // two, both required
 *         'window' => ['params' => ['from', 'to'],           // 'to' optional
 *                      'optional' => ['to']],
 *     ];
 *
 * A scope with no declared parameters never receives arguments: sending any
 * is a 403, which keeps client input out of scope bodies that were written
 * without it.
 */
class ScopeSpec
{
    /**
     * Normalize a raw `$allowedScopes` array into
     * `name => ['params' => [...], 'optional' => [...]]`.
     *
     * @param  array<mixed>  $declared
     * @return array<string, array{params: array<string>, optional: array<string>}>
     */
    public static function normalize(array $declared): array
    {
        $out = [];

        foreach ($declared as $key => $value) {
            if (is_int($key)) {
                if (! is_string($value)) {
                    continue;
                }
                $out[$value] = ['params' => [], 'optional' => []];

                continue;
            }

            $name = (string) $key;
            $params = [];
            $optional = [];

            if (is_string($value)) {
                $params = [$value];
            } elseif (is_array($value) && (array_key_exists('params', $value) || array_key_exists('optional', $value))) {
                $params = array_values(array_map('strval', (array) ($value['params'] ?? [])));
                $optional = array_values(array_map('strval', (array) ($value['optional'] ?? [])));
            } elseif (is_array($value)) {
                $params = array_values(array_map('strval', $value));
            }

            $out[$name] = [
                'params' => $params,
                // An 'optional' entry that is not a declared parameter is meaningless.
                'optional' => array_values(array_intersect($optional, $params)),
            ];
        }

        return $out;
    }

    /**
     * The declared scope names only.
     *
     * @param  array<mixed>  $declared
     * @return array<string>
     */
    public static function names(array $declared): array
    {
        return array_keys(static::normalize($declared));
    }

    /**
     * Bind the raw value a client sent for one scope to positional arguments,
     * in the order the model declared them.
     *
     * `$raw` is whatever the query string produced for `scope[<name>]`:
     * an empty string (no arguments), a scalar (the single parameter), or a
     * map of parameter name => value.
     *
     * @param  array{params: array<string>, optional: array<string>}  $spec
     * @return array<int, mixed>
     *
     * @throws InvalidScopeArguments
     */
    public static function bind(string $name, array $spec, $raw): array
    {
        $params = $spec['params'];
        $optional = $spec['optional'];

        $given = static::normalizeRawArguments($name, $params, $raw);

        foreach (array_keys($given) as $key) {
            if (! in_array($key, $params, true)) {
                throw new InvalidScopeArguments("Scope '{$name}' does not accept parameter '{$key}'");
            }
        }

        $args = [];
        foreach ($params as $param) {
            if (array_key_exists($param, $given)) {
                $args[] = static::coerce($given[$param]);

                continue;
            }

            if (! in_array($param, $optional, true)) {
                throw new InvalidScopeArguments("Scope '{$name}' requires parameter '{$param}'");
            }

            $args[] = null;
        }

        // Drop trailing nulls so an omitted optional parameter falls back to the
        // default declared in the scope's own signature.
        while ($args !== [] && end($args) === null) {
            array_pop($args);
        }

        return $args;
    }

    /**
     * Turn the raw query-string value into a parameter name => value map.
     *
     * @param  array<string>  $params
     * @return array<string, mixed>
     *
     * @throws InvalidScopeArguments
     */
    protected static function normalizeRawArguments(string $name, array $params, $raw): array
    {
        // `?scope[archived]=` (or a bare `?scope[archived]`): no arguments.
        // A scope with required parameters still fails, in bind(), naming them.
        if ($raw === null || $raw === '') {
            return [];
        }

        if (is_scalar($raw)) {
            if ($params === []) {
                throw new InvalidScopeArguments("Scope '{$name}' does not accept arguments");
            }

            // A bare value binds to the first declared parameter. Only allowed
            // when there is exactly one, so two parameters can never be guessed
            // at from a single value.
            if (count($params) > 1) {
                throw new InvalidScopeArguments("Scope '{$name}' requires named parameters");
            }

            return [$params[0] => $raw];
        }

        if (! is_array($raw)) {
            throw new InvalidScopeArguments("Scope '{$name}' is not allowed");
        }

        if ($params === []) {
            throw new InvalidScopeArguments("Scope '{$name}' does not accept arguments");
        }

        // A positional list (`scope[between][]=a`) is deliberately not supported:
        // every argument is named.
        foreach (array_keys($raw) as $key) {
            if (! is_string($key)) {
                throw new InvalidScopeArguments("Scope '{$name}' requires named parameters");
            }
        }

        foreach ($raw as $value) {
            if (! is_scalar($value) && $value !== null) {
                throw new InvalidScopeArguments("Scope '{$name}' requires named parameters");
            }
        }

        return $raw;
    }

    /**
     * Query-string values always arrive as strings; give scope bodies real
     * booleans so `if ($flag)` cannot be fooled by the string "false".
     */
    protected static function coerce($value)
    {
        if (! is_string($value)) {
            return $value;
        }

        $lowered = strtolower($value);

        if ($lowered === 'true') {
            return true;
        }

        if ($lowered === 'false') {
            return false;
        }

        return $value;
    }
}
