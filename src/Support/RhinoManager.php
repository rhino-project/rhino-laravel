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
     * The Rhino context singleton.
     */
    public function context(): RhinoContext
    {
        return app(RhinoContext::class);
    }
}
