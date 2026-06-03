<?php

namespace Rhino\Tests\Feature;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Laravel\Sanctum\SanctumServiceProvider;
use Rhino\Models\OrganizationInvitation;
use Rhino\Tests\Feature\GroupAuthHooks\TestAuthHooks;
use Rhino\Tests\TestCase;

/**
 * Covers Parts 2 (group-aware auth) and 4 (lifecycle hooks), plus the login
 * membership-enforcement path. See GROUP_AUTH_DESIGN.md §5, §6, §7, §10.
 */
class GroupAuthTest extends TestCase
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

        Schema::create('password_reset_tokens', function (Blueprint $table) {
            $table->string('email')->primary();
            $table->string('token');
            $table->timestamp('created_at')->nullable();
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

        TestAuthHooks::reset();
    }

    protected function tearDown(): void
    {
        TestAuthHooks::reset();

        parent::tearDown();
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

    /**
     * Configure route groups + enforcement, then load the package routes.
     */
    protected function loadRoutes(array $routeGroups, bool $enforce = false): void
    {
        config([
            'rhino.route_groups' => $routeGroups,
            'rhino.auth.enforce_group_membership' => $enforce,
            'rhino.multi_tenant.organization_identifier_column' => 'id',
        ]);

        Route::prefix('api')->group(function () {
            require __DIR__ . '/../../routes/api.php';
        });
    }

    protected function createUser(array $attributes = []): \App\Models\User
    {
        return \App\Models\User::forceCreate(array_merge([
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => Hash::make('password123'),
        ], $attributes));
    }

    protected function createOrg(array $attributes = []): \App\Models\Organization
    {
        return \App\Models\Organization::forceCreate(array_merge([
            'name' => 'Org',
            'slug' => 'org',
        ], $attributes));
    }

    protected function createRole(array $attributes = []): \App\Models\Role
    {
        return \App\Models\Role::forceCreate(array_merge([
            'name' => 'Role',
            'slug' => 'role',
        ], $attributes));
    }

    protected function membership(\App\Models\User $user, ?int $orgId, ?string $routeGroup, ?int $roleId = null, array $permissions = ['*']): void
    {
        \App\Models\UserRole::forceCreate([
            'user_id' => $user->id,
            'organization_id' => $orgId,
            'role_id' => $roleId ?? $this->createRole(['slug' => 'r' . uniqid()])->id,
            'route_group' => $routeGroup,
            'permissions' => $permissions,
        ]);
    }

    protected function defaultGroups(): array
    {
        return [
            'default' => ['prefix' => '', 'middleware' => [], 'models' => '*'],
        ];
    }

    // ==================================================================
    // Group-aware auth route registration (§5 / 9.A)
    // ==================================================================

    public function test_auth_routes_are_registered_per_auth_enabled_group(): void
    {
        $this->loadRoutes([
            'default' => ['prefix' => '', 'middleware' => [], 'models' => '*'],
            'driver' => ['prefix' => 'driver', 'auth' => true, 'middleware' => [], 'models' => '*'],
        ]);

        $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->uri())->all();

        // Legacy unprefixed auth set still present.
        $this->assertContains('api/auth/login', $uris);
        // Per-group auth set.
        $this->assertContains('api/driver/auth/login', $uris);
        $this->assertContains('api/driver/auth/logout', $uris);
        $this->assertContains('api/driver/auth/register', $uris);
        $this->assertContains('api/driver/auth/password/recover', $uris);
        $this->assertContains('api/driver/auth/password/reset', $uris);
    }

    public function test_public_group_never_gets_auth_routes(): void
    {
        $this->loadRoutes([
            'public' => ['prefix' => 'pub', 'auth' => true, 'middleware' => [], 'models' => '*'],
        ]);

        $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->uri())->all();

        $this->assertNotContains('api/pub/auth/login', $uris);
    }

    public function test_group_without_auth_flag_gets_no_auth_routes(): void
    {
        $this->loadRoutes([
            'driver' => ['prefix' => 'driver', 'middleware' => [], 'models' => '*'],
        ]);

        $uris = collect(Route::getRoutes()->getRoutes())->map(fn ($r) => $r->uri())->all();

        $this->assertNotContains('api/driver/auth/login', $uris);
        // Legacy still present.
        $this->assertContains('api/auth/login', $uris);
    }

    public function test_group_auth_routes_carry_route_group_default(): void
    {
        $this->loadRoutes([
            'driver' => ['prefix' => 'driver', 'auth' => true, 'middleware' => [], 'models' => '*'],
        ]);

        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/driver/auth/login');

        $this->assertSame('driver', $route->defaults['route_group'] ?? null);
    }

    public function test_domain_based_group_auth_route_registered_on_host(): void
    {
        $this->loadRoutes([
            'admin' => ['prefix' => '', 'domain' => 'admin.example.com', 'auth' => true, 'middleware' => [], 'models' => '*'],
        ]);

        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r) => $r->uri() === 'api/auth/login' && $r->getDomain() === 'admin.example.com');

        $this->assertNotNull($route);
        $this->assertSame('admin', $route->defaults['route_group'] ?? null);
    }

    public function test_domain_group_login_resolves_group_not_shadowed_by_legacy(): void
    {
        // An auth-enabled group with an empty prefix + a domain must win on its
        // own host: the legacy unprefixed /auth/login (no host constraint,
        // registered AFTER the per-group routes) must not shadow it. Logging in
        // on the host must resolve the group and fire its hook. (Fix #7.)
        $this->createUser();
        $this->loadRoutes([
            'admin' => [
                'prefix' => '',
                'domain' => 'admin.example.com',
                'auth' => true,
                'hooks' => TestAuthHooks::class,
                'middleware' => [],
                'models' => '*',
            ],
        ]);

        $this->postJson('http://admin.example.com/api/auth/login', [
            'email' => 'user@example.com',
            'password' => 'password123',
        ])->assertStatus(200);

        $this->assertCount(1, TestAuthHooks::$calls);
        $this->assertSame('afterLogin', TestAuthHooks::$calls[0]['event']);
        $this->assertSame('admin', TestAuthHooks::$calls[0]['context']['routeGroup']);
    }

    // ==================================================================
    // Group-aware login resolves the correct group
    // ==================================================================

    public function test_legacy_login_still_works(): void
    {
        $this->createUser();
        $this->loadRoutes($this->defaultGroups());

        $this->postJson('/api/auth/login', [
            'email' => 'user@example.com',
            'password' => 'password123',
        ])->assertStatus(200)->assertJsonStructure(['token']);
    }

    public function test_per_group_login_works_for_member(): void
    {
        $user = $this->createUser();
        $this->loadRoutes([
            'driver' => ['prefix' => 'driver', 'auth' => true, 'middleware' => [], 'models' => '*'],
        ], enforce: true);
        $this->membership($user, null, 'driver');

        $this->postJson('/api/driver/auth/login', [
            'email' => 'user@example.com',
            'password' => 'password123',
        ])->assertStatus(200);
    }

    public function test_wrong_group_login_denied_when_enforced(): void
    {
        $user = $this->createUser();
        $this->loadRoutes([
            'driver' => ['prefix' => 'driver', 'auth' => true, 'middleware' => [], 'models' => '*'],
            'rider' => ['prefix' => 'rider', 'auth' => true, 'middleware' => [], 'models' => '*'],
        ], enforce: true);
        // Member of 'driver' only.
        $this->membership($user, null, 'driver');

        $this->postJson('/api/rider/auth/login', [
            'email' => 'user@example.com',
            'password' => 'password123',
        ])->assertStatus(403)->assertJson(['message' => 'You are not a member of this group']);
    }

    public function test_wildcard_null_membership_logs_into_any_group(): void
    {
        $user = $this->createUser();
        $this->loadRoutes([
            'driver' => ['prefix' => 'driver', 'auth' => true, 'middleware' => [], 'models' => '*'],
        ], enforce: true);
        // NULL route_group = wildcard.
        $this->membership($user, null, null);

        $this->postJson('/api/driver/auth/login', [
            'email' => 'user@example.com',
            'password' => 'password123',
        ])->assertStatus(200);
    }

    public function test_enforced_login_without_membership_denied(): void
    {
        $this->createUser();
        $this->loadRoutes([
            'driver' => ['prefix' => 'driver', 'auth' => true, 'middleware' => [], 'models' => '*'],
        ], enforce: true);

        $this->postJson('/api/driver/auth/login', [
            'email' => 'user@example.com',
            'password' => 'password123',
        ])->assertStatus(403);
    }

    public function test_flag_off_login_ignores_membership(): void
    {
        // No membership row at all; flag off → login still succeeds.
        $this->createUser();
        $this->loadRoutes([
            'driver' => ['prefix' => 'driver', 'auth' => true, 'middleware' => [], 'models' => '*'],
        ], enforce: false);

        $this->postJson('/api/driver/auth/login', [
            'email' => 'user@example.com',
            'password' => 'password123',
        ])->assertStatus(200);
    }

    // ==================================================================
    // Lifecycle hooks
    // ==================================================================

    protected function driverGroupWithHooks(): array
    {
        return [
            'driver' => [
                'prefix' => 'driver',
                'auth' => true,
                'hooks' => TestAuthHooks::class,
                'middleware' => [],
                'models' => '*',
            ],
        ];
    }

    public function test_after_login_hook_fires_with_context(): void
    {
        $this->createUser();
        $this->loadRoutes($this->driverGroupWithHooks());

        $this->postJson('/api/driver/auth/login', [
            'email' => 'user@example.com',
            'password' => 'password123',
        ])->assertStatus(200);

        $this->assertCount(1, TestAuthHooks::$calls);
        $call = TestAuthHooks::$calls[0];
        $this->assertSame('afterLogin', $call['event']);
        $this->assertSame('driver', $call['context']['routeGroup']);
        $this->assertNotNull($call['context']['token']);
        $this->assertArrayHasKey('user', $call['context']);
        $this->assertArrayHasKey('request', $call['context']);
    }

    public function test_login_hook_reject_revokes_token_and_returns_status(): void
    {
        $user = $this->createUser();
        TestAuthHooks::$reject['afterLogin'] = 409;
        $this->loadRoutes($this->driverGroupWithHooks());

        $this->postJson('/api/driver/auth/login', [
            'email' => 'user@example.com',
            'password' => 'password123',
        ])->assertStatus(409);

        // Token was revoked.
        $this->assertSame(0, $user->tokens()->count());
    }

    public function test_login_hook_reject_only_revokes_just_issued_token(): void
    {
        // A pre-existing session (e.g. from another device) must survive a
        // rejected login — reject revokes ONLY the just-issued token. (Fix #2.)
        $user = $this->createUser();
        $preExisting = $user->createToken('Existing Session');
        $preExistingId = $preExisting->accessToken->getKey();

        TestAuthHooks::$reject['afterLogin'] = 403;
        $this->loadRoutes($this->driverGroupWithHooks());

        $this->postJson('/api/driver/auth/login', [
            'email' => 'user@example.com',
            'password' => 'password123',
        ])->assertStatus(403);

        // Exactly the pre-existing token remains; the new one was revoked.
        $this->assertSame(1, $user->tokens()->count());
        $this->assertNotNull($user->tokens()->whereKey($preExistingId)->first());
    }

    public function test_login_hook_generic_exception_returns_500_and_revokes_only_issued_token(): void
    {
        // A non-RhinoAuthRejected exception is treated as a hook failure: 500 +
        // the just-issued token revoked, but other sessions untouched. (Fix #8.)
        $user = $this->createUser();
        $preExisting = $user->createToken('Existing Session');
        $preExistingId = $preExisting->accessToken->getKey();

        TestAuthHooks::$throw['afterLogin'] = true;
        $this->loadRoutes($this->driverGroupWithHooks());

        $this->postJson('/api/driver/auth/login', [
            'email' => 'user@example.com',
            'password' => 'password123',
        ])->assertStatus(500);

        $this->assertSame(1, $user->tokens()->count());
        $this->assertNotNull($user->tokens()->whereKey($preExistingId)->first());
    }

    public function test_login_hook_default_reject_status_is_403(): void
    {
        $this->createUser();
        TestAuthHooks::$reject['afterLogin'] = 403;
        $this->loadRoutes($this->driverGroupWithHooks());

        $this->postJson('/api/driver/auth/login', [
            'email' => 'user@example.com',
            'password' => 'password123',
        ])->assertStatus(403);
    }

    public function test_logout_hook_fires(): void
    {
        $user = $this->createUser();
        $this->loadRoutes($this->driverGroupWithHooks());
        $this->actingAs($user, 'sanctum');

        $this->postJson('/api/driver/auth/logout')->assertStatus(200);

        $this->assertSame('afterLogout', TestAuthHooks::$calls[0]['event']);
    }

    public function test_logout_hook_reject_returns_status(): void
    {
        $user = $this->createUser();
        TestAuthHooks::$reject['afterLogout'] = 418;
        $this->loadRoutes($this->driverGroupWithHooks());
        $this->actingAs($user, 'sanctum');

        $this->postJson('/api/driver/auth/logout')->assertStatus(418);
    }

    public function test_password_recover_hook_fires(): void
    {
        $this->createUser();
        Password::shouldReceive('sendResetLink')->once()->andReturn(Password::RESET_LINK_SENT);
        $this->loadRoutes($this->driverGroupWithHooks());

        $this->postJson('/api/driver/auth/password/recover', [
            'email' => 'user@example.com',
        ])->assertStatus(200);

        $this->assertSame('afterPasswordRecover', TestAuthHooks::$calls[0]['event']);
    }

    public function test_password_recover_hook_reject_is_swallowed_uniform_200(): void
    {
        // Anti-enumeration: a rejecting afterPasswordRecover hook must NOT change
        // the uniform response. The hook still runs (side effects), but its
        // rejection is swallowed so the status stays 200. (Design §5 / fix #5.)
        $this->createUser();
        Password::shouldReceive('sendResetLink')->once()->andReturn(Password::RESET_LINK_SENT);
        TestAuthHooks::$reject['afterPasswordRecover'] = 429;
        $this->loadRoutes($this->driverGroupWithHooks());

        $this->postJson('/api/driver/auth/password/recover', [
            'email' => 'user@example.com',
        ])->assertStatus(200)->assertJson(['message' => 'Password recovery email sent.']);

        // The hook DID fire (just couldn't change the outcome).
        $events = array_column(TestAuthHooks::$calls, 'event');
        $this->assertContains('afterPasswordRecover', $events);
    }

    public function test_password_reset_hook_fires(): void
    {
        $this->createUser(['email' => 'x@example.com']);
        Password::shouldReceive('reset')->once()->andReturn(Password::PASSWORD_RESET);
        $this->loadRoutes($this->driverGroupWithHooks());

        $this->postJson('/api/driver/auth/password/reset', [
            'token' => 'sometoken',
            'email' => 'x@example.com',
            'password' => 'newpassword123',
            'password_confirmation' => 'newpassword123',
        ])->assertStatus(200);

        $events = array_column(TestAuthHooks::$calls, 'event');
        $this->assertContains('afterPasswordReset', $events);
    }

    public function test_register_hook_fires_and_reject_revokes_token(): void
    {
        $org = $this->createOrg();
        $role = $this->createRole();
        $inviter = $this->createUser(['email' => 'inviter@example.com']);
        $invitation = OrganizationInvitation::create([
            'organization_id' => $org->id,
            'route_group' => 'driver',
            'email' => 'newbie@example.com',
            'role_id' => $role->id,
            'invited_by' => $inviter->id,
        ]);

        TestAuthHooks::$reject['afterRegister'] = 403;
        $this->loadRoutes($this->driverGroupWithHooks());

        $this->postJson('/api/driver/auth/register', [
            'token' => $invitation->token,
            'name' => 'Newbie',
            'email' => 'newbie@example.com',
            'password' => 'password123',
            'password_confirmation' => 'password123',
        ])->assertStatus(403);

        // The user was created, but the token revoked.
        $created = \App\Models\User::where('email', 'newbie@example.com')->first();
        $this->assertNotNull($created);
        $this->assertSame(0, $created->tokens()->count());
    }

    public function test_non_rejecting_hook_is_noop(): void
    {
        $this->createUser();
        $this->loadRoutes($this->driverGroupWithHooks());

        $this->postJson('/api/driver/auth/login', [
            'email' => 'user@example.com',
            'password' => 'password123',
        ])->assertStatus(200);

        // Hook fired but did not change the outcome.
        $this->assertCount(1, TestAuthHooks::$calls);
    }

    public function test_group_without_hook_class_works(): void
    {
        $this->createUser();
        $this->loadRoutes([
            'driver' => ['prefix' => 'driver', 'auth' => true, 'middleware' => [], 'models' => '*'],
        ]);

        $this->postJson('/api/driver/auth/login', [
            'email' => 'user@example.com',
            'password' => 'password123',
        ])->assertStatus(200);

        $this->assertCount(0, TestAuthHooks::$calls);
    }

    public function test_legacy_auth_does_not_fire_group_hooks(): void
    {
        $this->createUser();
        // Hooks configured on a group, but login via the legacy unprefixed route
        // (no route_group default) must not trigger them.
        $this->loadRoutes($this->driverGroupWithHooks());

        $this->postJson('/api/auth/login', [
            'email' => 'user@example.com',
            'password' => 'password123',
        ])->assertStatus(200);

        $this->assertCount(0, TestAuthHooks::$calls);
    }
}
