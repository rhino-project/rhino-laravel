<?php

namespace Rhino\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\SanctumServiceProvider;
use Rhino\Controllers\AuthController;
use Rhino\Controllers\InvitationController;
use Rhino\Models\OrganizationInvitation;
use Rhino\Policies\InvitationPolicy;
use Rhino\Tests\Feature\GroupAuthHooks\TestAuthHooks;
use Rhino\Tests\TestCase;

/**
 * Covers Part 3 (invitations carry the group) and the accept→membership flow
 * plus afterRegister hook. See GROUP_AUTH_DESIGN.md §8, §10.
 */
class GroupInvitationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        Schema::create('personal_access_tokens', function (Blueprint $table) {
            $table->id();
            $table->morphs('tokenable');
            $table->string('name');
            $table->string('token', 64)->unique();
            $table->text('abilities')->nullable();
            $table->timestamp('last_used_at')->nullable();
            $table->timestamp('expires_at')->nullable();
            $table->timestamps();
        });

        Schema::create('organization_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->nullable()->constrained()->onDelete('cascade');
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

        Mail::fake();
        Gate::policy(OrganizationInvitation::class, InvitationPolicy::class);
        TestAuthHooks::reset();

        config([
            'rhino.route_groups' => [
                'tenant' => ['prefix' => '{organization}', 'middleware' => [], 'models' => '*'],
                'driver' => ['prefix' => 'driver', 'auth' => true, 'hooks' => TestAuthHooks::class, 'middleware' => [], 'models' => '*'],
                'public' => ['prefix' => 'pub', 'middleware' => [], 'models' => '*'],
            ],
            'rhino.multi_tenant.organization_identifier_column' => 'slug',
        ]);

        $this->registerRoutes();
    }

    protected function tearDown(): void
    {
        TestAuthHooks::reset();
        parent::tearDown();
    }

    protected function getPackageProviders($app): array
    {
        return array_merge(parent::getPackageProviders($app), [SanctumServiceProvider::class]);
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('auth.guards.sanctum', ['driver' => 'sanctum', 'provider' => 'users']);
        $app['config']->set('auth.providers.users', ['driver' => 'eloquent', 'model' => \App\Models\User::class]);
    }

    protected function registerRoutes(): void
    {
        Route::middleware(['api'])->group(function () {
            Route::prefix('api/{organization}')->middleware('auth:sanctum')->group(function () {
                Route::post('invitations', [InvitationController::class, 'store']);
            });

            Route::post('api/invitations/accept', [InvitationController::class, 'accept']);
            Route::post('api/auth/register', [AuthController::class, 'registerWithInvitation']);
        });

        Route::matched(function ($event) {
            $orgSlug = $event->route->parameter('organization');
            if ($orgSlug) {
                $org = \App\Models\Organization::where('slug', $orgSlug)->first();
                if ($org) {
                    $event->request->attributes->set('organization', $org);
                }
            }
        });
    }

    protected function createOrg(string $slug = 'test-org'): \App\Models\Organization
    {
        return \App\Models\Organization::forceCreate(['name' => 'Org', 'slug' => $slug]);
    }

    protected function createRole(string $slug = 'member'): \App\Models\Role
    {
        return \App\Models\Role::forceCreate(['name' => ucfirst($slug), 'slug' => $slug]);
    }

    protected function createUserInOrg(\App\Models\Organization $org, \App\Models\Role $role): \App\Models\User
    {
        $user = \App\Models\User::forceCreate([
            'name' => 'Inviter',
            'email' => 'inviter-' . uniqid() . '@test.com',
            'password' => bcrypt('password'),
        ]);

        \App\Models\UserRole::forceCreate([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'route_group' => 'tenant',
            'permissions' => ['*'],
        ]);

        return $user;
    }

    // ==================================================================
    // store stores route_group
    // ==================================================================

    public function test_store_persists_route_group(): void
    {
        $org = $this->createOrg();
        $role = $this->createRole();
        $inviter = $this->createUserInOrg($org, $role);
        $this->actingAs($inviter, 'sanctum');

        $this->postJson("/api/{$org->slug}/invitations", [
            'email' => 'invitee@test.com',
            'role_id' => $role->id,
            'route_group' => 'driver',
        ])->assertStatus(201);

        $this->assertDatabaseHas('organization_invitations', [
            'email' => 'invitee@test.com',
            'route_group' => 'driver',
        ]);
    }

    public function test_store_without_route_group_keeps_it_null(): void
    {
        $org = $this->createOrg();
        $role = $this->createRole();
        $inviter = $this->createUserInOrg($org, $role);
        $this->actingAs($inviter, 'sanctum');

        $this->postJson("/api/{$org->slug}/invitations", [
            'email' => 'invitee@test.com',
            'role_id' => $role->id,
        ])->assertStatus(201);

        $this->assertDatabaseHas('organization_invitations', [
            'email' => 'invitee@test.com',
            'route_group' => null,
        ]);
    }

    public function test_cannot_invite_into_public_group(): void
    {
        $org = $this->createOrg();
        $role = $this->createRole();
        $inviter = $this->createUserInOrg($org, $role);
        $this->actingAs($inviter, 'sanctum');

        $this->postJson("/api/{$org->slug}/invitations", [
            'email' => 'invitee@test.com',
            'role_id' => $role->id,
            'route_group' => 'public',
        ])->assertStatus(422)->assertJson(['message' => "Cannot invite into group 'public'"]);
    }

    public function test_cannot_invite_into_nonexistent_group(): void
    {
        $org = $this->createOrg();
        $role = $this->createRole();
        $inviter = $this->createUserInOrg($org, $role);
        $this->actingAs($inviter, 'sanctum');

        $this->postJson("/api/{$org->slug}/invitations", [
            'email' => 'invitee@test.com',
            'role_id' => $role->id,
            'route_group' => 'ghost',
        ])->assertStatus(422);
    }

    // ==================================================================
    // Privilege-escalation guard (design §8 / fix #1)
    // ==================================================================

    public function test_non_member_inviter_cannot_mint_invite_into_other_group_when_enforced(): void
    {
        // Adversarial: a tenant-only inviter (member of the 'tenant' group for
        // this org, NOT the 'driver' group) must NOT be able to mint a 'driver'
        // invitation when enforcement is on.
        config(['rhino.auth.enforce_group_membership' => true]);

        $org = $this->createOrg();
        $role = $this->createRole();
        $inviter = $this->createUserInOrg($org, $role); // 'tenant' membership only
        $this->actingAs($inviter, 'sanctum');

        $this->postJson("/api/{$org->slug}/invitations", [
            'email' => 'invitee@test.com',
            'role_id' => $role->id,
            'route_group' => 'driver',
        ])->assertStatus(403)
          ->assertJson(['message' => "You are not a member of group 'driver'"]);

        $this->assertDatabaseMissing('organization_invitations', [
            'email' => 'invitee@test.com',
        ]);
    }

    public function test_member_inviter_can_mint_invite_into_their_group_when_enforced(): void
    {
        config(['rhino.auth.enforce_group_membership' => true]);

        $org = $this->createOrg();
        $role = $this->createRole();
        $inviter = $this->createUserInOrg($org, $role);
        // Also a member of the non-tenant 'driver' group (null org).
        \App\Models\UserRole::forceCreate([
            'user_id' => $inviter->id,
            'organization_id' => null,
            'role_id' => $role->id,
            'route_group' => 'driver',
            'permissions' => ['*'],
        ]);
        $this->actingAs($inviter, 'sanctum');

        $this->postJson("/api/{$org->slug}/invitations", [
            'email' => 'invitee@test.com',
            'role_id' => $role->id,
            'route_group' => 'driver',
        ])->assertStatus(201);
    }

    public function test_null_wildcard_member_can_mint_invite_into_any_group_when_enforced(): void
    {
        // A NULL-wildcard membership row counts as membership of every group.
        config(['rhino.auth.enforce_group_membership' => true]);

        $org = $this->createOrg();
        $role = $this->createRole();
        // Belongs to the org (so the InvitationPolicy's org gate passes) AND has a
        // NULL-wildcard membership — the wildcard alone satisfies the §8 guard for
        // ANY target group, including 'driver'.
        $inviter = $this->createUserInOrg($org, $role);
        \App\Models\UserRole::forceCreate([
            'user_id' => $inviter->id,
            'organization_id' => null,
            'role_id' => $role->id,
            'route_group' => null, // wildcard
            'permissions' => ['*'],
        ]);
        $this->actingAs($inviter, 'sanctum');

        $this->postJson("/api/{$org->slug}/invitations", [
            'email' => 'invitee@test.com',
            'role_id' => $role->id,
            'route_group' => 'driver',
        ])->assertStatus(201);
    }

    public function test_enforcement_off_does_not_gate_inviter_group_membership(): void
    {
        // Regression guard: with the flag off, no inviter-membership check runs.
        $org = $this->createOrg();
        $role = $this->createRole();
        $inviter = $this->createUserInOrg($org, $role); // 'tenant' only
        $this->actingAs($inviter, 'sanctum');

        $this->postJson("/api/{$org->slug}/invitations", [
            'email' => 'invitee@test.com',
            'role_id' => $role->id,
            'route_group' => 'driver',
        ])->assertStatus(201);
    }

    // ==================================================================
    // Non-tenant invite stores null org (design §3 / fix #3)
    // ==================================================================

    public function test_non_tenant_group_invite_stores_null_organization(): void
    {
        // Inviting into a non-tenant group (driver) from within a tenant org must
        // persist organization_id = null so accept() creates a null-org membership.
        $org = $this->createOrg();
        $role = $this->createRole();
        $inviter = $this->createUserInOrg($org, $role);
        $this->actingAs($inviter, 'sanctum');

        $this->postJson("/api/{$org->slug}/invitations", [
            'email' => 'driver-invitee@test.com',
            'role_id' => $role->id,
            'route_group' => 'driver',
        ])->assertStatus(201)
          ->assertJson(['organization_id' => null]);

        $this->assertDatabaseHas('organization_invitations', [
            'email' => 'driver-invitee@test.com',
            'route_group' => 'driver',
            'organization_id' => null,
        ]);
    }

    public function test_tenant_group_invite_keeps_organization(): void
    {
        $org = $this->createOrg();
        $role = $this->createRole();
        $inviter = $this->createUserInOrg($org, $role);
        $this->actingAs($inviter, 'sanctum');

        $this->postJson("/api/{$org->slug}/invitations", [
            'email' => 'tenant-invitee@test.com',
            'role_id' => $role->id,
            'route_group' => 'tenant',
        ])->assertStatus(201);

        $this->assertDatabaseHas('organization_invitations', [
            'email' => 'tenant-invitee@test.com',
            'route_group' => 'tenant',
            'organization_id' => $org->id,
        ]);
    }

    // ==================================================================
    // accept populates membership with route_group
    // ==================================================================

    public function test_accept_tenant_invitation_populates_membership_with_route_group(): void
    {
        $org = $this->createOrg();
        $role = $this->createRole();
        $inviter = $this->createUserInOrg($org, $role);

        $invitation = OrganizationInvitation::create([
            'organization_id' => $org->id,
            'route_group' => 'tenant',
            'email' => 'newbie@test.com',
            'role_id' => $role->id,
            'invited_by' => $inviter->id,
        ]);

        $newUser = \App\Models\User::forceCreate([
            'name' => 'Newbie', 'email' => 'newbie@test.com', 'password' => bcrypt('password'),
        ]);
        $this->actingAs($newUser, 'sanctum');

        $this->postJson('/api/invitations/accept', ['token' => $invitation->token])
            ->assertStatus(200);

        $this->assertDatabaseHas('user_roles', [
            'user_id' => $newUser->id,
            'organization_id' => $org->id,
            'route_group' => 'tenant',
            'role_id' => $role->id,
        ]);
    }

    public function test_accept_non_tenant_invitation_creates_membership_with_null_org(): void
    {
        $role = $this->createRole();
        $inviter = \App\Models\User::forceCreate([
            'name' => 'Inv', 'email' => 'inv@test.com', 'password' => bcrypt('password'),
        ]);

        // Non-tenant invitation: no organization, carries route_group.
        $invitation = OrganizationInvitation::create([
            'organization_id' => null,
            'route_group' => 'driver',
            'email' => 'driver1@test.com',
            'role_id' => $role->id,
            'invited_by' => $inviter->id,
        ]);

        $newUser = \App\Models\User::forceCreate([
            'name' => 'Driver', 'email' => 'driver1@test.com', 'password' => bcrypt('password'),
        ]);
        $this->actingAs($newUser, 'sanctum');

        $this->postJson('/api/invitations/accept', ['token' => $invitation->token])
            ->assertStatus(200);

        $this->assertDatabaseHas('user_roles', [
            'user_id' => $newUser->id,
            'organization_id' => null,
            'route_group' => 'driver',
            'role_id' => $role->id,
        ]);
    }

    public function test_register_with_invitation_populates_membership_and_fires_after_register(): void
    {
        $role = $this->createRole();
        $inviter = \App\Models\User::forceCreate([
            'name' => 'Inv', 'email' => 'inv2@test.com', 'password' => bcrypt('password'),
        ]);

        $invitation = OrganizationInvitation::create([
            'organization_id' => null,
            'route_group' => 'driver',
            'email' => 'reg@test.com',
            'role_id' => $role->id,
            'invited_by' => $inviter->id,
        ]);

        $this->postJson('/api/auth/register', [
            'token' => $invitation->token,
            'name' => 'Reg User',
            'email' => 'reg@test.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(201);

        $created = \App\Models\User::where('email', 'reg@test.com')->first();
        $this->assertNotNull($created);

        // Membership created with the invitation's route_group.
        $this->assertDatabaseHas('user_roles', [
            'user_id' => $created->id,
            'organization_id' => null,
            'route_group' => 'driver',
        ]);

        // afterRegister fired with the group from the invitation.
        $events = array_column(TestAuthHooks::$calls, 'event');
        $this->assertContains('afterRegister', $events);
        $registerCall = collect(TestAuthHooks::$calls)->firstWhere('event', 'afterRegister');
        $this->assertSame('driver', $registerCall['context']['routeGroup']);
    }

    public function test_cross_tenant_isolation_membership_not_created_in_other_org(): void
    {
        $orgA = $this->createOrg('org-a');
        $orgB = $this->createOrg('org-b');
        $role = $this->createRole();
        $inviter = $this->createUserInOrg($orgA, $role);

        $invitation = OrganizationInvitation::create([
            'organization_id' => $orgA->id,
            'route_group' => 'tenant',
            'email' => 'x@test.com',
            'role_id' => $role->id,
            'invited_by' => $inviter->id,
        ]);

        $newUser = \App\Models\User::forceCreate([
            'name' => 'X', 'email' => 'x@test.com', 'password' => bcrypt('password'),
        ]);
        $this->actingAs($newUser, 'sanctum');

        $this->postJson('/api/invitations/accept', ['token' => $invitation->token])->assertStatus(200);

        // Membership exists only for org A, never org B.
        $this->assertDatabaseHas('user_roles', ['user_id' => $newUser->id, 'organization_id' => $orgA->id]);
        $this->assertDatabaseMissing('user_roles', ['user_id' => $newUser->id, 'organization_id' => $orgB->id]);
    }
}
