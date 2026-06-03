<?php

namespace Rhino\Policies;

use Illuminate\Contracts\Auth\Authenticatable;
use Rhino\Contracts\HasHiddenColumns;
use Rhino\Contracts\HasPermittedAttributes;

class ResourcePolicy implements HasHiddenColumns, HasPermittedAttributes
{
    /**
     * The resource slug used for permission checks (e.g., 'posts', 'blogs').
     *
     * If not set, it will be auto-resolved from the global-controller config
     * by matching the model class this policy is registered for.
     */
    protected ?string $resourceSlug = null;

    // ------------------------------------------------------------------
    // Convention-based CRUD authorization
    // ------------------------------------------------------------------
    // Each method checks if the user has the '{slug}.{action}' permission
    // in their user_roles for the current organization.
    //
    // Override any method in your child policy for custom logic.
    // Call parent::methodName($user, ...) to compose with the permission check.
    // ------------------------------------------------------------------

    /**
     * Determine whether the user can view any models.
     */
    public function viewAny(?Authenticatable $user): bool
    {
        return $this->checkPermission($user, 'index');
    }

    /**
     * Determine whether the user can view the model.
     */
    public function view(?Authenticatable $user, $model): bool
    {
        return $this->checkPermission($user, 'show');
    }

    /**
     * Determine whether the user can create models.
     */
    public function create(?Authenticatable $user): bool
    {
        return $this->checkPermission($user, 'store');
    }

    /**
     * Determine whether the user can update the model.
     */
    public function update(?Authenticatable $user, $model): bool
    {
        return $this->checkPermission($user, 'update');
    }

    /**
     * Determine whether the user can delete the model.
     */
    public function delete(?Authenticatable $user, $model): bool
    {
        return $this->checkPermission($user, 'destroy');
    }

    // ------------------------------------------------------------------
    // Soft Delete authorization
    // ------------------------------------------------------------------

    /**
     * Determine whether the user can view trashed models.
     */
    public function viewTrashed(?Authenticatable $user): bool
    {
        return $this->checkPermission($user, 'trashed');
    }

    /**
     * Determine whether the user can restore the model.
     */
    public function restore(?Authenticatable $user, $model): bool
    {
        return $this->checkPermission($user, 'restore');
    }

    /**
     * Determine whether the user can permanently delete the model.
     */
    public function forceDelete(?Authenticatable $user, $model): bool
    {
        return $this->checkPermission($user, 'forceDelete');
    }

    // ------------------------------------------------------------------
    // Permission check engine
    // ------------------------------------------------------------------

    /**
     * Check if the user has the given permission for this resource.
     *
     * Permission format: '{slug}.{action}' (e.g., 'posts.index', 'blogs.store')
     *
     * Supports wildcards:
     *   - '*' grants access to everything
     *   - 'posts.*' grants access to all actions on posts
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable|null  $user
     * @param  string  $action  The action name (index, show, store, update, destroy)
     * @return bool
     */
    protected function checkPermission(?Authenticatable $user, string $action): bool
    {
        if (!$user) {
            return false;
        }

        $slug = $this->resolveResourceSlug();

        if (!$slug) {
            // If we can't resolve the slug, deny by default
            return false;
        }

        $permission = "{$slug}.{$action}";

        if (method_exists($user, 'hasPermission')) {
            $organization = request()->attributes->get('organization');
            $routeGroup = request()->route()?->defaults['route_group'] ?? null;
            return $user->hasPermission($permission, $organization, $routeGroup);
        }

        // Fallback: if the user model doesn't implement hasPermission,
        // allow (backwards compatibility)
        return true;
    }

    /**
     * Resolve the resource slug for permission checks.
     *
     * Priority:
     * 1. Explicit $resourceSlug property on the policy
     * 2. Auto-resolve from global-controller config by matching model class
     */
    protected function resolveResourceSlug(): ?string
    {
        if ($this->resourceSlug) {
            return $this->resourceSlug;
        }

        // Auto-resolve: find which model class this policy is registered for,
        // then look up its slug in the config
        $models = config('rhino.models', []);
        $policies = app('Illuminate\Contracts\Auth\Access\Gate');

        foreach ($models as $slug => $modelClass) {
            try {
                $policy = $policies->getPolicyFor($modelClass);
                if ($policy && get_class($policy) === static::class) {
                    $this->resourceSlug = $slug; // cache for subsequent calls
                    return $slug;
                }
            } catch (\Exception $e) {
                continue;
            }
        }

        return null;
    }

    // ------------------------------------------------------------------
    // Permitted attributes (HasPermittedAttributes)
    // ------------------------------------------------------------------

    /**
     * Attributes the user is allowed to see in show/index responses.
     *
     * Return `['*']` to allow all attributes (default — nothing restricted).
     * Override in your child policy to restrict per-role.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable|null  $user
     * @return array<string>
     */
    public function permittedAttributesForShow(?Authenticatable $user): array
    {
        return ['*'];
    }

    /**
     * Attributes that should be hidden from show/index responses.
     *
     * Override this method in your policy to apply role-based column visibility.
     * Return an array of column names that should be hidden from the response.
     *
     * These columns are additive — they are merged with the base hidden columns
     * and any static $additionalHiddenColumns defined on the model. Returning an
     * empty array means no additional columns are hidden beyond the defaults.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable|null  $user
     * @return array<string>
     */
    public function hiddenAttributesForShow(?Authenticatable $user): array
    {
        return [];
    }

    /**
     * Attributes the user is allowed to send when creating a resource.
     *
     * Return `['*']` to allow all fillable attributes (default — nothing restricted).
     * Override in your child policy to restrict per-role.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable|null  $user
     * @return array<string>
     */
    public function permittedAttributesForCreate(?Authenticatable $user): array
    {
        return ['*'];
    }

    /**
     * Attributes the user is allowed to send when updating a resource.
     *
     * Return `['*']` to allow all fillable attributes (default — nothing restricted).
     * Override in your child policy to restrict per-role.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable|null  $user
     * @return array<string>
     */
    public function permittedAttributesForUpdate(?Authenticatable $user): array
    {
        return ['*'];
    }

    // ------------------------------------------------------------------
    // Column hiding (legacy + new)
    // ------------------------------------------------------------------

    /**
     * @deprecated Use hiddenAttributesForShow() instead. Will be removed in a future version.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable|null  $user
     * @return array<string>
     */
    public function hiddenColumns(?Authenticatable $user): array
    {
        return [];
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    /**
     * Check if the authenticated user has a specific role in the current organization.
     *
     * Convenience method for use in child policies when implementing
     * permittedAttributesForCreate/Update/Show overrides.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable|null  $user
     * @param  string  $role  Role slug (e.g. 'admin', 'editor')
     * @return bool
     */
    protected function hasRole(?Authenticatable $user, string $role): bool
    {
        if (!$user) {
            return false;
        }

        if (!method_exists($user, 'getRoleSlugForValidation')) {
            return false;
        }

        $organization = request()->attributes->get('organization');

        return $user->getRoleSlugForValidation($organization) === $role;
    }
}
