<?php

namespace Rhino\Tests\Unit;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Rhino\Policies\ResourcePolicy;
use Rhino\Tests\TestCase;
use Rhino\Traits\HasValidation;
use Rhino\Traits\HidableColumns;

// --------------------------------------------------------------------------
// Test Models
// --------------------------------------------------------------------------

class PermissionPost extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'permission_posts';
    protected $fillable = ['title', 'content'];
}

// --------------------------------------------------------------------------
// Test Policies
// --------------------------------------------------------------------------

/**
 * Policy that uses default ResourcePolicy behavior (convention-based permissions).
 */
class PermissionPostPolicy extends ResourcePolicy
{
    // Uses $resourceSlug auto-resolution from config
}

/**
 * Policy with explicit resource slug.
 */
class ExplicitSlugPolicy extends ResourcePolicy
{
    protected ?string $resourceSlug = 'posts';
}

/**
 * Policy that overrides a method and composes with parent.
 */
class OverrideWithParentPolicy extends ResourcePolicy
{
    protected ?string $resourceSlug = 'posts';

    /**
     * Custom delete: only allow if user owns the post AND has permission.
     */
    public function delete(?Authenticatable $user, $model): bool
    {
        if (!parent::delete($user, $model)) {
            return false;
        }

        // Additional check: user must own the post
        return $user->getAuthIdentifier() === ($model->user_id ?? null);
    }
}

/**
 * Policy that fully overrides a method (ignores permissions).
 */
class FullOverridePolicy extends ResourcePolicy
{
    protected ?string $resourceSlug = 'posts';

    /**
     * Anyone authenticated can view, regardless of permissions.
     */
    public function viewAny(?Authenticatable $user): bool
    {
        return $user !== null;
    }
}

// --------------------------------------------------------------------------
// Tests
// --------------------------------------------------------------------------

class PermissionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        Schema::create('permission_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    /**
     * Helper: create a user, role, org, and assign permissions via user_roles (org-scoped).
     */
    protected function createUserWithPermissions(array $permissions, int $userId = 1): \App\Models\User
    {
        $user = \App\Models\User::firstOrCreate(
            ['id' => $userId],
            [
                'name' => "User {$userId}",
                'email' => "user{$userId}@example.com",
                'password' => bcrypt('password'),
            ]
        );

        $org = \App\Models\Organization::firstOrCreate(
            ['id' => 1],
            [
                'name' => 'Test Org',
                'slug' => 'test-org',
            ]
        );

        $role = \App\Models\Role::firstOrCreate(
            ['id' => 1],
            [
                'name' => 'Test Role',
                'slug' => 'test-role',
            ]
        );

        \App\Models\UserRole::forceCreate([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'permissions' => $permissions,
        ]);

        // Set organization on request attributes for policy resolution
        request()->attributes->set('organization', $org);

        return $user;
    }

    /**
     * Helper: create a user with no permissions.
     */
    protected function createUserWithoutPermissions(int $userId = 2): \App\Models\User
    {
        $user = \App\Models\User::forceCreate([
            'id' => $userId,
            'name' => "User {$userId}",
            'email' => "user{$userId}@example.com",
            'password' => bcrypt('password'),
        ]);

        return $user;
    }

    /**
     * Helper: create a user with direct permissions on the users table (non-org-scoped).
     */
    protected function createUserWithDirectPermissions(array $permissions, int $userId = 50): \App\Models\User
    {
        return \App\Models\User::forceCreate([
            'id' => $userId,
            'name' => "Direct Perm User {$userId}",
            'email' => "direct{$userId}@example.com",
            'password' => bcrypt('password'),
            'permissions' => $permissions,
        ]);
    }

    // ------------------------------------------------------------------
    // Basic permission checks (org-scoped via user_roles)
    // ------------------------------------------------------------------

    public function test_user_with_exact_permission_is_allowed(): void
    {
        $user = $this->createUserWithPermissions(['posts.index']);

        Gate::policy(PermissionPost::class, ExplicitSlugPolicy::class);
        config(['rhino.models' => ['posts' => PermissionPost::class]]);

        $policy = new ExplicitSlugPolicy();

        $this->assertTrue($policy->viewAny($user));
    }

    public function test_user_without_permission_is_denied(): void
    {
        $user = $this->createUserWithPermissions(['posts.index']);

        Gate::policy(PermissionPost::class, ExplicitSlugPolicy::class);
        config(['rhino.models' => ['posts' => PermissionPost::class]]);

        $policy = new ExplicitSlugPolicy();

        // Has posts.index but NOT posts.store
        $this->assertFalse($policy->create($user));
    }

    public function test_guest_user_is_denied(): void
    {
        config(['rhino.models' => ['posts' => PermissionPost::class]]);

        $policy = new ExplicitSlugPolicy();

        $this->assertFalse($policy->viewAny(null));
        $this->assertFalse($policy->view(null, new PermissionPost()));
        $this->assertFalse($policy->create(null));
        $this->assertFalse($policy->update(null, new PermissionPost()));
        $this->assertFalse($policy->delete(null, new PermissionPost()));
    }

    // ------------------------------------------------------------------
    // Wildcard permissions
    // ------------------------------------------------------------------

    public function test_wildcard_grants_all_access(): void
    {
        $user = $this->createUserWithPermissions(['*']);

        Gate::policy(PermissionPost::class, ExplicitSlugPolicy::class);
        config(['rhino.models' => ['posts' => PermissionPost::class]]);

        $policy = new ExplicitSlugPolicy();

        $this->assertTrue($policy->viewAny($user));
        $this->assertTrue($policy->view($user, new PermissionPost()));
        $this->assertTrue($policy->create($user));
        $this->assertTrue($policy->update($user, new PermissionPost()));
        $this->assertTrue($policy->delete($user, new PermissionPost()));
    }

    public function test_resource_wildcard_grants_all_actions_on_resource(): void
    {
        $user = $this->createUserWithPermissions(['posts.*']);

        Gate::policy(PermissionPost::class, ExplicitSlugPolicy::class);
        config(['rhino.models' => ['posts' => PermissionPost::class]]);

        $policy = new ExplicitSlugPolicy();

        $this->assertTrue($policy->viewAny($user));
        $this->assertTrue($policy->view($user, new PermissionPost()));
        $this->assertTrue($policy->create($user));
        $this->assertTrue($policy->update($user, new PermissionPost()));
        $this->assertTrue($policy->delete($user, new PermissionPost()));
    }

    // ------------------------------------------------------------------
    // Individual action permissions
    // ------------------------------------------------------------------

    public function test_each_action_maps_to_correct_permission(): void
    {
        Gate::policy(PermissionPost::class, ExplicitSlugPolicy::class);
        config(['rhino.models' => ['posts' => PermissionPost::class]]);

        $post = new PermissionPost();

        // Test each action individually
        $actionMap = [
            'viewAny' => 'posts.index',
            'view' => 'posts.show',
            'create' => 'posts.store',
            'update' => 'posts.update',
            'delete' => 'posts.destroy',
        ];

        foreach ($actionMap as $method => $permission) {
            // Create a fresh user with only this permission
            \App\Models\UserRole::query()->delete();
            \App\Models\User::query()->delete();

            $user = $this->createUserWithPermissions([$permission]);
            $policy = new ExplicitSlugPolicy();

            // The method with the matching permission should pass
            $args = in_array($method, ['viewAny', 'create']) ? [$user] : [$user, $post];
            $this->assertTrue(
                $policy->$method(...$args),
                "Expected {$method} to be allowed with permission '{$permission}'"
            );

            // Other methods should fail (they don't have the permission)
            foreach ($actionMap as $otherMethod => $otherPermission) {
                if ($otherMethod === $method) {
                    continue;
                }
                $otherArgs = in_array($otherMethod, ['viewAny', 'create']) ? [$user] : [$user, $post];
                $this->assertFalse(
                    $policy->$otherMethod(...$otherArgs),
                    "Expected {$otherMethod} to be denied when only '{$permission}' is granted"
                );
            }
        }
    }

    // ------------------------------------------------------------------
    // Multiple permissions
    // ------------------------------------------------------------------

    public function test_user_with_multiple_permissions(): void
    {
        $user = $this->createUserWithPermissions(['posts.index', 'posts.show', 'posts.store']);

        Gate::policy(PermissionPost::class, ExplicitSlugPolicy::class);
        config(['rhino.models' => ['posts' => PermissionPost::class]]);

        $policy = new ExplicitSlugPolicy();
        $post = new PermissionPost();

        $this->assertTrue($policy->viewAny($user));
        $this->assertTrue($policy->view($user, $post));
        $this->assertTrue($policy->create($user));
        $this->assertFalse($policy->update($user, $post)); // not granted
        $this->assertFalse($policy->delete($user, $post)); // not granted
    }

    // ------------------------------------------------------------------
    // User without any permissions
    // ------------------------------------------------------------------

    public function test_user_without_user_roles_is_denied(): void
    {
        $user = $this->createUserWithoutPermissions();

        Gate::policy(PermissionPost::class, ExplicitSlugPolicy::class);
        config(['rhino.models' => ['posts' => PermissionPost::class]]);

        $policy = new ExplicitSlugPolicy();

        $this->assertFalse($policy->viewAny($user));
        $this->assertFalse($policy->create($user));
    }

    // ------------------------------------------------------------------
    // Policy override patterns
    // ------------------------------------------------------------------

    public function test_override_with_parent_composition(): void
    {
        $user = $this->createUserWithPermissions(['posts.destroy']);

        Gate::policy(PermissionPost::class, OverrideWithParentPolicy::class);
        config(['rhino.models' => ['posts' => PermissionPost::class]]);

        $policy = new OverrideWithParentPolicy();

        // User owns the post AND has permission → allowed
        $ownedPost = new PermissionPost();
        $ownedPost->user_id = $user->id;
        $this->assertTrue($policy->delete($user, $ownedPost));

        // User has permission but does NOT own the post → denied
        $otherPost = new PermissionPost();
        $otherPost->user_id = 999;
        $this->assertFalse($policy->delete($user, $otherPost));
    }

    public function test_override_with_parent_denied_by_permission(): void
    {
        // User does NOT have posts.destroy permission
        $user = $this->createUserWithPermissions(['posts.index']);

        Gate::policy(PermissionPost::class, OverrideWithParentPolicy::class);
        config(['rhino.models' => ['posts' => PermissionPost::class]]);

        $policy = new OverrideWithParentPolicy();

        // User owns the post but lacks permission → denied by parent
        $ownedPost = new PermissionPost();
        $ownedPost->user_id = $user->id;
        $this->assertFalse($policy->delete($user, $ownedPost));
    }

    public function test_full_override_ignores_permissions(): void
    {
        // User has no relevant permissions at all
        $user = $this->createUserWithoutPermissions(3);

        Gate::policy(PermissionPost::class, FullOverridePolicy::class);
        config(['rhino.models' => ['posts' => PermissionPost::class]]);

        $policy = new FullOverridePolicy();

        // viewAny is fully overridden — just checks if user is authenticated
        $this->assertTrue($policy->viewAny($user));

        // Other methods still use ResourcePolicy defaults → denied
        $this->assertFalse($policy->create($user));
    }

    // ------------------------------------------------------------------
    // Auto-resolution of resource slug from config
    // ------------------------------------------------------------------

    public function test_auto_resolves_slug_from_config(): void
    {
        $user = $this->createUserWithPermissions(['posts.index']);

        Gate::policy(PermissionPost::class, PermissionPostPolicy::class);
        config(['rhino.models' => ['posts' => PermissionPost::class]]);

        $policy = new PermissionPostPolicy();

        // Should auto-resolve 'posts' from config
        $this->assertTrue($policy->viewAny($user));
    }

    // ------------------------------------------------------------------
    // Organization-scoped permissions (via user_roles)
    // ------------------------------------------------------------------

    public function test_permissions_are_scoped_to_organization(): void
    {
        $user = \App\Models\User::forceCreate([
            'id' => 10,
            'name' => 'Multi-org User',
            'email' => 'multiorg@example.com',
            'password' => bcrypt('password'),
        ]);

        $org1 = \App\Models\Organization::forceCreate([
            'id' => 10,
            'name' => 'Org A',
            'slug' => 'org-a',
        ]);

        $org2 = \App\Models\Organization::forceCreate([
            'id' => 11,
            'name' => 'Org B',
            'slug' => 'org-b',
        ]);

        $role = \App\Models\Role::firstOrCreate(
            ['slug' => 'test-role'],
            ['name' => 'Test Role']
        );

        // Full access in org1
        \App\Models\UserRole::forceCreate([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'organization_id' => $org1->id,
            'permissions' => ['*'],
        ]);

        // Read-only in org2
        \App\Models\UserRole::forceCreate([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'organization_id' => $org2->id,
            'permissions' => ['posts.index', 'posts.show'],
        ]);

        Gate::policy(PermissionPost::class, ExplicitSlugPolicy::class);
        config(['rhino.models' => ['posts' => PermissionPost::class]]);

        $policy = new ExplicitSlugPolicy();

        // In org1: can do everything
        request()->attributes->set('organization', $org1);
        $this->assertTrue($policy->create($user));
        $this->assertTrue($policy->delete($user, new PermissionPost()));

        // In org2: read-only
        request()->attributes->set('organization', $org2);
        $this->assertTrue($policy->viewAny($user));
        $this->assertFalse($policy->create($user));
        $this->assertFalse($policy->delete($user, new PermissionPost()));
    }

    // ------------------------------------------------------------------
    // ResourcePolicy default hiddenColumns still works
    // ------------------------------------------------------------------

    public function test_hidden_columns_still_works_with_permissions(): void
    {
        $policy = new ResourcePolicy();
        $this->assertEquals([], $policy->hiddenColumns(null));
    }

    // ------------------------------------------------------------------
    // HasPermittedAttributes defaults on ResourcePolicy
    // ------------------------------------------------------------------

    public function test_resource_policy_default_permitted_attributes_for_create(): void
    {
        $policy = new ResourcePolicy();
        $this->assertSame(['*'], $policy->permittedAttributesForCreate(null));
    }

    public function test_resource_policy_default_permitted_attributes_for_update(): void
    {
        $policy = new ResourcePolicy();
        $this->assertSame(['*'], $policy->permittedAttributesForUpdate(null));
    }

    public function test_resource_policy_default_permitted_attributes_for_show(): void
    {
        $policy = new ResourcePolicy();
        $this->assertSame(['*'], $policy->permittedAttributesForShow(null));
    }

    public function test_resource_policy_default_hidden_attributes_for_show(): void
    {
        $policy = new ResourcePolicy();
        $this->assertSame([], $policy->hiddenAttributesForShow(null));
    }

    // ------------------------------------------------------------------
    // User-level permissions (non-org-scoped, via users.permissions)
    // ------------------------------------------------------------------

    public function test_user_permissions_used_when_no_org_context(): void
    {
        $user = $this->createUserWithDirectPermissions(['posts.index', 'posts.show']);

        // No organization on request — uses users.permissions
        request()->replace([]);

        $this->assertTrue($user->hasPermission('posts.index'));
        $this->assertTrue($user->hasPermission('posts.show'));
        $this->assertFalse($user->hasPermission('posts.store'));
        $this->assertFalse($user->hasPermission('posts.destroy'));
    }

    public function test_user_permissions_wildcard_grants_all(): void
    {
        $user = $this->createUserWithDirectPermissions(['*']);

        request()->replace([]);

        $this->assertTrue($user->hasPermission('posts.index'));
        $this->assertTrue($user->hasPermission('posts.store'));
        $this->assertTrue($user->hasPermission('anything.here'));
    }

    public function test_user_permissions_resource_wildcard(): void
    {
        $user = $this->createUserWithDirectPermissions(['posts.*']);

        request()->replace([]);

        $this->assertTrue($user->hasPermission('posts.index'));
        $this->assertTrue($user->hasPermission('posts.store'));
        $this->assertFalse($user->hasPermission('categories.index'));
    }

    public function test_org_context_checks_user_roles_not_user_permissions(): void
    {
        // User has broad direct permissions but limited org-scoped permissions
        $user = \App\Models\User::forceCreate([
            'id' => 60,
            'name' => 'Hybrid User',
            'email' => 'hybrid@example.com',
            'password' => bcrypt('password'),
            'permissions' => ['*'],
        ]);

        $org = \App\Models\Organization::firstOrCreate(
            ['id' => 100],
            ['name' => 'Perm Org', 'slug' => 'perm-org']
        );

        $role = \App\Models\Role::firstOrCreate(
            ['id' => 100],
            ['name' => 'Limited', 'slug' => 'limited']
        );

        \App\Models\UserRole::forceCreate([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'permissions' => ['posts.index'],
        ]);

        // With org context: uses user_roles.permissions (limited), not users.permissions
        $this->assertTrue($user->hasPermission('posts.index', $org));
        $this->assertFalse($user->hasPermission('posts.store', $org));
    }

    public function test_user_role_permissions_used_when_org_context_present(): void
    {
        // User has direct permissions AND org-scoped permissions
        $user = \App\Models\User::forceCreate([
            'id' => 70,
            'name' => 'Dual User',
            'email' => 'dual@example.com',
            'password' => bcrypt('password'),
            'permissions' => ['posts.index'],
        ]);

        $org = \App\Models\Organization::firstOrCreate(
            ['id' => 200],
            ['name' => 'Check Org', 'slug' => 'check-org']
        );

        $role = \App\Models\Role::firstOrCreate(
            ['id' => 200],
            ['name' => 'Full', 'slug' => 'full']
        );

        \App\Models\UserRole::forceCreate([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'permissions' => ['*'],
        ]);

        // With org context: uses user_roles.permissions (full access)
        $this->assertTrue($user->hasPermission('posts.store', $org));
        $this->assertTrue($user->hasPermission('anything.here', $org));

        // Without org context: uses users.permissions (limited)
        $this->assertTrue($user->hasPermission('posts.index'));
        $this->assertFalse($user->hasPermission('posts.store'));
    }

    public function test_user_without_permissions_is_denied(): void
    {
        // User with no direct permissions and no org context
        $user = $this->createUserWithoutPermissions(80);

        $this->assertFalse($user->hasPermission('posts.index'));
    }
}
