<?php

namespace Rhino\Contracts;

/**
 * Per-group auth lifecycle hooks.
 *
 * A route group may declare a `hooks` class implementing this contract. The
 * relevant {@see \Rhino\Controllers\AuthController} action calls the matching
 * method AFTER the action succeeds, passing the affected user and a context
 * array.
 *
 * The context shape is:
 *   [
 *     'user'         => \App\Models\User,            // the affected user
 *     'routeGroup'   => string|null,                 // resolved group key
 *     'organization' => \App\Models\Organization|null,
 *     'token'        => string|null,                 // plain-text token (login/register)
 *     'request'      => \Illuminate\Http\Request,
 *   ]
 *
 * A hook may REJECT the action by throwing
 * {@see \Rhino\Exceptions\RhinoAuthRejected}. For token-issuing actions
 * (login/register) the controller revokes ONLY the just-issued token (never the
 * user's other, pre-existing sessions) and returns the exception's status
 * (default 403); for other actions it returns the status without further side
 * effects.
 *
 * EXCEPTION — {@see afterPasswordRecover}: a rejection thrown here is SWALLOWED.
 * The recover endpoint must return a uniform response whether or not the email
 * exists, so a rejecting recover hook runs for its side effects but cannot alter
 * the HTTP status (otherwise it would become a user-enumeration oracle). All
 * other events honor rejection.
 *
 * NON-REJECT EXCEPTIONS: if a hook throws anything other than
 * {@see \Rhino\Exceptions\RhinoAuthRejected}, the controller treats it as a hook
 * failure — for token-issuing actions it revokes the just-issued token and
 * returns a uniform 500 (the exception does not propagate uncaught past token
 * issuance).
 *
 * Implementations should extend {@see AbstractAuthLifecycleHooks}, which
 * provides a no-op default for every method, so only the relevant events need
 * to be overridden.
 */
interface AuthLifecycleHooks
{
    /**
     * Called after a successful login (token issued).
     *
     * @param  array<string, mixed>  $context
     */
    public function afterLogin($user, array $context): void;

    /**
     * Called after a logout.
     *
     * @param  array<string, mixed>  $context
     */
    public function afterLogout($user, array $context): void;

    /**
     * Called after an invitation-accept registration (token issued).
     *
     * @param  array<string, mixed>  $context
     */
    public function afterRegister($user, array $context): void;

    /**
     * Called after a password recovery email is requested.
     *
     * @param  array<string, mixed>  $context
     */
    public function afterPasswordRecover($user, array $context): void;

    /**
     * Called after a password reset completes.
     *
     * @param  array<string, mixed>  $context
     */
    public function afterPasswordReset($user, array $context): void;
}
