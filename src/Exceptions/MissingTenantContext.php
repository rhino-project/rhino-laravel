<?php

namespace Rhino\Exceptions;

/**
 * Thrown when Rhino::query() is asked to build a query for an organization-scoped
 * model but no organization context is available. Fail CLOSED: never return an
 * unscoped query for a tenant-owned model.
 */
class MissingTenantContext extends \RuntimeException
{
    public function __construct(string $modelClass)
    {
        parent::__construct(
            "Rhino::query({$modelClass}) requires an organization context but none is set. "
            . 'Use Rhino::forUser(...)->inOrganization(...) outside a tenant request.'
        );
    }
}
