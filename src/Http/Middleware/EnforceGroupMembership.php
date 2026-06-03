<?php

namespace Rhino\Http\Middleware;

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
 * matches every group) and, for tenant groups (an organization is present on
 * the request), the resolved organization. No match → 403.
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
        $organization = $request->attributes->get('organization');

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
