<?php

namespace Rhino\Controllers;

use App\Models\Organization;
use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Validator;
use Rhino\Contracts\AuthLifecycleHooks;
use Rhino\Exceptions\RhinoAuthRejected;
use Rhino\Http\Middleware\EnforceGroupMembership;
use Rhino\Jobs\SendPasswordRecoveryEmailJob;
use Rhino\Models\OrganizationInvitation;
use App\Models\User;

class AuthController extends Controller
{
    public function login(Request $request)
    {
        $credentials = $request->validate([
            'email' => 'required|string|email',
            'password' => 'required|string',
        ]);

        if (!Auth::attempt($credentials)) {
            return response()->json(['message' => 'Invalid credentials'], 401);
        }

        $user = Auth::user();
        $routeGroup = $this->resolveRouteGroup($request);
        $organization = $this->resolveOrganization($request);

        // Membership enforcement: the user must be a member of the resolved
        // group (NULL row = wildcard; tenant group also requires org) → else 403.
        if (config('rhino.auth.enforce_group_membership', false)) {
            if (!EnforceGroupMembership::isMember($user, $routeGroup, $organization)) {
                return response()->json([
                    'message' => 'You are not a member of this group',
                ], 403);
            }
        }

        // Capture the new token instance so a rejecting hook can revoke ONLY this
        // session, never the user's other (pre-existing) tokens (design §7).
        $newToken = $user->createToken('API Token');
        $token = $newToken->plainTextToken;

        // Get the first organization the user belongs to. Single-tenant apps
        // have no Organization model and no organizations() relation, so guard
        // the call and simply return a null slug in that case.
        $organizationSlug = null;
        if (method_exists($user, 'organizations')) {
            $firstOrganization = $user->organizations()->first();
            $organizationSlug = $firstOrganization ? $firstOrganization->slug : null;
        }

        // Lifecycle hook (may reject → revoke the just-issued token + return status).
        $rejection = $this->runHook($routeGroup, 'afterLogin', $user, [
            'routeGroup' => $routeGroup,
            'organization' => $organization,
            'token' => $token,
            'request' => $request,
        ], $newToken->accessToken);
        if ($rejection !== null) {
            return $rejection;
        }

        return response()->json([
            'token' => $token,
            'organization_slug' => $organizationSlug,
        ], 200);
    }

    public function logout(Request $request)
    {
        $user = $request->user();
        $routeGroup = $this->resolveRouteGroup($request);
        $organization = $this->resolveOrganization($request);

        $user->tokens()->delete();

        // Lifecycle hook (token already gone; reject just returns the status).
        $rejection = $this->runHook($routeGroup, 'afterLogout', $user, [
            'routeGroup' => $routeGroup,
            'organization' => $organization,
            'token' => null,
            'request' => $request,
        ], null);
        if ($rejection !== null) {
            return $rejection;
        }

        return response()->json(['message' => 'Logged out successfully'], 200);
    }

    public function recoverPassword(Request $request)
    {
        $request->validate(['email' => 'required|email']);

        $status = Password::sendResetLink(
            $request->only('email')
        );

        if ($status !== Password::RESET_LINK_SENT) {
            return response()->json(['message' => 'Unable to send password recovery email.'], 500);
        }

        $routeGroup = $this->resolveRouteGroup($request);
        $user = User::where('email', $request->input('email'))->first();

        // Run the hook for its side effects, but SWALLOW any rejection: this
        // endpoint must return a uniform response whether or not the email
        // exists, so a rejecting afterPasswordRecover hook must never alter the
        // status (otherwise it becomes a user-enumeration oracle). Reject still
        // works for login/register/logout/reset; only recover is exempt.
        $this->runHook($routeGroup, 'afterPasswordRecover', $user, [
            'routeGroup' => $routeGroup,
            'organization' => $this->resolveOrganization($request),
            'token' => null,
            'request' => $request,
        ], null, swallowRejection: true);

        return response()->json(['message' => 'Password recovery email sent.'], 200);
    }

    public function reset(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required',
            'email' => 'required|email|exists:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function ($user, string $password) {
                $user->password = Hash::make($password);
                $user->save();
            }
        );

        if ($status !== Password::PASSWORD_RESET) {
            return response()->json(['message' => 'Token is invalid or expired.'], 400);
        }

        $routeGroup = $this->resolveRouteGroup($request);
        $user = User::where('email', $request->input('email'))->first();

        $rejection = $this->runHook($routeGroup, 'afterPasswordReset', $user, [
            'routeGroup' => $routeGroup,
            'organization' => $this->resolveOrganization($request),
            'token' => null,
            'request' => $request,
        ], null);
        if ($rejection !== null) {
            return $rejection;
        }

        return response()->json(['message' => 'Password has been reset.'], 200);
    }

    /**
     * Register a new user with an invitation token.
     */
    public function registerWithInvitation(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string|size:64',
            'name' => 'required|string|max:255',
            'email' => 'required|email|max:255|unique:users,email',
            'password' => 'required|string|min:8|confirmed',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $token = $request->input('token');

        $invitation = OrganizationInvitation::where('token', $token)
            ->where('status', 'pending')
            ->first();

        if (!$invitation) {
            return response()->json([
                'message' => 'Invalid or expired invitation token'
            ], 404);
        }

        if ($invitation->isExpired()) {
            $invitation->status = 'expired';
            $invitation->save();

            return response()->json([
                'message' => 'This invitation has expired'
            ], 422);
        }

        // Validate email matches invitation
        if ($invitation->email !== $request->input('email')) {
            return response()->json([
                'message' => 'Email does not match the invitation'
            ], 422);
        }

        // Create user
        $user = User::create([
            'name' => $request->input('name'),
            'email' => $request->input('email'),
            'password' => Hash::make($request->input('password')),
        ]);

        // Accept invitation (creates the membership row carrying route_group)
        $invitation->accept($user);

        // Capture the new token instance so a rejecting hook revokes ONLY this
        // freshly-issued token (design §7).
        $newToken = $user->createToken('API Token');
        $token = $newToken->plainTextToken;

        // Get organization slug for redirect
        $organization = $invitation->organization;
        $organizationSlug = $organization ? $organization->slug : null;

        // The group the invitee joined drives the afterRegister hook.
        $routeGroup = $invitation->route_group ?? $this->resolveRouteGroup($request);

        $rejection = $this->runHook($routeGroup, 'afterRegister', $user, [
            'routeGroup' => $routeGroup,
            'organization' => $organization,
            'token' => $token,
            'request' => $request,
        ], $newToken->accessToken);
        if ($rejection !== null) {
            return $rejection;
        }

        return response()->json([
            'message' => 'Registration successful',
            'token' => $token,
            'user' => $user,
            'organization_slug' => $organizationSlug,
        ], 201);
    }

    // ------------------------------------------------------------------
    // Group-aware auth helpers
    // ------------------------------------------------------------------

    /**
     * Resolve the route group key from the matched route's defaults.
     * Returns null for the legacy/global auth routes (no route_group default).
     */
    protected function resolveRouteGroup(Request $request): ?string
    {
        return $request->route()?->defaults['route_group'] ?? null;
    }

    /**
     * Resolve the organization context for the request, if any.
     * Tenant-group auth routes resolve org from the {organization} route param
     * (set on request attributes by ResolveOrganizationFromRoute middleware).
     */
    protected function resolveOrganization(Request $request): ?Organization
    {
        $organization = $request->attributes->get('organization');
        if ($organization instanceof Organization) {
            return $organization;
        }

        $route = $request->route();
        if ($route && $route->hasParameter('organization')) {
            $identifier = $route->parameter('organization');
            if ($identifier) {
                $column = config('rhino.multi_tenant.organization_identifier_column', 'id');
                return Organization::where($column, $identifier)->first();
            }
        }

        return null;
    }

    /**
     * Resolve the configured AuthLifecycleHooks instance for a route group.
     */
    protected function resolveHooks(?string $routeGroup): ?AuthLifecycleHooks
    {
        if ($routeGroup === null) {
            return null;
        }

        $hooksClass = config("rhino.route_groups.{$routeGroup}.hooks");

        if (!$hooksClass || !class_exists($hooksClass)) {
            return null;
        }

        $instance = app($hooksClass);

        return $instance instanceof AuthLifecycleHooks ? $instance : null;
    }

    /**
     * Run a lifecycle hook for the resolved group. Returns a JSON response when
     * the hook rejects; otherwise returns null.
     *
     * Reject semantics (design §7):
     *   - For token-issuing actions (login/register), pass the just-issued token
     *     instance as $issuedToken; on rejection ONLY that token is revoked, so a
     *     reject never nukes the user's other (pre-existing) sessions.
     *   - For non-token actions, pass null for $issuedToken.
     *   - $swallowRejection=true runs the hook for its side effects but suppresses
     *     any RhinoAuthRejected (used by recoverPassword so the endpoint stays a
     *     uniform, non-enumerating response regardless of the hook outcome).
     *
     * A hook that throws any OTHER exception (not RhinoAuthRejected) is treated
     * as a hook failure: the just-issued token (if any) is revoked and a uniform
     * 500 is returned, rather than letting an arbitrary exception leak past
     * token issuance with the token still live.
     *
     * @param  array<string, mixed>  $context
     * @param  \Laravel\Sanctum\PersonalAccessToken|\Illuminate\Database\Eloquent\Model|null  $issuedToken
     */
    protected function runHook(?string $routeGroup, string $event, $user, array $context, $issuedToken, bool $swallowRejection = false)
    {
        $hooks = $this->resolveHooks($routeGroup);

        if ($hooks === null || $user === null) {
            return null;
        }

        $context = array_merge(['user' => $user], $context);

        try {
            $hooks->{$event}($user, $context);
        } catch (RhinoAuthRejected $e) {
            if ($swallowRejection) {
                return null;
            }

            $this->revokeIssuedToken($issuedToken);

            return response()->json(['message' => $e->getMessage()], $e->getStatus());
        } catch (\Throwable $e) {
            // A non-reject exception after token issuance must not leave the new
            // token live: revoke it and surface a uniform 500.
            $this->revokeIssuedToken($issuedToken);

            return response()->json(['message' => 'Authentication hook failed.'], 500);
        }

        return null;
    }

    /**
     * Revoke a single just-issued personal access token instance (no-op when
     * null), without touching the user's other tokens.
     */
    protected function revokeIssuedToken($issuedToken): void
    {
        if ($issuedToken !== null && method_exists($issuedToken, 'delete')) {
            $issuedToken->delete();
        }
    }
}
