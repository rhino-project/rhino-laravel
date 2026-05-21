<?php

namespace Rhino\Tests\Feature;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Rhino\Controllers\InvitationController;
use Rhino\Models\OrganizationInvitation;
use Rhino\Notifications\InvitationNotification;
use Rhino\Policies\InvitationPolicy;
use Rhino\Tests\TestCase;

class InvitationControllerTest extends TestCase
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

        Mail::fake();
        Gate::policy(OrganizationInvitation::class, InvitationPolicy::class);

        $this->registerInvitationRoutes();
    }

    protected function getPackageProviders($app): array
    {
        return array_merge(parent::getPackageProviders($app), [
            \Laravel\Sanctum\SanctumServiceProvider::class,
        ]);
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('rhino.multi_tenant.organization_identifier_column', 'slug');
        $app['config']->set('rhino.invitations.expires_days', 7);
        $app['config']->set('auth.guards.sanctum', [
            'driver' => 'sanctum',
            'provider' => 'users',
        ]);
    }

    // ------------------------------------------------------------------
    // Route Registration
    // ------------------------------------------------------------------

    protected function registerInvitationRoutes(): void
    {
        Route::middleware(['api'])->group(function () {
            Route::prefix('api/{organization}')->middleware('auth:sanctum')->group(function () {
                Route::get('invitations', [InvitationController::class, 'index']);
                Route::post('invitations', [InvitationController::class, 'store']);
                Route::post('invitations/{id}/resend', [InvitationController::class, 'resend']);
                Route::post('invitations/{id}/cancel', [InvitationController::class, 'cancel']);
            });

            Route::post('api/invitations/accept', [InvitationController::class, 'accept']);
        });

        // Add middleware to set organization on request
        Route::matched(function ($event) {
            $route = $event->route;
            $request = $event->request;
            $orgSlug = $route->parameter('organization');

            if ($orgSlug) {
                $org = Organization::where('slug', $orgSlug)->first();
                if ($org) {
                    $request->attributes->set('organization', $org);
                }
            }
        });
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function createOrg(string $slug = 'test-org'): Organization
    {
        return Organization::forceCreate([
            'name' => 'Test Org',
            'slug' => $slug,
        ]);
    }

    protected function createRole(string $slug = 'admin'): Role
    {
        return Role::forceCreate([
            'name' => ucfirst($slug),
            'slug' => $slug,
        ]);
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

    protected function createInvitation(Organization $org, Role $role, User $invitedBy, array $attrs = []): OrganizationInvitation
    {
        return OrganizationInvitation::create(array_merge([
            'organization_id' => $org->id,
            'email' => 'invitee@test.com',
            'role_id' => $role->id,
            'invited_by' => $invitedBy->id,
        ], $attrs));
    }

    // ======================================================================
    // INDEX
    // ======================================================================

    public function test_index_returns_all_invitations(): void
    {
        $org = $this->createOrg();
        $role = $this->createRole();
        $user = $this->createUserInOrg($org, $role);

        $this->createInvitation($org, $role, $user, ['email' => 'a@test.com']);
        $this->createInvitation($org, $role, $user, ['email' => 'b@test.com']);

        $this->actingAs($user, 'sanctum');
        $response = $this->getJson('/api/test-org/invitations');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json());
    }

    public function test_index_filters_by_pending_status(): void
    {
        $org = $this->createOrg();
        $role = $this->createRole();
        $user = $this->createUserInOrg($org, $role);

        $this->createInvitation($org, $role, $user, ['email' => 'pending@test.com', 'status' => 'pending', 'expires_at' => now()->addDays(7)]);
        $this->createInvitation($org, $role, $user, ['email' => 'accepted@test.com', 'status' => 'accepted']);

        $this->actingAs($user, 'sanctum');
        $response = $this->getJson('/api/test-org/invitations?status=pending');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(1, $data);
        $this->assertEquals('pending@test.com', $data[0]['email']);
    }

    public function test_index_filters_by_expired_status(): void
    {
        $org = $this->createOrg();
        $role = $this->createRole();
        $user = $this->createUserInOrg($org, $role);

        $this->createInvitation($org, $role, $user, ['email' => 'expired@test.com', 'expires_at' => now()->subDay()]);
        $this->createInvitation($org, $role, $user, ['email' => 'fresh@test.com', 'expires_at' => now()->addDays(7)]);

        $this->actingAs($user, 'sanctum');
        $response = $this->getJson('/api/test-org/invitations?status=expired');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(1, $data);
        $this->assertEquals('expired@test.com', $data[0]['email']);
    }

    public function test_index_filters_by_custom_status(): void
    {
        $org = $this->createOrg();
        $role = $this->createRole();
        $user = $this->createUserInOrg($org, $role);

        $this->createInvitation($org, $role, $user, ['email' => 'cancelled@test.com', 'status' => 'cancelled']);
        $this->createInvitation($org, $role, $user, ['email' => 'pending@test.com', 'status' => 'pending']);

        $this->actingAs($user, 'sanctum');
        $response = $this->getJson('/api/test-org/invitations?status=cancelled');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertCount(1, $data);
        $this->assertEquals('cancelled@test.com', $data[0]['email']);
    }

    // ======================================================================
    // STORE
    // ======================================================================

    public function test_store_creates_invitation_and_sends_email(): void
    {
        $org = $this->createOrg();
        $role = $this->createRole();
        $user = $this->createUserInOrg($org, $role);

        $this->actingAs($user, 'sanctum');
        $response = $this->postJson('/api/test-org/invitations', [
            'email' => 'newuser@test.com',
            'role_id' => $role->id,
        ]);

        $response->assertStatus(201);
        $response->assertJsonFragment(['email' => 'newuser@test.com']);

        $this->assertDatabaseHas('organization_invitations', [
            'email' => 'newuser@test.com',
            'organization_id' => $org->id,
            'role_id' => $role->id,
            'invited_by' => $user->id,
            'status' => 'pending',
        ]);

        Mail::assertSent(InvitationNotification::class, function ($mail) {
            return $mail->hasTo('newuser@test.com');
        });
    }

    public function test_store_returns_422_for_invalid_email(): void
    {
        $org = $this->createOrg();
        $role = $this->createRole();
        $user = $this->createUserInOrg($org, $role);

        $this->actingAs($user, 'sanctum');
        $response = $this->postJson('/api/test-org/invitations', [
            'email' => 'not-an-email',
            'role_id' => $role->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('email');
    }

    public function test_store_returns_422_for_missing_role(): void
    {
        $org = $this->createOrg();
        $role = $this->createRole();
        $user = $this->createUserInOrg($org, $role);

        $this->actingAs($user, 'sanctum');
        $response = $this->postJson('/api/test-org/invitations', [
            'email' => 'user@test.com',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('role_id');
    }

    public function test_store_returns_422_if_user_already_in_org(): void
    {
        $org = $this->createOrg();
        $role = $this->createRole();
        $user = $this->createUserInOrg($org, $role, ['email' => 'existing@test.com']);

        $this->actingAs($user, 'sanctum');
        $response = $this->postJson('/api/test-org/invitations', [
            'email' => 'existing@test.com',
            'role_id' => $role->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'User is already a member of this organization']);
    }

    public function test_store_returns_422_if_pending_invitation_exists(): void
    {
        $org = $this->createOrg();
        $role = $this->createRole();
        $user = $this->createUserInOrg($org, $role);

        $this->createInvitation($org, $role, $user, [
            'email' => 'duplicate@test.com',
            'expires_at' => now()->addDays(7),
        ]);

        $this->actingAs($user, 'sanctum');
        $response = $this->postJson('/api/test-org/invitations', [
            'email' => 'duplicate@test.com',
            'role_id' => $role->id,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'A pending invitation already exists for this email']);
    }

    // ======================================================================
    // RESEND
    // ======================================================================

    public function test_resend_sends_email_and_updates_expiry(): void
    {
        $org = $this->createOrg();
        $role = $this->createRole();
        $user = $this->createUserInOrg($org, $role);
        $invitation = $this->createInvitation($org, $role, $user);

        $this->actingAs($user, 'sanctum');
        $response = $this->postJson("/api/test-org/invitations/{$invitation->id}/resend");

        $response->assertStatus(200);
        $response->assertJsonFragment(['message' => 'Invitation resent successfully']);

        $invitation->refresh();
        $this->assertNotNull($invitation->expires_at);

        Mail::assertSent(InvitationNotification::class);
    }

    public function test_resend_returns_403_for_non_pending_invitation(): void
    {
        $org = $this->createOrg();
        $role = $this->createRole();
        $user = $this->createUserInOrg($org, $role);
        $invitation = $this->createInvitation($org, $role, $user, ['status' => 'accepted']);

        $this->actingAs($user, 'sanctum');
        $response = $this->postJson("/api/test-org/invitations/{$invitation->id}/resend");

        // Policy denies update on non-pending invitations
        $response->assertStatus(403);
    }

    public function test_resend_returns_404_for_wrong_org(): void
    {
        $org = $this->createOrg('org-a');
        $otherOrg = $this->createOrg('org-b');
        $role = $this->createRole();
        $user = $this->createUserInOrg($org, $role);
        $invitation = $this->createInvitation($otherOrg, $role, $user);

        $this->actingAs($user, 'sanctum');
        $response = $this->postJson("/api/org-a/invitations/{$invitation->id}/resend");

        $response->assertStatus(404);
    }

    // ======================================================================
    // CANCEL
    // ======================================================================

    public function test_cancel_sets_status_to_cancelled(): void
    {
        $org = $this->createOrg();
        $role = $this->createRole();
        $user = $this->createUserInOrg($org, $role);
        $invitation = $this->createInvitation($org, $role, $user);

        $this->actingAs($user, 'sanctum');
        $response = $this->postJson("/api/test-org/invitations/{$invitation->id}/cancel");

        $response->assertStatus(200);
        $response->assertJsonFragment(['message' => 'Invitation cancelled successfully']);

        $invitation->refresh();
        $this->assertEquals('cancelled', $invitation->status);
    }

    public function test_cancel_returns_403_for_non_pending(): void
    {
        $org = $this->createOrg();
        $role = $this->createRole();
        $user = $this->createUserInOrg($org, $role);
        $invitation = $this->createInvitation($org, $role, $user, ['status' => 'accepted']);

        $this->actingAs($user, 'sanctum');
        $response = $this->postJson("/api/test-org/invitations/{$invitation->id}/cancel");

        // Policy denies delete on non-pending invitations
        $response->assertStatus(403);
    }

    // ======================================================================
    // ACCEPT
    // ======================================================================

    public function test_accept_returns_422_for_invalid_token(): void
    {
        $response = $this->postJson('/api/invitations/accept', [
            'token' => 'short',
        ]);

        $response->assertStatus(422);
        $response->assertJsonValidationErrors('token');
    }

    public function test_accept_returns_404_for_nonexistent_token(): void
    {
        $response = $this->postJson('/api/invitations/accept', [
            'token' => str_repeat('a', 64),
        ]);

        $response->assertStatus(404);
        $response->assertJsonFragment(['message' => 'Invalid or expired invitation token']);
    }

    public function test_accept_returns_422_for_expired_invitation(): void
    {
        $org = $this->createOrg();
        $role = $this->createRole();
        $user = $this->createUserInOrg($org, $role);
        $invitation = $this->createInvitation($org, $role, $user, [
            'expires_at' => now()->subDay(),
        ]);

        $response = $this->postJson('/api/invitations/accept', [
            'token' => $invitation->token,
        ]);

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'This invitation has expired']);

        $invitation->refresh();
        $this->assertEquals('expired', $invitation->status);
    }

    public function test_accept_without_auth_returns_requires_registration(): void
    {
        $org = $this->createOrg();
        $role = $this->createRole();
        $user = $this->createUserInOrg($org, $role);
        $invitation = $this->createInvitation($org, $role, $user, [
            'expires_at' => now()->addDays(7),
        ]);

        $response = $this->postJson('/api/invitations/accept', [
            'token' => $invitation->token,
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['requires_registration' => true]);
    }

    public function test_accept_with_auth_accepts_invitation(): void
    {
        $org = $this->createOrg();
        $role = $this->createRole();
        $inviter = $this->createUserInOrg($org, $role, ['email' => 'inviter@test.com']);
        $invitation = $this->createInvitation($org, $role, $inviter, [
            'email' => 'acceptor@test.com',
            'expires_at' => now()->addDays(7),
        ]);

        $acceptor = User::forceCreate([
            'name' => 'Acceptor',
            'email' => 'acceptor@test.com',
            'password' => bcrypt('password'),
        ]);

        $this->actingAs($acceptor, 'sanctum');
        $response = $this->postJson('/api/invitations/accept', [
            'token' => $invitation->token,
        ]);

        $response->assertStatus(200);
        $response->assertJsonFragment(['message' => 'Invitation accepted successfully']);

        $invitation->refresh();
        $this->assertEquals('accepted', $invitation->status);
    }
}
