<?php

namespace Rhino\Tests\Unit;

use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rhino\Contracts\AbstractAuthLifecycleHooks;
use Rhino\Exceptions\RhinoAuthRejected;
use Rhino\Http\Middleware\EnforceGroupMembership;
use Rhino\Tests\TestCase;

class GroupAuthUnitTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    protected function user(): \App\Models\User
    {
        return \App\Models\User::forceCreate([
            'name' => 'U', 'email' => 'u@example.com', 'password' => bcrypt('x'), 'permissions' => [],
        ]);
    }

    protected function role(): \App\Models\Role
    {
        return \App\Models\Role::forceCreate(['name' => 'R', 'slug' => 'r' . uniqid()]);
    }

    protected function membership(\App\Models\User $u, ?int $orgId, ?string $rg, array $perms = ['*']): void
    {
        \App\Models\UserRole::forceCreate([
            'user_id' => $u->id, 'organization_id' => $orgId,
            'role_id' => $this->role()->id, 'route_group' => $rg, 'permissions' => $perms,
        ]);
    }

    // ------------------------------------------------------------------
    // EnforceGroupMembership::isMember
    // ------------------------------------------------------------------

    public function test_is_member_exact_match(): void
    {
        $u = $this->user();
        $this->membership($u, null, 'driver');

        $this->assertTrue(EnforceGroupMembership::isMember($u, 'driver'));
        $this->assertFalse(EnforceGroupMembership::isMember($u, 'rider'));
    }

    public function test_is_member_null_is_wildcard(): void
    {
        $u = $this->user();
        $this->membership($u, null, null);

        $this->assertTrue(EnforceGroupMembership::isMember($u, 'driver'));
        $this->assertTrue(EnforceGroupMembership::isMember($u, 'anything'));
        $this->assertTrue(EnforceGroupMembership::isMember($u, null));
    }

    public function test_is_member_tenant_requires_matching_org(): void
    {
        $u = $this->user();
        $org = \App\Models\Organization::forceCreate(['name' => 'O', 'slug' => 'o']);
        $this->membership($u, $org->id, 'tenant');

        $this->assertTrue(EnforceGroupMembership::isMember($u, 'tenant', $org));
        $this->assertFalse(EnforceGroupMembership::isMember($u, 'tenant', 999999));
    }

    public function test_is_member_non_tenant_ignores_org(): void
    {
        $u = $this->user();
        $org = \App\Models\Organization::forceCreate(['name' => 'O', 'slug' => 'o']);
        // Membership scoped to org, but no org passed → org ignored.
        $this->membership($u, $org->id, 'driver');

        $this->assertTrue(EnforceGroupMembership::isMember($u, 'driver', null));
    }

    // ------------------------------------------------------------------
    // hasPermission flag-off regression + flag-on resolution
    // ------------------------------------------------------------------

    public function test_has_permission_flag_off_uses_user_permissions_for_non_tenant(): void
    {
        config(['rhino.auth.enforce_group_membership' => false]);
        $u = \App\Models\User::forceCreate([
            'name' => 'U', 'email' => 'u2@example.com', 'password' => bcrypt('x'), 'permissions' => ['posts.index'],
        ]);

        // No org → user.permissions heuristic, route_group ignored entirely.
        $this->assertTrue($u->hasPermission('posts.index', null, 'driver'));
        $this->assertFalse($u->hasPermission('posts.store', null, 'driver'));
    }

    public function test_has_permission_flag_on_resolves_from_matching_row(): void
    {
        config(['rhino.auth.enforce_group_membership' => true]);
        $u = \App\Models\User::forceCreate([
            'name' => 'U', 'email' => 'u3@example.com', 'password' => bcrypt('x'), 'permissions' => ['*'],
        ]);
        $this->membership($u, null, 'driver', ['posts.index']);

        // Resolves from the driver membership row, NOT user.permissions['*'].
        $this->assertTrue($u->hasPermission('posts.index', null, 'driver'));
        $this->assertFalse($u->hasPermission('posts.store', null, 'driver'));
    }

    public function test_has_permission_flag_on_null_row_is_wildcard_group(): void
    {
        config(['rhino.auth.enforce_group_membership' => true]);
        $u = \App\Models\User::forceCreate([
            'name' => 'U', 'email' => 'u4@example.com', 'password' => bcrypt('x'), 'permissions' => [],
        ]);
        $this->membership($u, null, null, ['posts.index']);

        $this->assertTrue($u->hasPermission('posts.index', null, 'anygroup'));
    }

    public function test_has_permission_exact_row_takes_precedence_over_null_wildcard(): void
    {
        // Coexistence (fix #4): a scoped 'driver' row that does NOT grant the
        // permission must win over a broad NULL-wildcard '*' row.
        config(['rhino.auth.enforce_group_membership' => true]);
        $u = \App\Models\User::forceCreate([
            'name' => 'U', 'email' => 'u5@example.com', 'password' => bcrypt('x'), 'permissions' => [],
        ]);
        $exactRole = \App\Models\Role::forceCreate(['name' => 'Exact', 'slug' => 'exact']);
        $wildRole = \App\Models\Role::forceCreate(['name' => 'Wild', 'slug' => 'wild']);
        \App\Models\UserRole::forceCreate([
            'user_id' => $u->id, 'organization_id' => null,
            'role_id' => $exactRole->id, 'route_group' => 'driver', 'permissions' => ['posts.store'],
        ]);
        \App\Models\UserRole::forceCreate([
            'user_id' => $u->id, 'organization_id' => null,
            'role_id' => $wildRole->id, 'route_group' => null, 'permissions' => ['*'],
        ]);

        // Exact 'driver' row is authoritative → wildcard '*' is ignored.
        $this->assertFalse($u->hasPermission('posts.index', null, 'driver'));
        // The exact row's own grant still works.
        $this->assertTrue($u->hasPermission('posts.store', null, 'driver'));
    }

    public function test_has_permission_falls_back_to_wildcard_when_no_exact_row(): void
    {
        // No exact row for 'driver' → the NULL-wildcard row applies (fix #4).
        config(['rhino.auth.enforce_group_membership' => true]);
        $u = \App\Models\User::forceCreate([
            'name' => 'U', 'email' => 'u6@example.com', 'password' => bcrypt('x'), 'permissions' => [],
        ]);
        $this->membership($u, null, null, ['posts.index']);

        $this->assertTrue($u->hasPermission('posts.index', null, 'driver'));
    }

    // ------------------------------------------------------------------
    // Abstract hooks + exception
    // ------------------------------------------------------------------

    public function test_abstract_hooks_are_noops(): void
    {
        $hooks = new class extends AbstractAuthLifecycleHooks {};
        $user = $this->user();

        // None of these throw.
        $hooks->afterLogin($user, []);
        $hooks->afterLogout($user, []);
        $hooks->afterRegister($user, []);
        $hooks->afterPasswordRecover($user, []);
        $hooks->afterPasswordReset($user, []);

        $this->assertTrue(true);
    }

    public function test_rejection_exception_carries_status_and_message(): void
    {
        $e = new RhinoAuthRejected('nope', 409);
        $this->assertSame(409, $e->getStatus());
        $this->assertSame('nope', $e->getMessage());

        $default = new RhinoAuthRejected();
        $this->assertSame(403, $default->getStatus());
    }
}
