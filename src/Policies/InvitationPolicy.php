<?php

namespace Rhino\Policies;

use Illuminate\Auth\Access\Response;
use Illuminate\Http\Request;
use Rhino\Models\OrganizationInvitation;

class InvitationPolicy
{
    /**
     * Determine if the user can view any invitations.
     */
    public function viewAny($user, Request $request): Response
    {
        if (!$user) {
            return Response::deny('Authentication required');
        }

        // Get organization from request (set by middleware)
        $organization = $request->attributes->get('organization');
        
        if (!$organization) {
            return Response::deny('Organization not found');
        }

        // User must belong to the organization
        $userBelongsToOrg = $user->organizations()
            ->where('organizations.id', $organization->id)
            ->exists();

        return $userBelongsToOrg
            ? Response::allow()
            : Response::deny('You do not have access to this organization');
    }

    /**
     * Determine if the user can create invitations.
     */
    public function create($user, Request $request): Response
    {
        if (!$user) {
            return Response::deny('Authentication required');
        }

        // Get organization from request
        $organization = $request->attributes->get('organization');
        
        if (!$organization) {
            return Response::deny('Organization not found');
        }

        // User must belong to the organization
        $userBelongsToOrg = $user->organizations()
            ->where('organizations.id', $organization->id)
            ->exists();

        if (!$userBelongsToOrg) {
            return Response::deny('You do not have access to this organization');
        }

        // Check if user has permission to invite (check allowed roles)
        $allowedRoles = config('rhino.invitations.allowed_roles');
        
        if ($allowedRoles !== null && is_array($allowedRoles)) {
            $userRoles = $user->rolesInOrganization($organization)
                ->whereIn('slug', $allowedRoles)
                ->exists();
            
            if (!$userRoles) {
                return Response::deny('You do not have permission to invite users');
            }
        }

        return Response::allow();
    }

    /**
     * Determine if the user can update the invitation.
     */
    public function update($user, OrganizationInvitation $invitation, Request $request): Response
    {
        if (!$user) {
            return Response::deny('Authentication required');
        }

        // Get organization from request
        $organization = $request->attributes->get('organization');
        
        if (!$organization) {
            return Response::deny('Organization not found');
        }

        // Invitation must belong to the organization
        if ($invitation->organization_id !== $organization->id) {
            return Response::deny('Invitation does not belong to this organization');
        }

        // User must belong to the organization
        $userBelongsToOrg = $user->organizations()
            ->where('organizations.id', $organization->id)
            ->exists();

        if (!$userBelongsToOrg) {
            return Response::deny('You do not have access to this organization');
        }

        // Only pending invitations can be updated
        if ($invitation->status !== 'pending') {
            return Response::deny('Only pending invitations can be updated');
        }

        return Response::allow();
    }

    /**
     * Determine if the user can delete the invitation.
     */
    public function delete($user, OrganizationInvitation $invitation, Request $request): Response
    {
        return $this->update($user, $invitation, $request);
    }
}
