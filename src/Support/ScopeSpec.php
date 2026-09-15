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
     * The noun every scope-argument error message starts with. The binding
     * algorithm itself lives in ArgumentBinder and is shared with computed
     * attributes; this constant is what keeps the scope wording its own.
     */
    protected const SUBJECT = 'Scope';

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
                $params = (array) ($value['params'] ?? []);
                $optional = (array) ($value['optional'] ?? []);
            } elseif (is_array($value)) {
                $params = $value;
            }

            $out[$name] = ArgumentBinder::normalizeParams($params, $optional);
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
        return ArgumentBinder::bind(static::SUBJECT, $name, $spec, $raw, static::failure());
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
        return ArgumentBinder::normalizeRawArguments(static::SUBJECT, $name, $params, $raw, static::failure());
    }

    /**
     * Query-string values always arrive as strings; give scope bodies real
     * booleans so `if ($flag)` cannot be fooled by the string "false".
     */
    protected static function coerce($value)
    {
        return ArgumentBinder::coerce($value);
    }

    /**
     * The exception the binder raises for this subject.
     *
     * @return callable(string): InvalidScopeArguments
     */
    protected static function failure(): callable
    {
        return fn (string $message) => new InvalidScopeArguments($message);
    }
}
