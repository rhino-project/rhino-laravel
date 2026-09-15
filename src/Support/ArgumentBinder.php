<?php

namespace Rhino\Support;

use Throwable;

/**
 * Binds the arguments a client sent in the bracket query form
 * (`?scope[name][param]=value`, `?attributes[name][param]=value`) to the
 * parameters a model declared, in declared order.
 *
 * The algorithm is shared by named scopes and computed attributes so the two
 * features cannot drift. Everything that differs between them is passed in:
 *
 *  - `$subject` is the noun used in every error message ("Scope",
 *    "Computed attribute"), so each feature keeps its own wording;
 *  - `$fail` is a factory returning the Throwable to raise, so each feature
 *    keeps its own exception type and its own controller catch.
 *
 * Nothing here decides whether a name may be used: callers MUST run the
 * declared-check and the policy-check BEFORE binding, so an argument error can
 * only ever be seen for a name the caller was already allowed to use.
 */
class ArgumentBinder
{
    /**
     * Clean a declared parameter list: stringify the names, and drop any
     * 'optional' entry that is not actually a declared parameter.
     *
     * @param  mixed  $params
     * @param  mixed  $optional
     * @return array{params: array<string>, optional: array<string>}
     */
    public static function normalizeParams($params, $optional = []): array
    {
        $params = array_values(array_map('strval', (array) ($params ?? [])));
        $optional = array_values(array_map('strval', (array) ($optional ?? [])));

        return [
            'params' => $params,
            // An 'optional' entry that is not a declared parameter is meaningless.
            'optional' => array_values(array_intersect($optional, $params)),
        ];
    }

    /**
     * Bind the raw value a client sent for one name to positional arguments,
     * in the order the model declared them.
     *
     * `$raw` is whatever the query string produced for the bracket key: an
     * empty string (no arguments), a scalar (the single parameter), or a map of
     * parameter name => value.
     *
     * @param  string  $subject  Noun for error messages ("Scope", "Computed attribute").
     * @param  array{params: array<string>, optional: array<string>}  $spec
     * @param  mixed  $raw
     * @param  callable(string): Throwable  $fail
     * @return array<int, mixed>
     *
     * @throws Throwable
     */
    public static function bind(string $subject, string $name, array $spec, $raw, callable $fail): array
    {
        $params = $spec['params'] ?? [];
        $optional = $spec['optional'] ?? [];

        $given = static::normalizeRawArguments($subject, $name, $params, $raw, $fail);

        foreach (array_keys($given) as $key) {
            if (! in_array($key, $params, true)) {
                throw $fail("{$subject} '{$name}' does not accept parameter '{$key}'");
            }
        }

        $args = [];
        foreach ($params as $param) {
            if (array_key_exists($param, $given)) {
                $args[] = static::coerce($given[$param]);

                continue;
            }

            if (! in_array($param, $optional, true)) {
                throw $fail("{$subject} '{$name}' requires parameter '{$param}'");
            }

            $args[] = null;
        }

        // Drop trailing nulls so an omitted optional parameter falls back to the
        // default declared in the callable's own signature.
        while ($args !== [] && end($args) === null) {
            array_pop($args);
        }

        return $args;
    }

    /**
     * Turn the raw query-string value into a parameter name => value map.
     *
     * @param  array<string>  $params
     * @param  mixed  $raw
     * @param  callable(string): Throwable  $fail
     * @return array<string, mixed>
     *
     * @throws Throwable
     */
    public static function normalizeRawArguments(string $subject, string $name, array $params, $raw, callable $fail): array
    {
        // `?scope[archived]=` (or a bare `?scope[archived]`): no arguments.
        // A name with required parameters still fails, in bind(), naming them.
        if ($raw === null || $raw === '') {
            return [];
        }

        if (is_scalar($raw)) {
            if ($params === []) {
                throw $fail("{$subject} '{$name}' does not accept arguments");
            }

            // A bare value binds to the first declared parameter. Only allowed
            // when there is exactly one, so two parameters can never be guessed
            // at from a single value.
            if (count($params) > 1) {
                throw $fail("{$subject} '{$name}' requires named parameters");
            }

            return [$params[0] => $raw];
        }

        if (! is_array($raw)) {
            throw $fail("{$subject} '{$name}' is not allowed");
        }

        if ($params === []) {
            throw $fail("{$subject} '{$name}' does not accept arguments");
        }

        // A positional list (`scope[between][]=a`) is deliberately not supported:
        // every argument is named.
        foreach (array_keys($raw) as $key) {
            if (! is_string($key)) {
                throw $fail("{$subject} '{$name}' requires named parameters");
            }
        }

        foreach ($raw as $value) {
            if (! is_scalar($value) && $value !== null) {
                throw $fail("{$subject} '{$name}' requires named parameters");
            }
        }

        return $raw;
    }

    /**
     * Query-string values always arrive as strings; give callables real
     * booleans so `if ($flag)` cannot be fooled by the string "false".
     *
     * @param  mixed  $value
     * @return mixed
     */
    public static function coerce($value)
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
