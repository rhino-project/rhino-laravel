<?php

namespace Rhino\Support;

use Illuminate\Database\Eloquent\Builder;
use Rhino\Exceptions\MissingTenantContext;

/**
 * The service behind Rhino::query(): builds a tenant-safe base query for any
 * model, applying the SAME organization scoping GlobalController applies to
 * CRUD, plus the app's user-aware global scopes.
 */
class ResourceScope
{
    use ScopesToOrganization;

    /**
     * Build a tenant-scoped base query for the given model class.
     *
     * Organization is taken from the current Rhino context (explicit override
     * if active, otherwise the request attribute). Fails CLOSED: an
     * organization-scoped model with no organization context throws.
     */
    public function query(string $modelClass): Builder
    {
        $ctx = app(RhinoContext::class);
        $org = $ctx->organization();
        $model = app()->make($modelClass);

        // Strip the console/request-gated 'organization' global scope; we apply
        // org deterministically from context (works in a request AND in
        // console/jobs). Keep other global scopes (the app's {Model}Scope reads
        // the current user).
        $query = $modelClass::query()->withoutGlobalScope('organization');

        if ($this->isOrganizationScoped($model)) {
            if (! $org) {
                throw new MissingTenantContext($modelClass); // fail closed
            }

            $this->scopeQueryToOrganization($query, $model, $org);
        }

        return $query;
    }

    /**
     * query() plus an optional whitelisted ?scope= named scope, invoked with the
     * current context user as its first argument.
     */
    public function scopedQuery(string $modelClass, ?string $namedScope = null): Builder
    {
        $query = $this->query($modelClass);

        $allowed = property_exists($modelClass, 'allowedScopes') ? $modelClass::$allowedScopes : [];

        if ($namedScope && in_array($namedScope, $allowed, true) && app($modelClass)->hasNamedScope($namedScope)) {
            $query->scopes([$namedScope => [app(RhinoContext::class)->user()]]);
        }

        return $query;
    }
}
