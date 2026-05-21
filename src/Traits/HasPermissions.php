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
     * @param  string  $permission  The permission to check (e.g., 'posts.index')
     * @param  \App\Models\Organization|null  $organization  The organization context
     * @return bool
     */
    public function hasPermission(string $permission, $organization = null): bool
    {
        $slug = explode('.', $permission)[0] ?? '';

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
