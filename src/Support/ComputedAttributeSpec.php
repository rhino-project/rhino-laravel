<?php

namespace Rhino\Support;

use Rhino\Exceptions\InvalidComputedAttributeArguments;

/**
 * Parses a model's computed-attribute declarations
 * (`rhinoRecordComputedAttributes()` / `rhinoCollectionComputedAttributes()`)
 * and binds the arguments a client sent for `?attributes[name][param]=value`
 * (and `?computed_attributes[name][param]=value`) to the declared parameters.
 *
 * Declaration forms (both may be mixed in one array):
 *
 *     return [
 *         // Legacy: anything that is not an extended spec is used as-is —
 *         // a callable is called, any other value is serialized literally.
 *         'active_users_count' => fn ($query, $user) => $query->count(),
 *         'schema_version'     => 3,
 *
 *         // Extended: a map carrying at least one of params/optional/using.
 *         'revenue' => [
 *             'params'   => ['from', 'to'],
 *             'optional' => ['to'],
 *             'using'    => fn ($query, $user, $from, $to = null) => ...,
 *         ],
 *     ];
 *
 * Unlike named scopes, there is deliberately NO string or bare-list shorthand:
 * `'version' => 'v3'` and `'tags' => ['a', 'b']` are valid *literal* value
 * declarations today, and reinterpreting them as parameter lists would silently
 * change what a shipped model returns. A declaration is an extended spec if and
 * only if it is an array carrying `params`, `optional` or `using` — those three
 * keys are reserved inside a computed-attribute declaration.
 */
class ComputedAttributeSpec
{
    /**
     * The noun every computed-attribute argument error message starts with.
     */
    protected const SUBJECT = 'Computed attribute';

    /**
     * The keys whose presence marks a declaration map as an extended spec.
     */
    protected const SPEC_KEYS = ['params', 'optional', 'using'];

    /**
     * Normalize a raw declaration array into
     * `name => ['params' => [...], 'optional' => [...], 'using' => mixed]`.
     *
     * `using` is the callable (or the literal value) that produces the
     * attribute; for a legacy declaration it is the declared value itself.
     *
     * @param  array<mixed>  $declared
     * @return array<string, array{params: array<string>, optional: array<string>, using: mixed}>
     */
    public static function normalize(array $declared): array
    {
        $out = [];

        foreach ($declared as $key => $value) {
            // Attribute names are always the array key — including a numeric
            // one, which stays the attribute's name rather than becoming a
            // parameterless declaration the way it does for scopes.
            $name = (string) $key;

            if (! static::isSpec($value)) {
                $out[$name] = ['params' => [], 'optional' => [], 'using' => $value];

                continue;
            }

            $out[$name] = ArgumentBinder::normalizeParams(
                $value['params'] ?? [],
                $value['optional'] ?? []
            ) + ['using' => $value['using'] ?? null];
        }

        return $out;
    }

    /**
     * Whether a declared value is an extended spec rather than a legacy
     * callable/literal declaration.
     *
     * @param  mixed  $value
     */
    public static function isSpec($value): bool
    {
        if (! is_array($value)) {
            return false;
        }

        foreach (static::SPEC_KEYS as $key) {
            if (array_key_exists($key, $value)) {
                return true;
            }
        }

        return false;
    }

    /**
     * The declared attribute names only.
     *
     * @param  array<mixed>  $declared
     * @return array<string>
     */
    public static function names(array $declared): array
    {
        return array_keys(static::normalize($declared));
    }

    /**
     * Whether the attribute declares at least one parameter that the client
     * MUST supply. Such attributes are skipped — never 403'd — when no
     * selection was made (a bare `GET /computed`) and when a direct
     * serialization call passes no arguments for them.
     *
     * @param  array{params: array<string>, optional: array<string>}  $spec
     */
    public static function requiresArguments(array $spec): bool
    {
        return array_diff($spec['params'] ?? [], $spec['optional'] ?? []) !== [];
    }

    /**
     * Bind the raw value a client sent for one attribute to positional
     * arguments, in the order the model declared them.
     *
     * Callers MUST have already checked that the attribute is declared and
     * policy-visible: the messages raised here name the attribute.
     *
     * @param  array{params: array<string>, optional: array<string>}  $spec
     * @param  mixed  $raw
     * @return array<int, mixed>
     *
     * @throws InvalidComputedAttributeArguments
     */
    public static function bind(string $name, array $spec, $raw): array
    {
        return ArgumentBinder::bind(
            static::SUBJECT,
            $name,
            $spec,
            $raw,
            fn (string $message) => new InvalidComputedAttributeArguments($message)
        );
    }
}
