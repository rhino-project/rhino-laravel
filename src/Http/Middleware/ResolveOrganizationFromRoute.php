<?php

namespace Rhino\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use App\Models\Organization;
use Symfony\Component\HttpFoundation\Response;

class ResolveOrganizationFromRoute
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        // Only process if the route has an organization parameter
        $route = $request->route();
        if (!$route || !$route->hasParameter('organization')) {
            return $next($request);
        }

        $organizationIdentifier = $route->parameter('organization');

        if (!$organizationIdentifier) {
            return response()->json(['message' => 'Organization identifier is required'], 400);
        }

        $identifierColumn = config('rhino.multi_tenant.organization_identifier_column', 'id');
        
        $organization = Organization::where($identifierColumn, $organizationIdentifier)->first();

        if (!$organization) {
            return response()->json(['message' => 'Organization not found'], 404);
        }

        // Check if user is authenticated and belongs to this organization
        $user = $request->user('sanctum');
        
        if ($user) {
            $userBelongsToOrg = $user->organizations()
                ->where('organizations.id', $organization->id)
                ->exists();
            
            if (!$userBelongsToOrg) {
                return response()->json(['message' => 'Organization not found'], 404);
            }
        }

        // Set organization in request attributes for later use.
        // We intentionally use only attributes->set() (not merge()) so the
        // organization object does not appear in $request->all() / input(),
        // which would cause findForbiddenFields() to treat it as a
        // user-submitted field and trigger a 403.
        $request->attributes->set('organization', $organization);

        return $next($request);
    }
}
