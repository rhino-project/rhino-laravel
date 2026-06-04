<?php

namespace Rhino\Http\Middleware;

use App\Models\Organization;
use App\Models\UserRole;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * Enforces group membership as a coarse access boundary.
 *
 * Only active when `rhino.auth.enforce_group_membership` is true; otherwise it
 * is a transparent pass-through (preserving today's behavior byte-for-byte).
 *
 * When active, the authenticated user must have a `user_roles` row matching the
 * request's resolved `route_group` (a NULL `route_group` row is a WILDCARD that
 * matches every group) and, for tenant groups (a route with an `{organization}`
 * parameter), the resolved organization. No match → 403.
 *
 * §11.2: when enforcement is on this gate is ordered BEFORE
 * ResolveOrganizationFromRoute, so it resolves the organization itself (from the
 * route param + identifier column) to make the (group, org) membership decision.
 * An authenticated non-member therefore gets a 403 that takes precedence over the
 * org resolver's info-hiding 404. A genuinely non-existent organization is left
 * to ResolveOrganizationFromRoute, which 404s afterwards.
 *
 * The `public` group is never auth-enabled, so this middleware is never added
 * to its routes.
 */
class EnforceGroupMembership
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (!config('rhino.auth.enforce_group_membership', false)) {
            return $next($request);
        }

        $user = $request->user('sanctum') ?? $request->user();

        // No authenticated user → let the auth middleware / policies handle it
        // (this middleware only gates membership for authenticated users).
        if (!$user) {
            return $next($request);
        }

        $routeGroup = $this->resolveRouteGroup($request);
        $organization = $this->resolveOrganization($request);

        // A route that declares an {organization} param but resolves to no
        // existing organization is NOT a membership denial — it is a genuinely
        // non-existent org. Pass through so ResolveOrganizationFromRoute (ordered
        // after this gate) returns its info-hiding 404 unchanged.
        if ($this->routeExpectsOrganization($request) && $organization === null) {
            return $next($request);
        }

        if (!$this->isMember($user, $routeGroup, $organization)) {
            return response()->json([
                'message' => 'You are not a member of this group',
            ], 403);
        }

        return $next($request);
    }

    /**
     * Resolve the route group key from the matched route's defaults.
     */
    protected function resolveRouteGroup(Request $request): ?string
    {
        $route = $request->route();

        if (!$route) {
            return null;
        }

        return $route->defaults['route_group'] ?? null;
    }

    /**
     * Whether the matched route carries an {organization} parameter (i.e. it is
     * a tenant-group route whose membership decision must consider the org).
     */
    protected function routeExpectsOrganization(Request $request): bool
    {
        $route = $request->route();

        return $route !== null && $route->hasParameter('organization');
    }

    /**
     * Resolve the organization for the request.
     *
     * Prefers an organization already placed on the request attributes (e.g. by
     * ResolveOrganizationFromRoute in configurations where it runs first), and
     * otherwise resolves it from the route's {organization} parameter using the
     * configured identifier column — mirroring ResolveOrganizationFromRoute so
     * the gate's tenant org-match works when it is ordered first (§11.2).
     */
    protected function resolveOrganization(Request $request)
    {
        $organization = $request->attributes->get('organization');
        if ($organization !== null) {
            return $organization;
        }

        if (!$this->routeExpectsOrganization($request)) {
            return null;
        }

        $identifier = $request->route()->parameter('organization');
        if (!$identifier) {
            return null;
        }

        $column = config('rhino.multi_tenant.organization_identifier_column', 'id');

        return Organization::where($column, $identifier)->first();
    }

    /**
     * Determine whether the user is a member of the resolved group.
     *
     * A `user_roles` row qualifies when:
     *   - its `route_group` is NULL (wildcard) OR equals the resolved group, AND
     *   - for tenant groups (organization present) its `organization_id`
     *     matches the resolved organization; non-tenant groups ignore org.
     */
    public static function isMember($user, ?string $routeGroup, $organization = null): bool
    {
        $query = UserRole::query()->where('user_id', $user->getKey());

        // route_group: NULL row (wildcard) OR exact match.
        $query->where(function ($q) use ($routeGroup) {
            $q->whereNull('route_group');

            if ($routeGroup !== null) {
                $q->orWhere('route_group', $routeGroup);
            }
        });

        // Tenant group: organization must match. Non-tenant: ignore org.
        if ($organization !== null) {
            $orgId = is_object($organization) ? $organization->getKey() : $organization;
            $query->where('organization_id', $orgId);
        }

        return $query->exists();
    }
}
