<?php

namespace Rhino\Tests\Unit;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Schema;
use Rhino\Models\OrganizationInvitation;
use Rhino\Policies\InvitationPolicy;
use Rhino\Tests\TestCase;

class InvitationPolicyTest extends TestCase
{
    protected InvitationPolicy $policy;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

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

        $this->policy = new InvitationPolicy();
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function makeRequest(?Organization $org = null): Request
    {
        $request = new Request();
        if ($org) {
            $request->attributes->set('organization', $org);
        }
        return $request;
    }

    protected function createOrg(string $slug = 'test-org'): Organization
    {
        return Organization::forceCreate(['name' => 'Test Org', 'slug' => $slug]);
    }

    protected function createRole(string $slug = 'admin'): Role
    {
        return Role::forceCreate(['name' => ucfirst($slug), 'slug' => $slug]);
    }

    protected function createUserInOrg(Organization $org, Role $role, array $attrs = []): User
    {
        $user = User::forceCreate(array_merge([
            'name' => 'Test User',
            'email' => 'user-' . uniqid() . '@test.com',
            'password' => bcrypt('password'),
        ], $attrs));

        UserRole::forceCreate([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'permissions' => ['*'],
        ]);

        return $user;
    }

    // ======================================================================
    // viewAny
    // ======================================================================

    public function test_view_any_denies_null_user(): void
    {
        $org = $this->createOrg();
        $request = $this->makeRequest($org);

        $response = $this->policy->viewAny(null, $request);
        $this->assertFalse($response->allowed());
    }

    public function test_view_any_denies_without_organization(): void
    {
        $user = User::forceCreate(['name' => 'No Org', 'email' => 'noorg@test.com', 'password' => bcrypt('pw')]);
        $request = $this->makeRequest(null);

        $response = $this->policy->viewAny($user, $request);
        $this->assertFalse($response->allowed());
    }

    public function test_view_any_denies_user_not_in_org(): void
    {
        $org = $this->createOrg();
        $user = User::forceCreate(['name' => 'Outsider', 'email' => 'outsider@test.com', 'password' => bcrypt('pw')]);
        $request = $this->makeRequest($org);

        $response = $this->policy->viewAny($user, $request);
        $this->assertFalse($response->allowed());
    }

    public function test_view_any_allows_user_in_org(): void
    {
        $org = $this->createOrg();
        $role = $this->createRole();
        $user = $this->createUserInOrg($org, $role);
        $request = $this->makeRequest($org);

        $response = $this->policy->viewAny($user, $request);
        $this->assertTrue($response->allowed());
    }

    // ======================================================================
    // create
    // ======================================================================

    public function test_create_denies_null_user(): void
    {
        $org = $this->createOrg();
        $request = $this->makeRequest($org);

        $response = $this->policy->create(null, $request);
        $this->assertFalse($response->allowed());
    }

    public function test_create_denies_without_organization(): void
    {
        $user = User::forceCreate(['name' => 'No Org', 'email' => 'noorg2@test.com', 'password' => bcrypt('pw')]);
        $request = $this->makeRequest(null);

        $response = $this->policy->create($user, $request);
        $this->assertFalse($response->allowed());
    }

    public function test_create_denies_user_not_in_org(): void
    {
        $org = $this->createOrg();
        $user = User::forceCreate(['name' => 'Outsider', 'email' => 'outsider2@test.com', 'password' => bcrypt('pw')]);
        $request = $this->makeRequest($org);

        $response = $this->policy->create($user, $request);
        $this->assertFalse($response->allowed());
    }

    public function test_create_allows_user_in_org_without_role_restriction(): void
    {
        $org = $this->createOrg();
        $role = $this->createRole();
        $user = $this->createUserInOrg($org, $role);
        $request = $this->makeRequest($org);

        // No allowed_roles config = anyone in org can invite
        config(['rhino.invitations.allowed_roles' => null]);

        $response = $this->policy->create($user, $request);
        $this->assertTrue($response->allowed());
    }

    public function test_create_denies_user_without_allowed_role(): void
    {
        $org = $this->createOrg();
        $role = $this->createRole('viewer');
        $user = $this->createUserInOrg($org, $role);
        $request = $this->makeRequest($org);

        config(['rhino.invitations.allowed_roles' => ['admin']]);

        $response = $this->policy->create($user, $request);
        $this->assertFalse($response->allowed());
    }

    public function test_create_allows_user_with_allowed_role(): void
    {
        $org = $this->createOrg();
        $role = $this->createRole('admin');
        $user = $this->createUserInOrg($org, $role);
        $request = $this->makeRequest($org);

        config(['rhino.invitations.allowed_roles' => ['admin']]);

        $response = $this->policy->create($user, $request);
        $this->assertTrue($response->allowed());
    }

    // ======================================================================
    // update
    // ======================================================================

    public function test_update_denies_null_user(): void
    {
        $org = $this->createOrg();
        $role = $this->createRole();
        $inviter = $this->createUserInOrg($org, $role);
        $invitation = OrganizationInvitation::create([
            'organization_id' => $org->id,
            'email' => 'test@test.com',
            'role_id' => $role->id,
            'invited_by' => $inviter->id,
        ]);
        $request = $this->makeRequest($org);

        $response = $this->policy->update(null, $invitation, $request);
        $this->assertFalse($response->allowed());
    }

    public function test_update_denies_without_organization(): void
    {
        $org = $this->createOrg();
        $role = $this->createRole();
        $user = $this->createUserInOrg($org, $role);
        $invitation = OrganizationInvitation::create([
            'organization_id' => $org->id,
            'email' => 'test@test.com',
            'role_id' => $role->id,
            'invited_by' => $user->id,
        ]);
        $request = $this->makeRequest(null);

        $response = $this->policy->update($user, $invitation, $request);
        $this->assertFalse($response->allowed());
    }

    public function test_update_denies_invitation_from_different_org(): void
    {
        $orgA = $this->createOrg('org-a');
        $orgB = $this->createOrg('org-b');
        $role = $this->createRole();
        $user = $this->createUserInOrg($orgA, $role);
        $invitation = OrganizationInvitation::create([
            'organization_id' => $orgB->id,
            'email' => 'test@test.com',
            'role_id' => $role->id,
            'invited_by' => $user->id,
        ]);
        $request = $this->makeRequest($orgA);

        $response = $this->policy->update($user, $invitation, $request);
        $this->assertFalse($response->allowed());
    }

    public function test_update_denies_non_pending_invitation(): void
    {
        $org = $this->createOrg();
        $role = $this->createRole();
        $user = $this->createUserInOrg($org, $role);
        $invitation = OrganizationInvitation::create([
            'organization_id' => $org->id,
            'email' => 'test@test.com',
            'role_id' => $role->id,
            'invited_by' => $user->id,
        ]);
        $invitation->status = 'accepted';
        $invitation->save();
        $request = $this->makeRequest($org);

        $response = $this->policy->update($user, $invitation, $request);
        $this->assertFalse($response->allowed());
    }

    public function test_update_allows_pending_invitation_in_same_org(): void
    {
        $org = $this->createOrg();
        $role = $this->createRole();
        $user = $this->createUserInOrg($org, $role);

        // Verify user is actually in org
        $userBelongs = $user->organizations()
            ->where('organizations.id', $org->id)
            ->exists();
        $this->assertTrue($userBelongs, 'User should belong to org via user_roles');

        $invitation = OrganizationInvitation::create([
            'organization_id' => $org->id,
            'email' => 'allowed@test.com',
            'role_id' => $role->id,
            'invited_by' => $user->id,
            'status' => 'pending',
        ]);

        $request = $this->makeRequest($org);

        $response = $this->policy->update($user, $invitation, $request);
        $this->assertTrue($response->allowed(), 'Policy should allow update on pending invitation in same org. Response: ' . ($response->message() ?? 'no message'));
    }

    // ======================================================================
    // delete (delegates to update)
    // ======================================================================

    public function test_delete_delegates_to_update(): void
    {
        $org = $this->createOrg();
        $role = $this->createRole();
        $user = $this->createUserInOrg($org, $role);
        $invitation = OrganizationInvitation::create([
            'organization_id' => $org->id,
            'email' => 'test@test.com',
            'role_id' => $role->id,
            'invited_by' => $user->id,
        ]);
        $request = $this->makeRequest($org);

        $deleteResponse = $this->policy->delete($user, $invitation, $request);
        $updateResponse = $this->policy->update($user, $invitation, $request);

        $this->assertEquals($updateResponse->allowed(), $deleteResponse->allowed());
    }
}
