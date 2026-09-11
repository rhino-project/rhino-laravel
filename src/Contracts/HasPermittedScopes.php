<?php

namespace Rhino\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * Policies that restrict which client-selectable named scopes (`?scope=`) a
 * user may choose.
 *
 * The model's `$allowedScopes` says which scopes exist on the wire; this says
 * which of them THIS user may select. The effective set is the intersection.
 *
 * Implemented by the base `ResourcePolicy`, so an app only overrides the
 * method. A policy that does not implement this interface at all allows every
 * declared scope, which is the behavior of every app written before it existed.
 */
interface HasPermittedScopes
{
    /**
     * Scope names this user may select, or `['*']` for all declared scopes.
     *
     * @return array<string>
     */
    public function permittedScopes(?Authenticatable $user): array;
}
