<?php

namespace Rhino\Contracts;

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
