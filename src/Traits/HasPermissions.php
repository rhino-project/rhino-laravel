<?php

namespace Rhino\Traits;

use App\Models\Organization;
use App\Models\UserRole;

trait HasPermissions
{
    /**
     * Get the user role assignments.
     */
    public function userRoles()
    {
        return $this->hasMany(UserRole::class);
    }

    /**
     * Check if the user has a specific permission.
     *
     * Permission format: '{slug}.{action}' (e.g., 'posts.index', 'blogs.store')
     *
     * Supports wildcards:
     *   - '*' grants access to everything
     *   - 'posts.*' grants access to all actions on posts
     *
     * Two permission sources:
     *   - users.permissions: used for non-tenant route groups (no organization context).
     *     Stored as a JSON array directly on the user model.
     *   - user_roles.permissions: used for tenant route groups (organization context present).
     *     Stored per-organization via the user_roles pivot table.
     *
     * Resolution:
     *   1. When an $organization is provided (tenant route group) → checks user_roles.permissions
     *      for that specific organization.
     *   2. When no $organization is provided (non-tenant route group) → checks users.permissions
     *      directly on the user model.
     *
     * When group-membership enforcement is ON (`rhino.auth.enforce_group_membership`)
     * AND a $routeGroup is provided, permissions resolve from the user_roles row
     * matching ($organization, $routeGroup) — a NULL route_group row is a wildcard
     * that matches any group. This path is only taken when enforcement is on; with
     * the flag off, behavior is exactly as before (org-presence heuristic).
     *
     * @param  string  $permission  The permission to check (e.g., 'posts.index')
     * @param  \App\Models\Organization|null  $organization  The organization context
     * @param  string|null  $routeGroup  The resolved route group (only used when enforcement is on)
     * @return bool
     */
    public function hasPermission(string $permission, $organization = null, ?string $routeGroup = null): bool
    {
        $slug = explode('.', $permission)[0] ?? '';

        // Enforcement ON: resolve permissions from the membership row matching
        // (org, route_group). Prefer the EXACT route_group row when one exists;
        // a NULL route_group row (wildcard) is only consulted when there is no
        // exact row for this group. This prevents a broad NULL `['*']` row from
        // silently overriding a deliberately scoped (and more restrictive)
        // per-group membership.
        if (config('rhino.auth.enforce_group_membership', false)) {
            $baseQuery = function () use ($organization) {
                $q = $this->userRoles();

                if ($organization) {
                    $q->where('organization_id', $organization->id);
                }

                return $q;
            };

            // 1. Exact route_group row(s) take precedence.
            if ($routeGroup !== null) {
                $exactRoles = $baseQuery()->where('route_group', $routeGroup)->get();

                if ($exactRoles->isNotEmpty()) {
                    foreach ($exactRoles as $userRole) {
                        if ($this->matchesPermission($permission, $slug, $userRole->permissions ?? [])) {
                            return true;
                        }
                    }

                    // An exact row exists: it is authoritative. Do NOT fall back
                    // to the NULL-wildcard row.
                    return false;
                }
            }

            // 2. No exact row → fall back to NULL-wildcard row(s).
            foreach ($baseQuery()->whereNull('route_group')->get() as $userRole) {
                if ($this->matchesPermission($permission, $slug, $userRole->permissions ?? [])) {
                    return true;
                }
            }

            return false;
        }

        if ($organization) {
            // Tenant route group: check user_roles.permissions for this organization
            $userRoles = $this->userRoles()
                ->where('organization_id', $organization->id)
                ->get();

            foreach ($userRoles as $userRole) {
                $permissions = $userRole->permissions ?? [];

                if ($this->matchesPermission($permission, $slug, $permissions)) {
                    return true;
                }
            }

            return false;
        }

        // Non-tenant route group: check users.permissions directly
        $permissions = $this->permissions ?? [];

        return $this->matchesPermission($permission, $slug, $permissions);
    }

    /**
     * Check if a permission matches against a list of granted permissions.
     */
    protected function matchesPermission(string $permission, string $slug, array $grantedPermissions): bool
    {
        foreach ($grantedPermissions as $p) {
            if ($p === $permission || $p === '*' || $p === "{$slug}.*") {
                return true;
            }
        }

        return false;
    }

    /**
     * Get the role slug for the given organization (for role-based validation).
     *
     * @param  \App\Models\Organization|mixed  $organization  Organization context from request, or null
     * @return string|null  Role slug (e.g. 'admin', 'assistant'), or null to use wildcard/fallback
     */
    public function getRoleSlugForValidation($organization): ?string
    {
        if ($organization === null) {
            return null;
        }

        $organizationId = $organization instanceof Organization
            ? $organization->id
            : (is_object($organization) && isset($organization->id) ? $organization->id : null);

        if ($organizationId === null) {
            return null;
        }

        $userRole = $this->userRoles()
            ->where('organization_id', $organizationId)
            ->with('role')
            ->first();

        return $userRole?->role?->slug;
    }
}
