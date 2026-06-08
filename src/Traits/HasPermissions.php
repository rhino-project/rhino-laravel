<?php

namespace Rhino\Traits;

use App\Models\Organization;
use App\Models\UserRole;
use Illuminate\Support\Facades\DB;

trait HasPermissions
{
    /**
     * Per-request memoization of resolved org_role_permissions rows,
     * keyed by "{organizationId}:{roleId}".
     *
     * @var array<string, string[]>
     */
    protected array $orgRolePermissionsCache = [];

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
     * Supports wildcards on every layer:
     *   - '*' grants/denies everything
     *   - 'posts.*' grants/denies all actions on posts
     *
     * ── Layered resolution (organization context) ──────────────────────────
     * The effective decision for an org-scoped check is:
     *
     *     effective = (role ∪ granted) − denied        (deny always wins)
     *
     *   - role     → org_role_permissions[(organization, role)].permissions
     *                The shared "role layer" an org manages once per role.
     *   - granted  → user_roles.granted_permissions  (per-user additive delta)
     *   - denied   → user_roles.denied_permissions   (per-user subtractive delta)
     *   - legacy   → user_roles.permissions          (kept in the allow set so
     *                existing apps that store the full set per-user keep working)
     *
     * Deny is checked first and overrides everything — even a role '*'. This is
     * intentionally deny-overrides (not most-specific-wins): a permission denied
     * at the user layer is denied, period.
     *
     * Backward compatibility: when org_role_permissions has no row and the
     * granted/denied columns are empty (or absent), the allow set reduces to the
     * legacy user_roles.permissions and the behavior is byte-for-byte as before.
     *
     * ── Sources ────────────────────────────────────────────────────────────
     *   1. $organization provided (tenant route group) → user_roles layers above.
     *   2. No $organization (non-tenant route group)   → users.permissions
     *      directly on the user model (with optional users.denied_permissions if
     *      the column exists — deny still wins there too).
     *
     * When group-membership enforcement is ON (`rhino.auth.enforce_group_membership`)
     * AND a $routeGroup is provided, the layers resolve from the user_roles row
     * matching ($organization, $routeGroup) — a NULL route_group row is a wildcard
     * that matches any group. An exact route_group row is authoritative: when one
     * exists the NULL-wildcard row is NOT consulted.
     *
     * @param  string  $permission  The permission to check (e.g., 'posts.index')
     * @param  \App\Models\Organization|null  $organization  The organization context
     * @param  string|null  $routeGroup  The resolved route group (only used when enforcement is on)
     */
    public function hasPermission(string $permission, $organization = null, ?string $routeGroup = null): bool
    {
        return $this->resolvePermission($permission, $organization, $routeGroup)['granted'];
    }

    /**
     * Explain a permission decision — returns the deciding layer.
     *
     * @return array{granted: bool, reason: string}
     *   reason ∈ { 'denied', 'role', 'granted', 'legacy', 'user', 'default-deny' }
     */
    public function explainPermission(string $permission, $organization = null, ?string $routeGroup = null): array
    {
        return $this->resolvePermission($permission, $organization, $routeGroup);
    }

    /**
     * Resolve a permission to a decision + the layer that decided it.
     *
     * @return array{granted: bool, reason: string}
     */
    protected function resolvePermission(string $permission, $organization, ?string $routeGroup): array
    {
        $slug = explode('.', $permission)[0] ?? '';

        // Enforcement ON: resolve from the membership row matching (org, group).
        if (config('rhino.auth.enforce_group_membership', false)) {
            $rows = $this->membershipRowsForEnforcement($organization, $routeGroup);

            return $this->decideFromRows($permission, $slug, $rows, $organization);
        }

        if ($organization) {
            // Tenant route group: layered resolution from user_roles for this org.
            $rows = $this->userRoles()
                ->where('organization_id', $organization->id)
                ->get();

            return $this->decideFromRows($permission, $slug, $rows, $organization);
        }

        // Non-tenant route group: users.permissions (+ optional user-level deltas).
        $userPerms = $this->permissionList($this->permissions ?? null);
        $userGrants = $this->permissionList($this->granted_permissions ?? null);
        $userDenies = $this->permissionList($this->denied_permissions ?? null);

        return $this->decide(
            $permission,
            $slug,
            allow: array_merge($userPerms, $userGrants),
            deny: $userDenies,
            allowReasons: ['granted' => $userGrants, 'user' => $userPerms],
        );
    }

    /**
     * Collect the authoritative membership rows under group-enforcement.
     *
     * Exact route_group row(s) take precedence — when at least one exists it is
     * authoritative and the NULL-wildcard row is not consulted. Otherwise the
     * NULL-wildcard row(s) apply.
     */
    protected function membershipRowsForEnforcement($organization, ?string $routeGroup)
    {
        $base = function () use ($organization) {
            $q = $this->userRoles();

            if ($organization) {
                $q->where('organization_id', $organization->id);
            }

            return $q;
        };

        if ($routeGroup !== null) {
            $exact = $base()->where('route_group', $routeGroup)->get();

            if ($exact->isNotEmpty()) {
                return $exact;
            }
        }

        return $base()->whereNull('route_group')->get();
    }

    /**
     * Compute the decision from a set of user_role rows (org context).
     *
     * Unions the allow layers (legacy permissions ∪ granted ∪ org role layer)
     * and the deny layer (denied) across every relevant row, then applies
     * deny-overrides.
     *
     * @return array{granted: bool, reason: string}
     */
    protected function decideFromRows(string $permission, string $slug, $rows, $organization): array
    {
        $deny = [];
        $legacy = [];
        $granted = [];
        $role = [];

        foreach ($rows as $userRole) {
            $deny = array_merge($deny, $this->permissionList($userRole->denied_permissions ?? null));
            $legacy = array_merge($legacy, $this->permissionList($userRole->permissions ?? null));
            $granted = array_merge($granted, $this->permissionList($userRole->granted_permissions ?? null));
            $role = array_merge($role, $this->orgRolePermissions($organization, $userRole->role_id ?? null));
        }

        return $this->decide(
            $permission,
            $slug,
            allow: array_merge($legacy, $granted, $role),
            deny: $deny,
            // Order here defines reason precedence among the ALLOW layers.
            allowReasons: ['granted' => $granted, 'role' => $role, 'legacy' => $legacy],
        );
    }

    /**
     * Apply deny-overrides to an allow/deny pair and report the deciding layer.
     *
     * @param  string[]  $allow
     * @param  string[]  $deny
     * @param  array<string, string[]>  $allowReasons  layer-name => permission list,
     *         in precedence order, used only to label which layer granted.
     * @return array{granted: bool, reason: string}
     */
    protected function decide(string $permission, string $slug, array $allow, array $deny, array $allowReasons = []): array
    {
        // Deny always wins.
        if ($this->matchesPermission($permission, $slug, $deny)) {
            return ['granted' => false, 'reason' => 'denied'];
        }

        if ($this->matchesPermission($permission, $slug, $allow)) {
            foreach ($allowReasons as $layer => $list) {
                if ($this->matchesPermission($permission, $slug, $list)) {
                    return ['granted' => true, 'reason' => $layer];
                }
            }

            return ['granted' => true, 'reason' => 'allowed'];
        }

        return ['granted' => false, 'reason' => 'default-deny'];
    }

    /**
     * Resolve the shared role-layer permissions for (organization, role) from the
     * org_role_permissions table. Memoized per request; tolerant of the table not
     * existing (un-migrated apps) so it degrades to "no role layer".
     *
     * @return string[]
     */
    protected function orgRolePermissions($organization, $roleId): array
    {
        if (!$organization || !$roleId) {
            return [];
        }

        $orgId = is_object($organization) ? ($organization->id ?? null) : $organization;

        if ($orgId === null) {
            return [];
        }

        $key = $orgId . ':' . $roleId;

        if (array_key_exists($key, $this->orgRolePermissionsCache)) {
            return $this->orgRolePermissionsCache[$key];
        }

        $perms = [];

        try {
            $raw = DB::table('org_role_permissions')
                ->where('organization_id', $orgId)
                ->where('role_id', $roleId)
                ->value('permissions');

            $perms = $this->permissionList($raw);
        } catch (\Throwable $e) {
            // Table absent (app has not run the new migration) → no role layer.
            $perms = [];
        }

        return $this->orgRolePermissionsCache[$key] = $perms;
    }

    /**
     * Normalize a permissions value (array, JSON string, or null) into a clean
     * list of string permissions.
     *
     * @return string[]
     */
    protected function permissionList($value): array
    {
        if (is_array($value)) {
            return array_values(array_filter($value, 'is_string'));
        }

        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);

            return is_array($decoded)
                ? array_values(array_filter($decoded, 'is_string'))
                : [];
        }

        return [];
    }

    /**
     * Check if a permission matches against a list of permissions.
     * Supports exact match, global '*', and resource '{slug}.*' wildcards.
     *
     * @param  string[]  $grantedPermissions
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
