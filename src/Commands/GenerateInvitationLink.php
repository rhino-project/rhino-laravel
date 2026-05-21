<?php

namespace Rhino\Commands;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use Illuminate\Console\Command;
use Rhino\Models\OrganizationInvitation;

class GenerateInvitationLink extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'invitation:link {email} {organization} {--role= : Role ID or slug} {--create : Create a new invitation if one does not exist}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Generate an invitation link for a user (for testing)';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $email = $this->argument('email');
        $organizationIdentifier = $this->argument('organization');
        $roleIdentifier = $this->option('role');
        $create = $this->option('create');

        // Find organization
        $identifierColumn = config('rhino.multi_tenant.organization_identifier_column', 'slug');
        $organization = Organization::where($identifierColumn, $organizationIdentifier)->first();

        if (!$organization) {
            $this->error("Organization '{$organizationIdentifier}' not found.");
            return 1;
        }

        // Find or create invitation
        $invitation = OrganizationInvitation::where('email', $email)
            ->where('organization_id', $organization->id)
            ->where('status', 'pending')
            ->first();

        if (!$invitation && !$create) {
            $this->error("No pending invitation found for '{$email}' in organization '{$organization->name}'.");
            $this->info("Use --create flag to create a new invitation.");
            return 1;
        }

        // If invitation doesn't exist and --create flag is set
        if (!$invitation && $create) {
            // Get role
            if (!$roleIdentifier) {
                $this->error("Role is required when creating a new invitation. Use --role option.");
                return 1;
            }

            $role = is_numeric($roleIdentifier)
                ? Role::find($roleIdentifier)
                : Role::where('slug', $roleIdentifier)->first();

            if (!$role) {
                $this->error("Role '{$roleIdentifier}' not found.");
                return 1;
            }

            // Get invited_by user (use first admin user or user ID 1 as fallback)
            $invitedBy = User::whereHas('organizations', function ($q) use ($organization) {
                $q->where('organizations.id', $organization->id);
            })->first() ?? User::find(1);
            
            if (!$invitedBy) {
                $this->error("No user found to assign as 'invited_by'. Please create a user first.");
                return 1;
            }

            // Create invitation
            $invitation = OrganizationInvitation::create([
                'organization_id' => $organization->id,
                'email' => $email,
                'role_id' => $role->id,
                'invited_by' => $invitedBy->id,
            ]);

            $this->info("Created new invitation for {$email}.");
        }

        // Build the invitation URL
        $frontendUrl = env('FRONTEND_URL', 'http://localhost:5173');
        $url = "{$frontendUrl}/accept-invitation?token={$invitation->token}";

        $this->newLine();
        $this->info("Invitation link for {$email}:");
        $this->line($url);
        $this->newLine();
        $this->info("Token: {$invitation->token}");
        $this->info("Organization: {$organization->name} ({$organization->slug})");
        $this->info("Role: {$invitation->role->name}");
        $this->info("Status: {$invitation->status}");
        if ($invitation->expires_at) {
            $this->info("Expires: {$invitation->expires_at->format('Y-m-d H:i:s')}");
        }
        $this->newLine();

        return 0;
    }
}
