<?php

namespace Rhino\Contracts;

use Illuminate\Contracts\Auth\Authenticatable;

/**
 * @deprecated Use HasPermittedAttributes::hiddenAttributesForShow() instead.
 *             This interface is preserved for backward compatibility and will
 *             be removed in a future major version.
 */
interface HasHiddenColumns
{
    /**
     * Define additional columns to hide based on the authenticated user.
     *
     * @deprecated Use HasPermittedAttributes::hiddenAttributesForShow() instead.
     *
     * @param  \Illuminate\Contracts\Auth\Authenticatable|null  $user
     * @return array<string>
     */
    public function hiddenColumns(?Authenticatable $user): array;
}
