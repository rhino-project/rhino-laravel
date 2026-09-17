<?php

namespace Rhino\Contracts;

/**
 * Note: the role-keyed *validation* configuration this interface was introduced
 * for ($validationRulesStore / $validationRulesUpdate keyed by role slug) is
 * deprecated in 4.10.0 in favour of request classes, which branch on
 * $this->user() directly. The interface itself is NOT deprecated — policies
 * still resolve a user's role through it (ResourcePolicy::hasRole()).
 */
interface HasRoleBasedValidation
{
    /**
     * Return the role slug to use for role-based validation rules.
     * Typically the user's role in the given organization (e.g. 'admin', 'assistant').
     *
     * @param  mixed  $organization  Organization context (e.g. from request, or null when not multi-tenant)
     * @return string|null  Role slug, or null to use wildcard/legacy fallback
     */
    public function getRoleSlugForValidation($organization): ?string;
}
