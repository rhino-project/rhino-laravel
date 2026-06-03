<?php

namespace Rhino\Exceptions;

use RuntimeException;

/**
 * Thrown at route-registration (boot) time when two route groups would resolve
 * to the same routes and silently shadow one another.
 *
 * This happens when two groups share the same effective prefix, have
 * intersecting host-sets (no domain = matches every host; same domain pattern),
 * and register overlapping models. The first group registered would win and the
 * second would be silently unreachable — a dangerous misconfiguration (e.g. a
 * `public` group shadowing an authenticated one), so we fail fast instead.
 */
class RouteGroupConflictException extends RuntimeException
{
    //
}
