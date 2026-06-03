<?php

namespace Rhino\Controllers;

use Illuminate\Http\Request;
use Illuminate\Routing\Controller;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Validator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Mail;
use Rhino\Http\Middleware\EnforceGroupMembership;
use Rhino\Models\OrganizationInvitation;
use Rhino\Notifications\InvitationNotification;
use App\Models\User;
use App\Models\Organization;

class InvitationController extends Controller
{
    /**
     * Display a listing of invitations for the organization.
     */
    public function index(Request $request, string $organization)
    {
        $org = $request->attributes->get('organization');
        
        Gate::forUser($request->user('sanctum'))->authorize('viewAny', [OrganizationInvitation::class, $request]);

        $status = $request->query('status', 'all');
        
        $query = OrganizationInvitation::where('organization_id', $org->id)
            ->with(['organization', 'role', 'invitedBy']);

        if ($status !== 'all') {
            if ($status === 'pending') {
                $query->pending();
            } elseif ($status === 'expired') {
                $query->expired();
            } else {
                $query->where('status', $status);
            }
        }

        return $query->orderBy('created_at', 'desc')->get();
    }

    /**
     * Store a newly created invitation.
     */
    public function store(Request $request, string $organization)
    {
        $org = $request->attributes->get('organization');
        $user = $request->user('sanctum');

        Gate::forUser($user)->authorize('create', [OrganizationInvitation::class, $request]);

        $validator = Validator::make($request->all(), [
            'email' => 'required|email|max:255',
            'role_id' => 'required|exists:roles,id',
            'route_group' => 'nullable|string',
        ]);

        if ($validator->fails()) {
            return response()->json(['errors' => $validator->errors()], 422);
        }

        $email = $request->input('email');
        $roleId = $request->input('role_id');
        $routeGroup = $request->input('route_group');

        // Validate the requested route_group: it must be a real, configured,
        // non-public group. The 'public' group is never auth-enabled and so
        // cannot be invited into.
        if ($routeGroup !== null) {
            $routeGroups = config('rhino.route_groups', []);

            if (!isset($routeGroups[$routeGroup]) || $routeGroup === 'public') {
                return response()->json([
                    'message' => "Cannot invite into group '{$routeGroup}'",
                ], 422);
            }

            // Privilege-escalation guard (design §8): when enforcement is on and
            // the target group is concrete (non-null), the inviter must itself be
            // a member of that group — otherwise a tenant-only inviter could mint
            // an invitation into a group (e.g. admin) they cannot enter. A
            // NULL-wildcard membership row counts as membership of every group.
            //
            // Tenancy of the target group decides the org dimension of the check:
            // for a tenant group the inviter's membership must be scoped to this
            // org; for a non-tenant group org is ignored (null-org membership).
            if (config('rhino.auth.enforce_group_membership', false)) {
                $targetIsTenant = $this->groupIsTenant($routeGroup, $routeGroups);
                $membershipOrg = $targetIsTenant ? $org : null;

                if (!EnforceGroupMembership::isMember($user, $routeGroup, $membershipOrg)) {
                    return response()->json([
                        'message' => "You are not a member of group '{$routeGroup}'",
                    ], 403);
                }
            }
        }

        // Check if user already exists and is in organization
        $existingUser = User::where('email', $email)->first();
        if ($existingUser) {
            $userInOrg = $existingUser->organizations()
                ->where('organizations.id', $org->id)
                ->exists();
            
            if ($userInOrg) {
                return response()->json([
                    'message' => 'User is already a member of this organization'
                ], 422);
            }
        }

        // Check for existing pending invitation
        $existingInvitation = OrganizationInvitation::where('email', $email)
            ->where('organization_id', $org->id)
            ->where('status', 'pending')
            ->where(function ($q) {
                $q->whereNull('expires_at')
                  ->orWhere('expires_at', '>', now());
            })
            ->first();

        if ($existingInvitation) {
            return response()->json([
                'message' => 'A pending invitation already exists for this email'
            ], 422);
        }

        // Create invitation (carrying the target route_group, if any).
        //
        // Non-tenant target groups have no organization, so persist a NULL
        // organization_id (design §3/§8) — accept() then creates a null-org
        // membership row. Tenant (and legacy null-group) invites keep the org.
        $isNonTenantGroup = $routeGroup !== null
            && !$this->groupIsTenant($routeGroup, config('rhino.route_groups', []));

        $invitation = OrganizationInvitation::create([
            'organization_id' => $isNonTenantGroup ? null : $org->id,
            'route_group' => $routeGroup,
            'email' => $email,
            'role_id' => $roleId,
            'invited_by' => $user->id,
        ]);

        // Send notification using Mail::to() to avoid serialization issues with anonymous classes
        Mail::to($email)->send(new InvitationNotification($invitation));

        return response()->json($invitation->load(['organization', 'role', 'invitedBy']), 201);
    }

    /**
     * Determine whether a configured route group is tenant-scoped (carries an
     * {organization} parameter in its prefix or domain). Non-tenant groups have
     * no organization context, so their memberships/invitations use a null org.
     *
     * @param  array<string, mixed>  $routeGroups
     */
    protected function groupIsTenant(string $routeGroup, array $routeGroups): bool
    {
        $config = $routeGroups[$routeGroup] ?? [];

        $prefix = $config['prefix'] ?? '';
        $domain = $config['domain'] ?? '';

        return str_contains($prefix, '{organization}')
            || str_contains((string) $domain, '{organization}');
    }

    /**
     * Resend an invitation email.
     */
    public function resend(Request $request, string $organization, $id)
    {
        $org = $request->attributes->get('organization');
        $user = $request->user('sanctum');

        $invitation = OrganizationInvitation::where('id', $id)
            ->where('organization_id', $org->id)
            ->firstOrFail();

        Gate::forUser($user)->authorize('update', [$invitation, $request]);

        if ($invitation->status !== 'pending') {
            return response()->json([
                'message' => 'Only pending invitations can be resent'
            ], 422);
        }

        // Update expiration
        $days = config('rhino.invitations.expires_days', 7);
        $invitation->expires_at = now()->addDays($days);
        $invitation->save();

        // Resend notification using Mail::to() to avoid serialization issues
        Mail::to($invitation->email)->send(new InvitationNotification($invitation));

        return response()->json([
            'message' => 'Invitation resent successfully',
            'invitation' => $invitation->load(['organization', 'role', 'invitedBy'])
        ]);
    }

    /**
     * Cancel an invitation.
     */
    public function cancel(Request $request, string $organization, $id)
    {
        $org = $request->attributes->get('organization');
        $user = $request->user('sanctum');

        $invitation = OrganizationInvitation::where('id', $id)
            ->where('organization_id', $org->id)
            ->firstOrFail();

        Gate::forUser($user)->authorize('delete', [$invitation, $request]);

        if ($invitation->status !== 'pending') {
            return response()->json([
                'message' => 'Only pending invitations can be cancelled'
            ], 422);
        }

        $invitation->status = 'cancelled';
        $invitation->save();

        return response()->json([
            'message' => 'Invitation cancelled successfully'
        ]);
    }

    /**
     * Accept an invitation (public route).
     */
    public function accept(Request $request)
    {
        $validator = Validator::make($request->all(), [
            'token' => 'required|string|size:64',
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

        // Check if user is authenticated
        $user = $request->user('sanctum');
        
        if (!$user) {
            // Return invitation details for frontend to handle registration
            return response()->json([
                'invitation' => $invitation->load(['organization', 'role']),
                'requires_registration' => true,
                'message' => 'Please register or login to accept this invitation'
            ], 200);
        }

        // User is authenticated, accept invitation
        if ($invitation->accept($user)) {
            return response()->json([
                'message' => 'Invitation accepted successfully',
                'invitation' => $invitation->load(['organization', 'role']),
                'organization' => $invitation->organization,
            ], 200);
        }

        return response()->json([
            'message' => 'Failed to accept invitation'
        ], 500);
    }
}
