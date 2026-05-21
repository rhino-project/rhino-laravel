<?php

namespace Rhino\Tests\Feature;

use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\SanctumServiceProvider;
use Rhino\Models\OrganizationInvitation;
use Rhino\Tests\TestCase;

class AuthenticationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Sanctum's personal_access_tokens table
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

        // Laravel's password reset tokens table
        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
        });

        // Organization invitations table
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

        // Load auth routes
        Route::prefix('api')->group(function () {
            require __DIR__ . '/../../routes/api.php';
        });
    }

    protected function getPackageProviders($app): array
    {
        return array_merge(parent::getPackageProviders($app), [
            SanctumServiceProvider::class,
        ]);
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('auth.guards.sanctum', [
            'driver' => 'sanctum',
            'provider' => 'users',
        ]);

        $app['config']->set('auth.guards.web', [
            'driver' => 'session',
            'provider' => 'users',
        ]);

        $app['config']->set('auth.providers.users', [
            'driver' => 'eloquent',
            'model' => \App\Models\User::class,
        ]);

        $app['config']->set('auth.passwords.users', [
            'provider' => 'users',
            'table' => 'password_reset_tokens',
            'expire' => 60,
        ]);
    }

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function createUser(array $attributes = []): \App\Models\User
    {
        return \App\Models\User::forceCreate(array_merge([
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => Hash::make('password123'),
        ], $attributes));
    }

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

    protected function createUserInOrganization(
        \App\Models\Organization $org,
        \App\Models\Role $role,
        array $userAttributes = []
    ): \App\Models\User {
        $user = $this->createUser($userAttributes);

        \App\Models\UserRole::forceCreate([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'permissions' => ['*'],
        ]);

        return $user;
    }

    protected function createInvitation(
        \App\Models\Organization $org,
        \App\Models\Role $role,
        \App\Models\User $invitedBy,
        array $attributes = []
    ): OrganizationInvitation {
        return OrganizationInvitation::create(array_merge([
            'organization_id' => $org->id,
            'email' => 'invitee@example.com',
            'role_id' => $role->id,
            'invited_by' => $invitedBy->id,
        ], $attributes));
    }

    // ==================================================================
    // Login
    // ==================================================================

    public function test_login_with_valid_credentials_returns_token(): void
    {
        $org = $this->createOrganization();
        $role = $this->createRole();
        $user = $this->createUserInOrganization($org, $role);

        $response = $this->postJson('/api/auth/login', [
            'email' => 'user@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJsonStructure(['token', 'organization_slug'])
            ->assertJson(['organization_slug' => 'test-org']);

        $this->assertNotEmpty($response->json('token'));
    }

    public function test_login_with_invalid_password_returns_401(): void
    {
        $this->createUser();

        $response = $this->postJson('/api/auth/login', [
            'email' => 'user@example.com',
            'password' => 'wrong-password',
        ]);

        $response->assertStatus(401)
            ->assertJson(['message' => 'Invalid credentials']);
    }

    public function test_login_with_non_existent_email_returns_401(): void
    {
        $response = $this->postJson('/api/auth/login', [
            'email' => 'nobody@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(401)
            ->assertJson(['message' => 'Invalid credentials']);
    }

    public function test_login_returns_null_org_slug_when_user_has_no_orgs(): void
    {
        $this->createUser();

        $response = $this->postJson('/api/auth/login', [
            'email' => 'user@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(200)
            ->assertJson(['organization_slug' => null]);
    }

    public function test_login_validates_required_fields(): void
    {
        $response = $this->postJson('/api/auth/login', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email', 'password']);
    }

    // ==================================================================
    // Logout
    // ==================================================================

    public function test_logout_revokes_tokens(): void
    {
        $user = $this->createUser();
        $token = $user->createToken('API Token')->plainTextToken;

        $response = $this->withHeader('Authorization', "Bearer {$token}")
            ->postJson('/api/auth/logout');

        $response->assertStatus(200)
            ->assertJson(['message' => 'Logged out successfully']);

        $this->assertDatabaseCount('personal_access_tokens', 0);
    }

    public function test_logout_requires_authentication(): void
    {
        $response = $this->postJson('/api/auth/logout');

        $response->assertStatus(401);
    }

    // ==================================================================
    // Password Recovery
    // ==================================================================

    public function test_recover_password_sends_reset_link(): void
    {
        $this->createUser();

        // Fake the Password broker to avoid actually sending emails
        Password::shouldReceive('sendResetLink')
            ->once()
            ->with(['email' => 'user@example.com'])
            ->andReturn(Password::RESET_LINK_SENT);

        $response = $this->postJson('/api/auth/password/recover', [
            'email' => 'user@example.com',
        ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Password recovery email sent.']);
    }

    public function test_recover_password_returns_500_on_failure(): void
    {
        $this->createUser();

        Password::shouldReceive('sendResetLink')
            ->once()
            ->andReturn(Password::RESET_THROTTLED);

        $response = $this->postJson('/api/auth/password/recover', [
            'email' => 'user@example.com',
        ]);

        $response->assertStatus(500)
            ->assertJson(['message' => 'Unable to send password recovery email.']);
    }

    public function test_recover_password_validates_email(): void
    {
        $response = $this->postJson('/api/auth/password/recover', []);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    public function test_recover_password_validates_email_format(): void
    {
        $response = $this->postJson('/api/auth/password/recover', [
            'email' => 'not-an-email',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['email']);
    }

    // ==================================================================
    // Password Reset
    // ==================================================================

    public function test_reset_password_with_valid_token(): void
    {
        $user = $this->createUser();

        Password::shouldReceive('reset')
            ->once()
            ->andReturnUsing(function ($credentials, $callback) use ($user) {
                $callback($user, $credentials['password']);
                return Password::PASSWORD_RESET;
            });

        $response = $this->postJson('/api/auth/password/reset', [
            'token' => 'valid-reset-token',
            'email' => 'user@example.com',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(200)
            ->assertJson(['message' => 'Password has been reset.']);

        // Verify the password was actually changed
        $user->refresh();
        $this->assertTrue(Hash::check('newpassword123', $user->password));
    }

    public function test_reset_password_with_invalid_token_returns_400(): void
    {
        $this->createUser();

        Password::shouldReceive('reset')
            ->once()
            ->andReturn(Password::INVALID_TOKEN);

        $response = $this->postJson('/api/auth/password/reset', [
            'token' => 'invalid-token',
            'email' => 'user@example.com',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(400)
            ->assertJson(['message' => 'Token is invalid or expired.']);
    }

    public function test_reset_password_validates_required_fields(): void
    {
        $response = $this->postJson('/api/auth/password/reset', []);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors']);
    }

    public function test_reset_password_requires_password_confirmation(): void
    {
        $this->createUser();

        $response = $this->postJson('/api/auth/password/reset', [
            'token' => 'some-token',
            'email' => 'user@example.com',
            'password' => 'newpassword123',
        ]);

        $response->assertStatus(422);
    }

    public function test_reset_password_requires_minimum_8_characters(): void
    {
        $this->createUser();

        $response = $this->postJson('/api/auth/password/reset', [
            'token' => 'some-token',
            'email' => 'user@example.com',
            'password' => 'short',
            'password_confirmation' => 'short',
        ]);

        $response->assertStatus(422);
    }

    public function test_reset_password_requires_existing_email(): void
    {
        $response = $this->postJson('/api/auth/password/reset', [
            'token' => 'some-token',
            'email' => 'nonexistent@example.com',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ]);

        $response->assertStatus(422);
    }

    // ==================================================================
    // Registration with Invitation
    // ==================================================================

    public function test_register_with_valid_invitation(): void
    {
        $org = $this->createOrganization();
        $role = $this->createRole();
        $inviter = $this->createUser(['email' => 'admin@example.com']);

        $invitation = $this->createInvitation($org, $role, $inviter, [
            'email' => 'newuser@example.com',
        ]);

        $response = $this->postJson('/api/auth/register', [
            'token' => $invitation->token,
            'name' => 'New User',
            'email' => 'newuser@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['message', 'token', 'user', 'organization_slug'])
            ->assertJson([
                'message' => 'Registration successful',
                'organization_slug' => 'test-org',
            ]);

        // User was created
        $this->assertDatabaseHas('users', ['email' => 'newuser@example.com']);

        // Invitation was accepted
        $this->assertDatabaseHas('organization_invitations', [
            'id' => $invitation->id,
            'status' => 'accepted',
        ]);

        // User was added to organization
        $this->assertDatabaseHas('user_roles', [
            'organization_id' => $org->id,
            'role_id' => $role->id,
        ]);
    }

    public function test_register_with_invalid_token_returns_404(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'token' => str_repeat('a', 64),
            'name' => 'New User',
            'email' => 'newuser@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(404)
            ->assertJson(['message' => 'Invalid or expired invitation token']);
    }

    public function test_register_with_expired_invitation_returns_422(): void
    {
        $org = $this->createOrganization();
        $role = $this->createRole();
        $inviter = $this->createUser(['email' => 'admin@example.com']);

        $invitation = $this->createInvitation($org, $role, $inviter, [
            'email' => 'newuser@example.com',
            'expires_at' => Carbon::now()->subDay(),
        ]);

        $response = $this->postJson('/api/auth/register', [
            'token' => $invitation->token,
            'name' => 'New User',
            'email' => 'newuser@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJson(['message' => 'This invitation has expired']);

        // Invitation status updated to expired
        $this->assertDatabaseHas('organization_invitations', [
            'id' => $invitation->id,
            'status' => 'expired',
        ]);
    }

    public function test_register_with_email_mismatch_returns_422(): void
    {
        $org = $this->createOrganization();
        $role = $this->createRole();
        $inviter = $this->createUser(['email' => 'admin@example.com']);

        $invitation = $this->createInvitation($org, $role, $inviter, [
            'email' => 'invited@example.com',
        ]);

        $response = $this->postJson('/api/auth/register', [
            'token' => $invitation->token,
            'name' => 'New User',
            'email' => 'different@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422)
            ->assertJson(['message' => 'Email does not match the invitation']);
    }

    public function test_register_validates_required_fields(): void
    {
        $response = $this->postJson('/api/auth/register', []);

        $response->assertStatus(422)
            ->assertJsonStructure(['errors']);
    }

    public function test_register_requires_unique_email(): void
    {
        $org = $this->createOrganization();
        $role = $this->createRole();
        $existingUser = $this->createUser(['email' => 'taken@example.com']);

        $invitation = $this->createInvitation($org, $role, $existingUser, [
            'email' => 'taken@example.com',
        ]);

        $response = $this->postJson('/api/auth/register', [
            'token' => $invitation->token,
            'name' => 'New User',
            'email' => 'taken@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422);
    }

    public function test_register_requires_password_confirmation(): void
    {
        $org = $this->createOrganization();
        $role = $this->createRole();
        $inviter = $this->createUser(['email' => 'admin@example.com']);

        $invitation = $this->createInvitation($org, $role, $inviter, [
            'email' => 'newuser@example.com',
        ]);

        $response = $this->postJson('/api/auth/register', [
            'token' => $invitation->token,
            'name' => 'New User',
            'email' => 'newuser@example.com',
            'password' => 'password123',
        ]);

        $response->assertStatus(422);
    }

    public function test_register_token_must_be_64_chars(): void
    {
        $response = $this->postJson('/api/auth/register', [
            'token' => 'short-token',
            'name' => 'New User',
            'email' => 'newuser@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ]);

        $response->assertStatus(422);
    }
}
