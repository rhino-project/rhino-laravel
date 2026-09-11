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
     * the query belongs to a route group declared non-tenant
     * ('tenant' => false) — either because the request is served by that group
     * or because the caller said so with Rhino::inRouteGroup(...) — in which
     * case no organization filter is applied at all and access is left to the
     * app's own user-aware global scopes.
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
                // An explicit organization is always honored, even inside a
                // non-tenant group — the caller asked for that tenant.
                $this->scopeQueryToOrganization($query, $model, $org);
            } elseif ($this->currentGroupIsTenant()) {
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

        $allowed = property_exists($modelClass, 'allowedScopes')
            ? ScopeSpec::names((array) $modelClass::$allowedScopes)
            : [];

        if ($namedScope && in_array($namedScope, $allowed, true) && app($modelClass)->hasNamedScope($namedScope)) {
            $query->scopes([$namedScope => [app(RhinoContext::class)->user()]]);
        }

        return $query;
    }

    /**
     * Whether the route group serving the current request has a tenant
     * boundary. A group that declares 'tenant' => false does not: queries made
     * inside it legitimately span every organization (a back office / admin
     * group), so the resolver applies no organization filter and does not throw.
     *
     * Defaults to TRUE for every other case — an unknown group, an untagged
     * route, or no group at all (a queued job or console command that did not
     * name one) — so the resolver keeps failing closed wherever the group is
     * not provably non-tenant.
     */
    protected function currentGroupIsTenant(): bool
    {
        $group = $this->currentRouteGroup();

        if ($group === null) {
            return true;
        }

        return config("rhino.route_groups.{$group}.tenant", true) !== false;
    }

    /**
     * The route group this query belongs to: an explicit
     * Rhino::inRouteGroup(...) context if one is active, otherwise the matched
     * route's 'route_group' default — the same source EnforceGroupMembership,
     * ResourcePolicy and AuthController resolve the group from. Null outside a
     * request, when no route matched, or when the route carries no group tag.
     */
    protected function currentRouteGroup(): ?string
    {
        return app(RhinoContext::class)->routeGroup();
    }
}
