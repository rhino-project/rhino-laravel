<?php

namespace Rhino\Tests\Unit;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Support\Facades\DB;
use PHPUnit\Framework\Attributes\DataProvider;
use Rhino\Tests\TestCase;

/**
 * Layered permissions: effective = (role ∪ granted) − denied, deny always wins.
 *
 *   - role    → org_role_permissions[(org, role)].permissions   (shared role layer)
 *   - granted → user_roles.granted_permissions                  (per-user additive)
 *   - denied  → user_roles.denied_permissions                   (per-user subtractive)
 *   - legacy  → user_roles.permissions                          (back-compat allow layer)
 *
 * See PERMISSIONS_DESIGN.md §4 for the cross-stack conformance truth table.
 */
class LayeredPermissionTest extends TestCase
{
    private int $seq = 0;

    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    // ------------------------------------------------------------------
    // Harness
    // ------------------------------------------------------------------

    /**
     * Build a fresh, isolated (user, org, role) triple with the given layers and
     * return the user. Each call uses unique ids so cases never bleed together.
     *
     * @param  array{role?: array, granted?: array, denied?: array, legacy?: array}  $layers
     */
    private function scenario(array $layers): array
    {
        $this->seq++;
        $id = 1000 + $this->seq;

        $user = User::forceCreate([
            'id' => $id,
            'name' => "User {$id}",
            'email' => "layered{$id}@example.com",
            'password' => bcrypt('password'),
        ]);

        $org = Organization::forceCreate([
            'id' => $id,
            'name' => "Org {$id}",
            'slug' => "org-{$id}",
        ]);

        $role = Role::forceCreate([
            'id' => $id,
            'name' => "Role {$id}",
            'slug' => "role-{$id}",
        ]);

        if (array_key_exists('role', $layers)) {
            DB::table('org_role_permissions')->insert([
                'organization_id' => $org->id,
                'role_id' => $role->id,
                'permissions' => json_encode($layers['role']),
            ]);
        }

        UserRole::forceCreate([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'permissions' => $layers['legacy'] ?? [],
            'granted_permissions' => $layers['granted'] ?? [],
            'denied_permissions' => $layers['denied'] ?? [],
        ]);

        return [$user, $org, $role];
    }

    private function can(array $layers, string $permission): bool
    {
        [$user, $org] = $this->scenario($layers);

        return $user->hasPermission($permission, $org);
    }

    // ------------------------------------------------------------------
    // Truth table (PERMISSIONS_DESIGN.md §4) — the conformance spec
    // ------------------------------------------------------------------

    public static function truthTable(): array
    {
        // [#, role, granted, denied, request, expected]
        return [
            'default deny'                  => [[], [], [], 'posts.update', false],
            'role grants'                   => [['posts.*'], [], [], 'posts.update', true],
            'grant grants'                  => [[], ['posts.update'], [], 'posts.update', true],
            'deny over role'                => [['posts.*'], [], ['posts.update'], 'posts.update', false],
            'deny over superadmin'          => [['*'], [], ['posts.update'], 'posts.update', false],
            'deny wildcard hits'            => [['*'], [], ['posts.*'], 'posts.index', false],
            'deny wildcard scoped'          => [['*'], [], ['posts.*'], 'users.index', true],
            'grant adds to role'            => [['posts.index'], ['posts.update'], [], 'posts.update', true],
            'still inherits role'           => [['posts.index'], ['posts.update'], [], 'posts.index', true],
            'not granted anywhere'          => [['posts.*'], [], [], 'comments.update', false],
            'deny over grant wildcard'      => [[], ['*'], ['posts.*'], 'posts.update', false],
            'grant wildcard else allowed'   => [[], ['*'], ['posts.*'], 'comments.index', true],
        ];
    }

    #[DataProvider('truthTable')]
    public function test_truth_table(array $role, array $granted, array $denied, string $request, bool $expected): void
    {
        $result = $this->can(
            ['role' => $role, 'granted' => $granted, 'denied' => $denied],
            $request
        );

        $this->assertSame(
            $expected,
            $result,
            sprintf(
                'role=%s granted=%s denied=%s request=%s',
                json_encode($role),
                json_encode($granted),
                json_encode($denied),
                $request
            )
        );
    }

    // ------------------------------------------------------------------
    // Edge cases explicitly requested
    // ------------------------------------------------------------------

    public function test_granted_and_denied_same_permission_follows_denied(): void
    {
        // The exact case the user named: same ability granted AND denied → deny.
        $this->assertFalse($this->can(
            ['granted' => ['posts.update'], 'denied' => ['posts.update']],
            'posts.update'
        ));
    }

    public function test_granted_and_denied_only_blocks_the_denied_ability(): void
    {
        // Grant a wildcard, deny one action — the rest of the grant survives.
        [$user, $org] = $this->scenario([
            'granted' => ['posts.*'],
            'denied' => ['posts.destroy'],
        ]);

        $this->assertTrue($user->hasPermission('posts.update', $org));
        $this->assertTrue($user->hasPermission('posts.index', $org));
        $this->assertFalse($user->hasPermission('posts.destroy', $org));
    }

    public function test_user_role_delta_unions_with_role_layer(): void
    {
        // "users role > role permissions": the user delta ADDS to the role layer
        // rather than replacing it (the behavior change vs. the old fallback rule).
        [$user, $org] = $this->scenario([
            'role' => ['posts.index', 'posts.show'],
            'granted' => ['posts.update'],
        ]);

        $this->assertTrue($user->hasPermission('posts.index', $org));  // from role
        $this->assertTrue($user->hasPermission('posts.show', $org));   // from role
        $this->assertTrue($user->hasPermission('posts.update', $org)); // from grant
        $this->assertFalse($user->hasPermission('posts.destroy', $org)); // neither
    }

    public function test_user_denied_overrides_role_layer(): void
    {
        // User-level deny beats the org role layer (deny-overrides precedence).
        [$user, $org] = $this->scenario([
            'role' => ['*'],
            'denied' => ['posts.destroy'],
        ]);

        $this->assertTrue($user->hasPermission('posts.update', $org));
        $this->assertFalse($user->hasPermission('posts.destroy', $org));
        $this->assertTrue($user->hasPermission('users.index', $org));
    }

    public function test_role_layer_alone_grants_without_any_user_permissions(): void
    {
        // The headline benefit: a user row with NO permissions still inherits the
        // org role layer (no need to stuff the full set on every user row).
        [$user, $org] = $this->scenario([
            'role' => ['posts.*', 'comments.index'],
        ]);

        $this->assertTrue($user->hasPermission('posts.update', $org));
        $this->assertTrue($user->hasPermission('comments.index', $org));
        $this->assertFalse($user->hasPermission('comments.store', $org));
    }

    // ------------------------------------------------------------------
    // Backward compatibility
    // ------------------------------------------------------------------

    public function test_legacy_permissions_still_work_without_role_layer(): void
    {
        // No org_role_permissions row, no granted/denied — pure legacy behavior.
        [$user, $org] = $this->scenario([
            'legacy' => ['posts.index', 'posts.show'],
        ]);

        $this->assertTrue($user->hasPermission('posts.index', $org));
        $this->assertTrue($user->hasPermission('posts.show', $org));
        $this->assertFalse($user->hasPermission('posts.update', $org));
    }

    public function test_legacy_wildcard_still_grants_everything(): void
    {
        [$user, $org] = $this->scenario(['legacy' => ['*']]);

        $this->assertTrue($user->hasPermission('posts.update', $org));
        $this->assertTrue($user->hasPermission('anything.here', $org));
    }

    public function test_legacy_permissions_can_be_overridden_by_user_deny(): void
    {
        // Even the legacy full-set can have a single ability carved out via deny.
        [$user, $org] = $this->scenario([
            'legacy' => ['*'],
            'denied' => ['posts.destroy'],
        ]);

        $this->assertTrue($user->hasPermission('posts.update', $org));
        $this->assertFalse($user->hasPermission('posts.destroy', $org));
    }

    public function test_empty_everything_denies(): void
    {
        [$user, $org] = $this->scenario([]);

        $this->assertFalse($user->hasPermission('posts.index', $org));
    }

    public function test_table_absent_degrades_to_legacy(): void
    {
        // Simulate an app that has not run the org_role_permissions migration:
        // the resolver must tolerate the missing table and fall back to legacy.
        [$user, $org] = $this->scenario([
            'legacy' => ['posts.index'],
        ]);

        DB::statement('DROP TABLE org_role_permissions');

        $this->assertTrue($user->hasPermission('posts.index', $org));
        $this->assertFalse($user->hasPermission('posts.update', $org));
    }

    // ------------------------------------------------------------------
    // Org / role isolation
    // ------------------------------------------------------------------

    public function test_role_layer_is_scoped_to_organization(): void
    {
        // Same role id, two orgs, different role-layer permissions per org.
        $user = User::forceCreate([
            'id' => 5001, 'name' => 'Multi', 'email' => 'multi@example.com', 'password' => bcrypt('x'),
        ]);
        $role = Role::forceCreate(['id' => 5001, 'name' => 'Shared', 'slug' => 'shared']);
        $orgA = Organization::forceCreate(['id' => 5001, 'name' => 'A', 'slug' => 'a']);
        $orgB = Organization::forceCreate(['id' => 5002, 'name' => 'B', 'slug' => 'b']);

        DB::table('org_role_permissions')->insert([
            ['organization_id' => $orgA->id, 'role_id' => $role->id, 'permissions' => json_encode(['*'])],
            ['organization_id' => $orgB->id, 'role_id' => $role->id, 'permissions' => json_encode(['posts.index'])],
        ]);

        foreach ([$orgA, $orgB] as $org) {
            UserRole::forceCreate([
                'user_id' => $user->id, 'role_id' => $role->id, 'organization_id' => $org->id,
            ]);
        }

        // Org A: full access from the role layer.
        $this->assertTrue($user->hasPermission('posts.destroy', $orgA));
        // Org B: read-only from the role layer.
        $this->assertTrue($user->hasPermission('posts.index', $orgB));
        $this->assertFalse($user->hasPermission('posts.destroy', $orgB));
    }

    public function test_role_layer_does_not_leak_across_roles(): void
    {
        // org_role_permissions for a DIFFERENT role must not apply to this user.
        $user = User::forceCreate([
            'id' => 5101, 'name' => 'U', 'email' => 'u5101@example.com', 'password' => bcrypt('x'),
        ]);
        $org = Organization::forceCreate(['id' => 5101, 'name' => 'O', 'slug' => 'o5101']);
        $myRole = Role::forceCreate(['id' => 5101, 'name' => 'Mine', 'slug' => 'mine']);
        $otherRole = Role::forceCreate(['id' => 5102, 'name' => 'Other', 'slug' => 'other']);

        DB::table('org_role_permissions')->insert([
            'organization_id' => $org->id, 'role_id' => $otherRole->id, 'permissions' => json_encode(['*']),
        ]);

        UserRole::forceCreate([
            'user_id' => $user->id, 'role_id' => $myRole->id, 'organization_id' => $org->id,
        ]);

        $this->assertFalse($user->hasPermission('posts.index', $org));
    }

    public function test_multiple_user_role_rows_union_allows_and_deny_wins_across_rows(): void
    {
        // Two rows for the same org (different roles): allows union, but a deny on
        // either row still wins globally.
        $user = User::forceCreate([
            'id' => 5201, 'name' => 'U', 'email' => 'u5201@example.com', 'password' => bcrypt('x'),
        ]);
        $org = Organization::forceCreate(['id' => 5201, 'name' => 'O', 'slug' => 'o5201']);
        $r1 = Role::forceCreate(['id' => 5201, 'name' => 'R1', 'slug' => 'r1']);
        $r2 = Role::forceCreate(['id' => 5202, 'name' => 'R2', 'slug' => 'r2']);

        UserRole::forceCreate([
            'user_id' => $user->id, 'role_id' => $r1->id, 'organization_id' => $org->id,
            'granted_permissions' => ['posts.index'],
        ]);
        UserRole::forceCreate([
            'user_id' => $user->id, 'role_id' => $r2->id, 'organization_id' => $org->id,
            'granted_permissions' => ['comments.index'], 'denied_permissions' => ['posts.index'],
        ]);

        // posts.index granted on r1 but denied on r2 → deny wins.
        $this->assertFalse($user->hasPermission('posts.index', $org));
        // comments.index granted on r2, not denied anywhere → allowed.
        $this->assertTrue($user->hasPermission('comments.index', $org));
    }

    // ------------------------------------------------------------------
    // Non-tenant (users.permissions) — layering still honors deny
    // ------------------------------------------------------------------

    public function test_non_tenant_uses_user_permissions(): void
    {
        $user = User::forceCreate([
            'id' => 5301, 'name' => 'D', 'email' => 'd5301@example.com', 'password' => bcrypt('x'),
            'permissions' => ['posts.index', 'posts.show'],
        ]);

        request()->replace([]);

        $this->assertTrue($user->hasPermission('posts.index'));
        $this->assertFalse($user->hasPermission('posts.store'));
    }

    public function test_non_tenant_user_deny_overrides_user_permissions(): void
    {
        // If the host app adds a denied_permissions attribute to the user, deny
        // wins in the non-tenant path too.
        $user = User::forceCreate([
            'id' => 5302, 'name' => 'D', 'email' => 'd5302@example.com', 'password' => bcrypt('x'),
            'permissions' => ['*'],
        ]);
        $user->setAttribute('denied_permissions', ['posts.destroy']);

        request()->replace([]);

        $this->assertTrue($user->hasPermission('posts.update'));
        $this->assertFalse($user->hasPermission('posts.destroy'));
    }

    // ------------------------------------------------------------------
    // explainPermission — deciding layer
    // ------------------------------------------------------------------

    public function test_explain_reports_the_deciding_layer(): void
    {
        [$user, $org] = $this->scenario([
            'role' => ['posts.index'],
            'granted' => ['comments.index'],
            'denied' => ['posts.destroy'],
            'legacy' => ['tags.index'],
        ]);

        $this->assertSame('denied', $user->explainPermission('posts.destroy', $org)['reason']);
        $this->assertSame('role', $user->explainPermission('posts.index', $org)['reason']);
        $this->assertSame('granted', $user->explainPermission('comments.index', $org)['reason']);
        $this->assertSame('legacy', $user->explainPermission('tags.index', $org)['reason']);
        $this->assertSame('default-deny', $user->explainPermission('widgets.index', $org)['reason']);

        $explain = $user->explainPermission('posts.index', $org);
        $this->assertTrue($explain['granted']);
    }

    // ------------------------------------------------------------------
    // Group-membership enforcement path keeps the layered behavior
    // ------------------------------------------------------------------

    public function test_enforcement_exact_route_group_row_uses_layers(): void
    {
        config(['rhino.auth.enforce_group_membership' => true]);

        $user = User::forceCreate([
            'id' => 5401, 'name' => 'E', 'email' => 'e5401@example.com', 'password' => bcrypt('x'),
        ]);
        $org = Organization::forceCreate(['id' => 5401, 'name' => 'O', 'slug' => 'o5401']);
        $role = Role::forceCreate(['id' => 5401, 'name' => 'R', 'slug' => 'r5401']);

        DB::table('org_role_permissions')->insert([
            'organization_id' => $org->id, 'role_id' => $role->id, 'permissions' => json_encode(['posts.*']),
        ]);

        UserRole::forceCreate([
            'user_id' => $user->id, 'role_id' => $role->id, 'organization_id' => $org->id,
            'route_group' => 'tenant', 'denied_permissions' => ['posts.destroy'],
        ]);

        // Role layer grants posts.*, the exact-group row denies posts.destroy.
        $this->assertTrue($user->hasPermission('posts.update', $org, 'tenant'));
        $this->assertFalse($user->hasPermission('posts.destroy', $org, 'tenant'));
        // No row for a different group → default deny (exact row is authoritative).
        $this->assertFalse($user->hasPermission('posts.update', $org, 'other'));
    }

    public function test_enforcement_null_wildcard_row_applies_when_no_exact(): void
    {
        config(['rhino.auth.enforce_group_membership' => true]);

        $user = User::forceCreate([
            'id' => 5402, 'name' => 'E', 'email' => 'e5402@example.com', 'password' => bcrypt('x'),
        ]);
        $org = Organization::forceCreate(['id' => 5402, 'name' => 'O', 'slug' => 'o5402']);
        $role = Role::forceCreate(['id' => 5402, 'name' => 'R', 'slug' => 'r5402']);

        UserRole::forceCreate([
            'user_id' => $user->id, 'role_id' => $role->id, 'organization_id' => $org->id,
            'route_group' => null, 'granted_permissions' => ['posts.index'],
        ]);

        $this->assertTrue($user->hasPermission('posts.index', $org, 'anygroup'));
        $this->assertFalse($user->hasPermission('posts.update', $org, 'anygroup'));
    }
}
