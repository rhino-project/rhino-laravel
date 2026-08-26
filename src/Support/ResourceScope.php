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
     * organization-scoped model with no organization context throws — unless
     * the app is single-tenant (config('rhino.multi_tenant.enabled') === false),
     * in which case no organization filter is applied at all and access is left
     * to the app's own user-aware global scopes.
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
            if ($org) {
                // An explicit organization is always honored, even in a
                // single-tenant app — the caller asked for that tenant.
                $this->scopeQueryToOrganization($query, $model, $org);
            } elseif ($this->multiTenancyEnabled()) {
                throw new MissingTenantContext($modelClass); // fail closed
            }
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

    /**
     * Whether organization scoping is active for this app. Defaults to true so
     * installs whose published config predates the flag keep failing closed.
     */
    protected function multiTenancyEnabled(): bool
    {
        return config('rhino.multi_tenant.enabled', true) !== false;
    }
}
