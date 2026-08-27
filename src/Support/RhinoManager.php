<?php

namespace Rhino\Support;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;

/**
 * Entry point behind the Rhino facade. Delegates resource-scope queries to
 * ResourceScope, exposes the fluent explicit builder, and the context singleton.
 */
class RhinoManager
{
    /**
     * Build a tenant-scoped base query using ambient context.
     */
    public function query(string $modelClass): Builder
    {
        return app(ResourceScope::class)->query($modelClass);
    }

    /**
     * Build a tenant-scoped query plus a whitelisted named scope, using ambient context.
     */
    public function scopedQuery(string $modelClass, ?string $namedScope = null): Builder
    {
        return app(ResourceScope::class)->scopedQuery($modelClass, $namedScope);
    }

    /**
     * Begin an explicit (user, organization) context for use outside a tenant request.
     */
    public function forUser(Authenticatable $user, $organization = null): PendingScopedContext
    {
        return new PendingScopedContext($user, $organization);
    }

    /**
     * Begin an explicit context that acts as the named route group, for use
     * where no route resolves one — a queued job, a console command, a test.
     *
     * The group's own 'tenant' key still decides the boundary: naming a group
     * declared 'tenant' => false lets the query span every organization, while
     * naming any other group keeps failing closed.
     */
    public function inRouteGroup(string $routeGroup): PendingScopedContext
    {
        return new PendingScopedContext(null, null, $routeGroup);
    }

    /**
     * The Rhino context singleton.
     */
    public function context(): RhinoContext
    {
        return app(RhinoContext::class);
    }

    /**
     * Resolve the column that the {id} URL segment is matched against on
     * member endpoints (show, update, destroy, restore, force-delete).
     *
     * Resolution chain (first match wins):
     *   1. Model static `$routeKey` (non-empty string), e.g.
     *      `public static string $routeKey = 'hash_id';`
     *   2. `config('rhino.route_key')` — `null` or `'id'` mean "not set"
     *   3. `$model->getRouteKeyName()` — Eloquent's default (the primary key
     *      name), which keeps the historical behavior byte-identical.
     *
     * Resolution is O(1): property/config lookups only, no schema or DB
     * introspection. Accepts a model instance or a class-string.
     *
     * @param  object|string  $model  Model instance or fully-qualified class name
     */
    public function routeKeyName(object|string $model): string
    {
        $class = is_object($model) ? get_class($model) : $model;

        // 1. Per-model static $routeKey
        if (property_exists($class, 'routeKey')) {
            $ref = new \ReflectionProperty($class, 'routeKey');
            if ($ref->isStatic()) {
                $value = $ref->getValue();
                if (is_string($value) && $value !== '') {
                    return $value;
                }
            }
        }

        // 2. Global config default — null/''/'id' fall through to Eloquent
        $configured = config('rhino.route_key');
        if (is_string($configured) && $configured !== '' && $configured !== 'id') {
            return $configured;
        }

        // 3. Eloquent default (primary key name)
        $instance = is_object($model) ? $model : new $class();

        return $instance->getRouteKeyName();
    }
}
