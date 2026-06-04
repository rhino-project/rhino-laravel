<?php

namespace Rhino\Tests\Feature;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Rhino\Tests\TestCase;
use Rhino\Traits\HasValidation;
use Rhino\Traits\HidableColumns;

// --------------------------------------------------------------------------
// Test Models
// --------------------------------------------------------------------------

class GroupMembershipPost extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'gm_posts';
    protected $fillable = ['title', 'organization_id'];
}

/**
 * Covers Part 1 (group membership on user_roles) and Part 6 (membership
 * enforcement + permission resolution) for the CRUD data path. See
 * GROUP_AUTH_DESIGN.md §3, §6, §10.
 */
class GroupMembershipTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('gm_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->timestamps();
        });

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('auth.guards.sanctum', [
            'driver' => 'session',
            'provider' => 'users',
        ]);
        $app['config']->set('auth.providers.users', [
            'driver' => 'eloquent',
            'model' => \App\Models\User::class,
        ]);
    }

    protected function loadRoutes(array $routeGroups, bool $enforce = false): void
    {
        config([
            'rhino.models' => ['posts' => GroupMembershipPost::class],
            'rhino.route_groups' => $routeGroups,
            'rhino.auth.enforce_group_membership' => $enforce,
            'rhino.multi_tenant.organization_identifier_column' => 'id',
        ]);

        Gate::policy(GroupMembershipPost::class, \Rhino\Policies\ResourcePolicy::class);

        Route::prefix('api')->group(function () {
            require __DIR__ . '/../../routes/api.php';
        });
    }

    protected function createUser(): \App\Models\User
    {
        return \App\Models\User::forceCreate([
            'name' => 'U',
            'email' => 'u@example.com',
            'password' => bcrypt('password'),
            'permissions' => [], // empty: forces permissions to come from user_roles when enforced
        ]);
    }

    protected function createRole(string $slug = 'role'): \App\Models\Role
    {
        return \App\Models\Role::forceCreate(['name' => 'R', 'slug' => $slug]);
    }

    protected function createOrg(string $slug = 'org'): \App\Models\Organization
    {
        return \App\Models\Organization::forceCreate(['name' => 'O', 'slug' => $slug]);
    }

    protected function membership(\App\Models\User $u, ?int $orgId, ?string $rg, int $roleId, array $perms = ['*']): void
    {
        \App\Models\UserRole::forceCreate([
            'user_id' => $u->id,
            'organization_id' => $orgId,
            'role_id' => $roleId,
            'route_group' => $rg,
            'permissions' => $perms,
        ]);
    }

    // ==================================================================
    // Part 1: schema / model
    // ==================================================================

    public function test_user_role_persists_route_group_and_null_org(): void
    {
        $u = $this->createUser();
        $role = $this->createRole();

        \App\Models\UserRole::forceCreate([
            'user_id' => $u->id,
            'organization_id' => null,
            'role_id' => $role->id,
            'route_group' => 'driver',
            'permissions' => ['*'],
        ]);

        $this->assertDatabaseHas('user_roles', [
            'user_id' => $u->id,
            'organization_id' => null,
            'route_group' => 'driver',
        ]);
    }

    public function test_unique_index_allows_same_user_role_across_distinct_groups(): void
    {
        $u = $this->createUser();
        $role = $this->createRole();

        $this->membership($u, null, 'driver', $role->id);
        $this->membership($u, null, 'rider', $role->id);

        $this->assertSame(2, \App\Models\UserRole::where('user_id', $u->id)->count());
    }

    // ==================================================================
    // Part 6: enforcement OFF (regression guard)
    // ==================================================================

    public function test_flag_off_no_membership_row_still_allows_with_user_permissions(): void
    {
        $u = \App\Models\User::forceCreate([
            'name' => 'U', 'email' => 'u@example.com', 'password' => bcrypt('password'),
            'permissions' => ['*'],
        ]);
        $this->loadRoutes([
            'driver' => ['prefix' => 'driver', 'middleware' => [], 'models' => '*'],
        ], enforce: false);
        $this->actingAs($u, 'sanctum');

        // No membership row at all; flag off → user.permissions heuristic applies.
        $this->getJson('/api/driver/posts')->assertStatus(200);
    }

    public function test_flag_off_does_not_add_membership_middleware(): void
    {
        $this->loadRoutes([
            'driver' => ['prefix' => 'driver', 'middleware' => [], 'models' => '*'],
        ], enforce: false);

        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r) => $r->getName() === 'driver.posts.index');

        $this->assertNotContains(\Rhino\Http\Middleware\EnforceGroupMembership::class, $route->gatherMiddleware());
    }

    // ==================================================================
    // Part 6: enforcement ON
    // ==================================================================

    public function test_enforced_member_of_group_allowed(): void
    {
        $u = $this->createUser();
        $role = $this->createRole();
        $this->loadRoutes([
            'driver' => ['prefix' => 'driver', 'middleware' => [], 'models' => '*'],
        ], enforce: true);
        $this->membership($u, null, 'driver', $role->id, ['*']);
        $this->actingAs($u, 'sanctum');

        $this->getJson('/api/driver/posts')->assertStatus(200);
    }

    public function test_enforced_non_member_denied_403(): void
    {
        $u = $this->createUser();
        $role = $this->createRole();
        $this->loadRoutes([
            'driver' => ['prefix' => 'driver', 'middleware' => [], 'models' => '*'],
            'rider' => ['prefix' => 'rider', 'middleware' => [], 'models' => '*'],
        ], enforce: true);
        // Member of driver only.
        $this->membership($u, null, 'driver', $role->id, ['*']);
        $this->actingAs($u, 'sanctum');

        $this->getJson('/api/rider/posts')->assertStatus(403)
            ->assertJson(['message' => 'You are not a member of this group']);
    }

    public function test_enforced_null_wildcard_matches_any_group(): void
    {
        $u = $this->createUser();
        $role = $this->createRole();
        $this->loadRoutes([
            'driver' => ['prefix' => 'driver', 'middleware' => [], 'models' => '*'],
        ], enforce: true);
        $this->membership($u, null, null, $role->id, ['*']);
        $this->actingAs($u, 'sanctum');

        $this->getJson('/api/driver/posts')->assertStatus(200);
    }

    public function test_enforced_non_tenant_group_ignores_org(): void
    {
        $u = $this->createUser();
        $role = $this->createRole();
        $org = $this->createOrg();
        $this->loadRoutes([
            'driver' => ['prefix' => 'driver', 'middleware' => [], 'models' => '*'],
        ], enforce: true);
        // Membership scoped to an org, but driver is a non-tenant group (no org
        // on the request) → org is ignored, membership still matches.
        $this->membership($u, $org->id, 'driver', $role->id, ['*']);
        $this->actingAs($u, 'sanctum');

        $this->getJson('/api/driver/posts')->assertStatus(200);
    }

    public function test_enforced_tenant_group_requires_matching_org(): void
    {
        $u = $this->createUser();
        $role = $this->createRole();
        $orgA = $this->createOrg('org-a');
        $orgB = $this->createOrg('org-b');
        $this->loadRoutes([
            'tenant' => [
                'prefix' => '{organization}',
                'middleware' => [\Rhino\Http\Middleware\ResolveOrganizationFromRoute::class],
                'models' => '*',
            ],
        ], enforce: true);
        // Member of tenant group in org A only.
        $this->membership($u, $orgA->id, 'tenant', $role->id, ['*']);
        $this->actingAs($u, 'sanctum');

        // Org A → allowed.
        $this->getJson("/api/{$orgA->id}/posts")->assertStatus(200);
    }

    public function test_enforced_permission_resolves_from_matching_membership_row(): void
    {
        $u = $this->createUser();
        $role = $this->createRole();
        $this->loadRoutes([
            'driver' => ['prefix' => 'driver', 'middleware' => [], 'models' => '*'],
        ], enforce: true);
        // Member of driver but with NO index permission → 403 from the policy
        // (membership passes, permissions do not).
        $this->membership($u, null, 'driver', $role->id, ['posts.store']);
        $this->actingAs($u, 'sanctum');

        $this->getJson('/api/driver/posts')->assertStatus(403);

        // user.permissions has '*' would NOT matter when enforced — prove it.
        $u->forceFill(['permissions' => ['*']])->save();
        $this->getJson('/api/driver/posts')->assertStatus(403);
    }

    public function test_enforced_exact_row_takes_precedence_over_null_wildcard(): void
    {
        // Coexistence: a scoped 'driver' row (restrictive) AND a NULL-wildcard
        // row with '*'. The exact row must win — the broad wildcard must NOT
        // override the deliberately restricted per-group permissions. (Fix #4.)
        $u = $this->createUser();
        $role = $this->createRole();
        $this->loadRoutes([
            'driver' => ['prefix' => 'driver', 'middleware' => [], 'models' => '*'],
        ], enforce: true);

        // Exact driver row: only posts.store (no index).
        $this->membership($u, null, 'driver', $role->id, ['posts.store']);
        // NULL-wildcard row granting everything.
        $this->membership($u, null, null, $role->id, ['*']);
        $this->actingAs($u, 'sanctum');

        // index is denied: the exact 'driver' row is authoritative, the wildcard
        // '*' row is ignored because an exact row exists for this group.
        $this->getJson('/api/driver/posts')->assertStatus(403);
    }

    public function test_enforced_null_wildcard_used_when_no_exact_row(): void
    {
        // With NO exact 'driver' row, the NULL-wildcard row's permissions apply.
        $u = $this->createUser();
        $role = $this->createRole();
        $this->loadRoutes([
            'driver' => ['prefix' => 'driver', 'middleware' => [], 'models' => '*'],
        ], enforce: true);

        $this->membership($u, null, null, $role->id, ['*']);
        $this->actingAs($u, 'sanctum');

        $this->getJson('/api/driver/posts')->assertStatus(200);
    }

    public function test_enforced_tenant_member_of_org_a_denied_on_org_b_by_gate(): void
    {
        // The membership gate itself (not just the policy) must 403 a tenant
        // member of org A who hits org B — proving the gate's org-match runs,
        // which requires the org to be resolved BEFORE the gate. (Fix #6.)
        $u = $this->createUser();
        $role = $this->createRole();
        $orgA = $this->createOrg('org-a');
        $orgB = $this->createOrg('org-b');

        // Belong to BOTH orgs (a user_roles row per org makes the user a member
        // for ResolveOrganizationFromRoute, which otherwise 404s non-members).
        // The org-A row is a 'tenant' membership; the org-B row is scoped to a
        // DIFFERENT group ('other') so it does NOT satisfy the tenant gate for
        // org B — only the org-scoped tenant row for org A does.
        $this->membership($u, $orgA->id, 'tenant', $role->id, ['*']);
        $this->membership($u, $orgB->id, 'other', $role->id, ['*']);

        $this->loadRoutes([
            'tenant' => [
                'prefix' => '{organization}',
                'middleware' => [\Rhino\Http\Middleware\ResolveOrganizationFromRoute::class],
                'models' => '*',
            ],
        ], enforce: true);
        $this->actingAs($u, 'sanctum');

        // Org A → member, allowed.
        $this->getJson("/api/{$orgA->id}/posts")->assertStatus(200);

        // Org B → no tenant membership scoped to org B → gate 403s.
        $this->getJson("/api/{$orgB->id}/posts")
            ->assertStatus(403)
            ->assertJson(['message' => 'You are not a member of this group']);
    }

    public function test_enforced_permission_granted_from_membership_row(): void
    {
        $u = $this->createUser();
        $role = $this->createRole();
        $this->loadRoutes([
            'driver' => ['prefix' => 'driver', 'middleware' => [], 'models' => '*'],
        ], enforce: true);
        $this->membership($u, null, 'driver', $role->id, ['posts.index']);
        $this->actingAs($u, 'sanctum');

        $this->getJson('/api/driver/posts')->assertStatus(200);
    }

    public function test_enforced_adds_membership_middleware_to_routes(): void
    {
        $this->loadRoutes([
            'driver' => ['prefix' => 'driver', 'middleware' => [], 'models' => '*'],
        ], enforce: true);

        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r) => $r->getName() === 'driver.posts.index');

        $this->assertContains(\Rhino\Http\Middleware\EnforceGroupMembership::class, $route->gatherMiddleware());
    }

    // ==================================================================
    // §11.2 — membership denial is 403 (not 404) when enforcement is ON.
    // ==================================================================

    protected function tenantGroup(): array
    {
        return [
            'tenant' => [
                'prefix' => '{organization}',
                'middleware' => [\Rhino\Http\Middleware\ResolveOrganizationFromRoute::class],
                'models' => '*',
            ],
        ];
    }

    public function test_enforced_authenticated_non_member_of_org_gets_403_not_404(): void
    {
        // The headline §11.2 fix: an authenticated user who is NOT a member of
        // the requested org/group must get 403 — taking precedence over
        // ResolveOrganizationFromRoute's info-hiding 404 (the user does not
        // belong to the org at all). The gate runs first and resolves the org.
        $u = $this->createUser();
        $role = $this->createRole();
        $orgA = $this->createOrg('org-a');
        $orgB = $this->createOrg('org-b');

        // Member of tenant group in org A only; NO row whatsoever for org B, so
        // ResolveOrganizationFromRoute would previously 404 the org-B request.
        $this->membership($u, $orgA->id, 'tenant', $role->id, ['*']);

        $this->loadRoutes($this->tenantGroup(), enforce: true);
        $this->actingAs($u, 'sanctum');

        $this->getJson("/api/{$orgB->id}/posts")
            ->assertStatus(403)
            ->assertJson(['message' => 'You are not a member of this group']);
    }

    public function test_enforced_member_of_org_gets_200(): void
    {
        $u = $this->createUser();
        $role = $this->createRole();
        $orgA = $this->createOrg('org-a');

        $this->membership($u, $orgA->id, 'tenant', $role->id, ['*']);

        $this->loadRoutes($this->tenantGroup(), enforce: true);
        $this->actingAs($u, 'sanctum');

        $this->getJson("/api/{$orgA->id}/posts")->assertStatus(200);
    }

    public function test_enforced_genuinely_missing_org_still_404(): void
    {
        // A genuinely non-existent org must still 404 (not 403): the gate passes
        // through and ResolveOrganizationFromRoute returns its 404.
        $u = $this->createUser();
        $role = $this->createRole();
        $orgA = $this->createOrg('org-a');

        // Wildcard membership so the user is a member of any (group, org) it
        // can resolve — proving the 404 comes from the missing org, not denial.
        $this->membership($u, null, null, $role->id, ['*']);

        $this->loadRoutes($this->tenantGroup(), enforce: true);
        $this->actingAs($u, 'sanctum');

        $this->getJson('/api/999999/posts')->assertStatus(404)
            ->assertJson(['message' => 'Organization not found']);
    }

    public function test_enforcement_off_cross_org_still_404_unchanged(): void
    {
        // Flag OFF (default): cross-org access keeps today's info-hiding 404 from
        // ResolveOrganizationFromRoute, byte-for-byte unchanged — no 403.
        $u = \App\Models\User::forceCreate([
            'name' => 'U', 'email' => 'u@example.com', 'password' => bcrypt('password'),
            'permissions' => ['*'],
        ]);
        $role = $this->createRole();
        $orgA = $this->createOrg('org-a');
        $orgB = $this->createOrg('org-b');

        // Member of org A only.
        $this->membership($u, $orgA->id, 'tenant', $role->id, ['*']);

        $this->loadRoutes($this->tenantGroup(), enforce: false);
        $this->actingAs($u, 'sanctum');

        $this->getJson("/api/{$orgB->id}/posts")
            ->assertStatus(404)
            ->assertJson(['message' => 'Organization not found']);
    }

    public function test_enforcement_off_does_not_add_gate_for_tenant_group(): void
    {
        // Regression guard: flag OFF → the membership gate is absent and the
        // tenant middleware stack is exactly ResolveOrganizationFromRoute.
        $this->loadRoutes($this->tenantGroup(), enforce: false);

        $route = collect(Route::getRoutes()->getRoutes())
            ->first(fn ($r) => $r->getName() === 'tenant.posts.index');

        $this->assertNotContains(
            \Rhino\Http\Middleware\EnforceGroupMembership::class,
            $route->gatherMiddleware()
        );
    }
}
