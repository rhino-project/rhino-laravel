<?php

namespace Rhino\Contracts;

/**
 * No-op base class for {@see AuthLifecycleHooks}.
 *
 * Extend this and override only the events you care about; every event defaults
 * to doing nothing (and therefore never rejecting the action).
 */
abstract class AbstractAuthLifecycleHooks implements AuthLifecycleHooks
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function afterLogin($user, array $context): void
    {
        // no-op
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function afterLogout($user, array $context): void
    {
        // no-op
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function afterRegister($user, array $context): void
    {
        // no-op
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function afterPasswordRecover($user, array $context): void
    {
        // no-op
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function afterPasswordReset($user, array $context): void
    {
        // no-op
    }
}
