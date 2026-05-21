<?php

namespace Rhino\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rhino\Models\OrganizationInvitation;
use Rhino\Tests\TestCase;

class GenerateInvitationLinkExtendedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        Schema::create('organization_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->string('email')->index();
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
        $app['config']->set('rhino.multi_tenant.organization_identifier_column', 'slug');
    }

    protected function createOrganization(array $attrs = []): \App\Models\Organization
    {
        return \App\Models\Organization::forceCreate(array_merge([
            'name' => 'Test Organization',
            'slug' => 'test-org',
        ], $attrs));
    }

    protected function createRole(array $attrs = []): \App\Models\Role
    {
        return \App\Models\Role::forceCreate(array_merge([
            'name' => 'Editor',
            'slug' => 'editor',
        ], $attrs));
    }

    protected function createUser(array $attrs = []): \App\Models\User
    {
        return \App\Models\User::forceCreate(array_merge([
            'name' => 'Test User',
            'email' => 'admin@example.com',
            'password' => bcrypt('password'),
        ], $attrs));
    }

    protected function createUserInOrganization(\App\Models\Organization $org, \App\Models\Role $role, array $userAttrs = []): \App\Models\User
    {
        $user = $this->createUser($userAttrs);

        \App\Models\UserRole::forceCreate([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'permissions' => ['*'],
        ]);

        return $user;
    }

    // ------------------------------------------------------------------
    // Tests for uncovered code paths
    // ------------------------------------------------------------------

    public function test_creates_invitation_with_numeric_role_id(): void
    {
        $org = $this->createOrganization();
        $role = $this->createRole();
        $user = $this->createUserInOrganization($org, $role);

        $this->artisan('invitation:link', [
            'email' => 'numrole@example.com',
            'organization' => 'test-org',
            '--create' => true,
            '--role' => (string) $role->id,
        ])
            ->expectsOutputToContain('Created new invitation for numrole@example.com.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('organization_invitations', [
            'email' => 'numrole@example.com',
            'role_id' => $role->id,
        ]);
    }

    public function test_returns_error_when_no_user_found_for_invited_by(): void
    {
        $org = $this->createOrganization();
        $role = $this->createRole();
        // No users in the org or in the system at all

        $this->artisan('invitation:link', [
            'email' => 'nouser@example.com',
            'organization' => 'test-org',
            '--create' => true,
            '--role' => 'editor',
        ])
            ->expectsOutputToContain("No user found to assign as 'invited_by'")
            ->assertExitCode(1);
    }

    public function test_creates_invitation_using_fallback_user(): void
    {
        $org = $this->createOrganization();
        $role = $this->createRole();

        // Create a user with id=1 (fallback) who is NOT in the org
        $fallbackUser = $this->createUser(['id' => 1]);

        $this->artisan('invitation:link', [
            'email' => 'fallback@example.com',
            'organization' => 'test-org',
            '--create' => true,
            '--role' => 'editor',
        ])
            ->expectsOutputToContain('Created new invitation for fallback@example.com.')
            ->assertExitCode(0);

        $this->assertDatabaseHas('organization_invitations', [
            'email' => 'fallback@example.com',
            'invited_by' => $fallbackUser->id,
        ]);
    }

    public function test_shows_invitation_without_expires_at(): void
    {
        $org = $this->createOrganization();
        $role = $this->createRole();
        $user = $this->createUserInOrganization($org, $role);

        $invitation = OrganizationInvitation::create([
            'organization_id' => $org->id,
            'email' => 'noexpiry@example.com',
            'role_id' => $role->id,
            'invited_by' => $user->id,
            'status' => 'pending',
        ]);

        // Manually set expires_at to null
        $invitation->expires_at = null;
        $invitation->save();

        $this->artisan('invitation:link', [
            'email' => 'noexpiry@example.com',
            'organization' => 'test-org',
        ])
            ->expectsOutputToContain('Invitation link for noexpiry@example.com:')
            ->assertExitCode(0);
    }
}
