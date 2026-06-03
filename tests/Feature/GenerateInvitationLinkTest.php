<?php

namespace Rhino\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rhino\Models\OrganizationInvitation;
use Rhino\Tests\TestCase;

class GenerateInvitationLinkTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Create the organization_invitations table (not in test migrations)
        Schema::create('organization_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->string('email')->index();
            $table->string('route_group')->nullable();
            $table->foreignId('role_id')->constrained()->onDelete('cascade');
            $table->string('token', 64)->unique()->index();
            $table->foreignId('invited_by')->constrained('users')->onDelete('cascade');
            $table->string('status')->default('pending');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        // Default: use slug as identifier (matches command's config default)
        $app['config']->set('rhino.multi_tenant.organization_identifier_column', 'slug');
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function createOrganization(array $attributes = []): \App\Models\Organization
    {
        return \App\Models\Organization::forceCreate(array_merge([
            'name' => 'Test Organization',
            'slug' => 'test-org',
        ], $attributes));
    }

    protected function createRole(array $attributes = []): \App\Models\Role
    {
        return \App\Models\Role::forceCreate(array_merge([
            'name' => 'Editor',
            'slug' => 'editor',
        ], $attributes));
    }

    protected function createUser(array $attributes = []): \App\Models\User
    {
        return \App\Models\User::forceCreate(array_merge([
            'name' => 'Test User',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
        ], $attributes));
    }

    protected function createUserInOrganization(\App\Models\Organization $org, \App\Models\Role $role, array $userAttributes = []): \App\Models\User
    {
        $user = $this->createUser($userAttributes);

        \App\Models\UserRole::forceCreate([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'permissions' => ['*'],
        ]);

        return $user;
    }

    protected function createInvitation(\App\Models\Organization $org, \App\Models\Role $role, \App\Models\User $invitedBy, array $attributes = []): OrganizationInvitation
    {
        return OrganizationInvitation::create(array_merge([
            'organization_id' => $org->id,
            'email' => 'invitee@example.com',
            'role_id' => $role->id,
            'invited_by' => $invitedBy->id,
        ], $attributes));
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    public function test_returns_error_when_organization_not_found(): void
    {
        $this->artisan('invitation:link', [
            'email' => 'invitee@example.com',
            'organization' => 'non-existent-org',
        ])
            ->expectsOutputToContain("Organization 'non-existent-org' not found.")
            ->assertExitCode(1);
    }

    public function test_returns_error_when_no_pending_invitation_exists(): void
    {
        $org = $this->createOrganization();

        $this->artisan('invitation:link', [
            'email' => 'invitee@example.com',
            'organization' => 'test-org',
        ])
            ->expectsOutputToContain("No pending invitation found for 'invitee@example.com'")
            ->expectsOutputToContain('Use --create flag to create a new invitation.')
            ->assertExitCode(1);
    }

    public function test_returns_error_when_creating_without_role(): void
    {
        $org = $this->createOrganization();

        $this->artisan('invitation:link', [
            'email' => 'invitee@example.com',
            'organization' => 'test-org',
            '--create' => true,
        ])
            ->expectsOutputToContain('Role is required when creating a new invitation. Use --role option.')
            ->assertExitCode(1);
    }

    public function test_returns_error_when_role_not_found(): void
    {
        $org = $this->createOrganization();

        $this->artisan('invitation:link', [
            'email' => 'invitee@example.com',
            'organization' => 'test-org',
            '--create' => true,
            '--role' => 'non-existent-role',
        ])
            ->expectsOutputToContain("Role 'non-existent-role' not found.")
            ->assertExitCode(1);
    }

    public function test_creates_invitation_with_create_flag(): void
    {
        $org = $this->createOrganization();
        $role = $this->createRole();
        $user = $this->createUserInOrganization($org, $role);

        $this->artisan('invitation:link', [
            'email' => 'newuser@example.com',
            'organization' => 'test-org',
            '--create' => true,
            '--role' => 'editor',
        ])
            ->expectsOutputToContain('Created new invitation for newuser@example.com.')
            ->expectsOutputToContain('Invitation link for newuser@example.com:')
            ->expectsOutputToContain("Organization: {$org->name} ({$org->slug})")
            ->expectsOutputToContain("Role: {$role->name}")
            ->assertExitCode(0);

        $this->assertDatabaseHas('organization_invitations', [
            'email' => 'newuser@example.com',
            'organization_id' => $org->id,
            'role_id' => $role->id,
            'invited_by' => $user->id,
            'status' => 'pending',
        ]);
    }

    public function test_shows_existing_invitation(): void
    {
        $org = $this->createOrganization();
        $role = $this->createRole();
        $user = $this->createUserInOrganization($org, $role);
        $invitation = $this->createInvitation($org, $role, $user, [
            'email' => 'existing@example.com',
        ]);

        $this->artisan('invitation:link', [
            'email' => 'existing@example.com',
            'organization' => 'test-org',
        ])
            ->expectsOutputToContain('Invitation link for existing@example.com:')
            ->expectsOutputToContain("Token: {$invitation->token}")
            ->expectsOutputToContain("Organization: {$org->name} ({$org->slug})")
            ->expectsOutputToContain("Role: {$role->name}")
            ->expectsOutputToContain('Status: pending')
            ->assertExitCode(0);
    }

    public function test_finds_organization_by_configured_identifier_column(): void
    {
        // Override config to use 'id' as identifier column
        config(['rhino.multi_tenant.organization_identifier_column' => 'id']);

        $org = $this->createOrganization(['id' => 42]);
        $role = $this->createRole();
        $user = $this->createUserInOrganization($org, $role);
        $invitation = $this->createInvitation($org, $role, $user, [
            'email' => 'byid@example.com',
        ]);

        // Pass the organization ID instead of slug
        $this->artisan('invitation:link', [
            'email' => 'byid@example.com',
            'organization' => '42',
        ])
            ->expectsOutputToContain('Invitation link for byid@example.com:')
            ->expectsOutputToContain("Token: {$invitation->token}")
            ->assertExitCode(0);

        // Verify using slug fails when identifier is set to 'id'
        $this->artisan('invitation:link', [
            'email' => 'byid@example.com',
            'organization' => 'test-org',
        ])
            ->expectsOutputToContain("Organization 'test-org' not found.")
            ->assertExitCode(1);
    }
}
