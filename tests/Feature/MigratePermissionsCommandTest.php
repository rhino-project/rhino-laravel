<?php

namespace Rhino\Tests\Feature;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Support\Facades\DB;
use Rhino\Tests\TestCase;

/**
 * rhino:permissions-migrate lifts per-user user_roles.permissions into the
 * shared org_role_permissions role layer, reducing each user row to its delta
 * while preserving effective permissions exactly.
 */
class MigratePermissionsCommandTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    private function makeOrg(int $id): Organization
    {
        return Organization::forceCreate(['id' => $id, 'name' => "Org {$id}", 'slug' => "org-{$id}"]);
    }

    private function makeRole(int $id): Role
    {
        return Role::forceCreate(['id' => $id, 'name' => "Role {$id}", 'slug' => "role-{$id}"]);
    }

    private function makeUser(int $id): User
    {
        return User::forceCreate([
            'id' => $id, 'name' => "U{$id}", 'email' => "u{$id}@example.com", 'password' => bcrypt('x'),
        ]);
    }

    public function test_dry_run_makes_no_changes(): void
    {
        $org = $this->makeOrg(1);
        $role = $this->makeRole(1);
        $u = $this->makeUser(1);
        UserRole::forceCreate([
            'user_id' => $u->id, 'role_id' => $role->id, 'organization_id' => $org->id,
            'permissions' => ['*'],
        ]);

        $this->artisan('rhino:permissions-migrate')
            ->assertExitCode(0);

        $this->assertSame(0, DB::table('org_role_permissions')->count());
        $this->assertSame(['*'], UserRole::first()->permissions);
    }

    public function test_apply_lifts_intersection_into_role_layer_and_reduces_rows(): void
    {
        $org = $this->makeOrg(1);
        $role = $this->makeRole(1);
        $u1 = $this->makeUser(1);
        $u2 = $this->makeUser(2);

        // Both admins share ['posts.*']; u2 additionally has ['comments.index'].
        UserRole::forceCreate([
            'user_id' => $u1->id, 'role_id' => $role->id, 'organization_id' => $org->id,
            'permissions' => ['posts.*'],
        ]);
        UserRole::forceCreate([
            'user_id' => $u2->id, 'role_id' => $role->id, 'organization_id' => $org->id,
            'permissions' => ['posts.*', 'comments.index'],
        ]);

        $this->artisan('rhino:permissions-migrate --apply')->assertExitCode(0);

        // Role layer = literal intersection = ['posts.*'].
        $layer = DB::table('org_role_permissions')
            ->where('organization_id', $org->id)->where('role_id', $role->id)->value('permissions');
        $this->assertEqualsCanonicalizing(['posts.*'], json_decode($layer, true));

        // u1: nothing left over → empty delta; legacy cleared.
        $ur1 = UserRole::where('user_id', $u1->id)->first();
        $this->assertSame([], $ur1->permissions);
        $this->assertSame([], $ur1->granted_permissions);

        // u2: delta = ['comments.index'].
        $ur2 = UserRole::where('user_id', $u2->id)->first();
        $this->assertSame([], $ur2->permissions);
        $this->assertSame(['comments.index'], $ur2->granted_permissions);

        // Effective permissions preserved for both users.
        $this->assertTrue($u1->fresh()->hasPermission('posts.update', $org));
        $this->assertFalse($u1->fresh()->hasPermission('comments.index', $org));
        $this->assertTrue($u2->fresh()->hasPermission('posts.update', $org));
        $this->assertTrue($u2->fresh()->hasPermission('comments.index', $org));
    }

    public function test_is_idempotent(): void
    {
        $org = $this->makeOrg(1);
        $role = $this->makeRole(1);
        $u = $this->makeUser(1);
        UserRole::forceCreate([
            'user_id' => $u->id, 'role_id' => $role->id, 'organization_id' => $org->id,
            'permissions' => ['*'],
        ]);

        $this->artisan('rhino:permissions-migrate --apply')->assertExitCode(0);
        $this->artisan('rhino:permissions-migrate --apply')->assertExitCode(0);

        // Exactly one role-layer row; no duplicates from the second run.
        $this->assertSame(1, DB::table('org_role_permissions')->count());
        $this->assertTrue($u->fresh()->hasPermission('anything.here', $org));
    }

    public function test_skips_group_with_existing_role_layer(): void
    {
        $org = $this->makeOrg(1);
        $role = $this->makeRole(1);
        $u = $this->makeUser(1);
        UserRole::forceCreate([
            'user_id' => $u->id, 'role_id' => $role->id, 'organization_id' => $org->id,
            'permissions' => ['posts.*'],
        ]);
        DB::table('org_role_permissions')->insert([
            'organization_id' => $org->id, 'role_id' => $role->id, 'permissions' => json_encode(['comments.*']),
        ]);

        $this->artisan('rhino:permissions-migrate --apply')->assertExitCode(0);

        // Existing role layer untouched; the user row is left as-is.
        $layer = DB::table('org_role_permissions')->where('organization_id', $org->id)->value('permissions');
        $this->assertSame(['comments.*'], json_decode($layer, true));
        $this->assertSame(['posts.*'], UserRole::first()->permissions);
    }

    public function test_leaves_non_tenant_rows_untouched(): void
    {
        $role = $this->makeRole(1);
        $u = $this->makeUser(1);
        // No organization → non-tenant membership row.
        UserRole::forceCreate([
            'user_id' => $u->id, 'role_id' => $role->id, 'organization_id' => null,
            'permissions' => ['posts.*'],
        ]);

        $this->artisan('rhino:permissions-migrate --apply')->assertExitCode(0);

        $this->assertSame(0, DB::table('org_role_permissions')->count());
        $this->assertSame(['posts.*'], UserRole::first()->permissions);
    }
}
