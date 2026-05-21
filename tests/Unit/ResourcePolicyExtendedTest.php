<?php

namespace Rhino\Tests\Unit;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Rhino\Policies\ResourcePolicy;
use Rhino\Tests\TestCase;

// --------------------------------------------------------------------------
// Test Policy with no resource slug and no config match
// --------------------------------------------------------------------------

class UnresolvablePolicy extends ResourcePolicy
{
    // No $resourceSlug set, and won't match any config entry
}

// --------------------------------------------------------------------------
// Test Policy for soft delete methods
// --------------------------------------------------------------------------

class SoftDeleteTestPolicy extends ResourcePolicy
{
    protected ?string $resourceSlug = 'sd_items';
}

// --------------------------------------------------------------------------
// Test Policy for hasRole testing
// --------------------------------------------------------------------------

class HasRoleTestPolicy extends ResourcePolicy
{
    protected ?string $resourceSlug = 'role_items';

    public function exposeHasRole(?Authenticatable $user, string $role): bool
    {
        return $this->hasRole($user, $role);
    }
}

// --------------------------------------------------------------------------
// Tests
// --------------------------------------------------------------------------

class ResourcePolicyExtendedTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');
    }

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
            ['name' => 'Test Org', 'slug' => 'test-org']
        );

        $role = \App\Models\Role::firstOrCreate(
            ['id' => 1],
            ['name' => 'Test Role', 'slug' => 'test-role']
        );

        \App\Models\UserRole::forceCreate([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'permissions' => $permissions,
        ]);

        request()->attributes->set('organization', $org);

        return $user;
    }

    // ------------------------------------------------------------------
    // Soft Delete authorization methods
    // ------------------------------------------------------------------

    public function test_view_trashed_checks_permission(): void
    {
        $user = $this->createUserWithPermissions(['sd_items.trashed']);
        $policy = new SoftDeleteTestPolicy();

        $this->assertTrue($policy->viewTrashed($user));
    }

    public function test_view_trashed_denied_without_permission(): void
    {
        $user = $this->createUserWithPermissions(['sd_items.index']);
        $policy = new SoftDeleteTestPolicy();

        $this->assertFalse($policy->viewTrashed($user));
    }

    public function test_view_trashed_denied_for_null_user(): void
    {
        $policy = new SoftDeleteTestPolicy();
        $this->assertFalse($policy->viewTrashed(null));
    }

    public function test_restore_checks_permission(): void
    {
        $user = $this->createUserWithPermissions(['sd_items.restore']);
        $policy = new SoftDeleteTestPolicy();

        $model = new \stdClass();
        $this->assertTrue($policy->restore($user, $model));
    }

    public function test_restore_denied_without_permission(): void
    {
        $user = $this->createUserWithPermissions(['sd_items.index']);
        $policy = new SoftDeleteTestPolicy();

        $this->assertFalse($policy->restore($user, new \stdClass()));
    }

    public function test_force_delete_checks_permission(): void
    {
        $user = $this->createUserWithPermissions(['sd_items.forceDelete']);
        $policy = new SoftDeleteTestPolicy();

        $this->assertTrue($policy->forceDelete($user, new \stdClass()));
    }

    public function test_force_delete_denied_without_permission(): void
    {
        $user = $this->createUserWithPermissions(['sd_items.index']);
        $policy = new SoftDeleteTestPolicy();

        $this->assertFalse($policy->forceDelete($user, new \stdClass()));
    }

    // ------------------------------------------------------------------
    // resolveResourceSlug returns null when no config match
    // ------------------------------------------------------------------

    public function test_unresolvable_policy_denies_by_default(): void
    {
        $user = $this->createUserWithPermissions(['*']);
        config(['rhino.models' => []]);

        $policy = new UnresolvablePolicy();

        $this->assertFalse($policy->viewAny($user));
        $this->assertFalse($policy->create($user));
    }

    // ------------------------------------------------------------------
    // hasRole helper
    // ------------------------------------------------------------------

    public function test_has_role_returns_false_for_null_user(): void
    {
        $policy = new HasRoleTestPolicy();
        $this->assertFalse($policy->exposeHasRole(null, 'admin'));
    }

    public function test_has_role_returns_true_for_matching_role(): void
    {
        $user = \App\Models\User::forceCreate([
            'id' => 30,
            'name' => 'Role User',
            'email' => 'roleuser@example.com',
            'password' => bcrypt('password'),
        ]);

        $org = \App\Models\Organization::firstOrCreate(
            ['id' => 1],
            ['name' => 'Test Org', 'slug' => 'test-org']
        );

        $role = \App\Models\Role::forceCreate([
            'id' => 30,
            'name' => 'Admin',
            'slug' => 'admin',
        ]);

        \App\Models\UserRole::forceCreate([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'permissions' => ['*'],
        ]);

        request()->attributes->set('organization', $org);

        $policy = new HasRoleTestPolicy();
        $this->assertTrue($policy->exposeHasRole($user, 'admin'));
        $this->assertFalse($policy->exposeHasRole($user, 'viewer'));
    }

    public function test_has_role_returns_false_when_user_lacks_method(): void
    {
        // Create a minimal authenticatable without getRoleSlugForValidation
        $user = new class implements Authenticatable
        {
            public function getAuthIdentifierName() { return 'id'; }
            public function getAuthIdentifier() { return 1; }
            public function getAuthPassword() { return ''; }
            public function getAuthPasswordName() { return 'password'; }
            public function getRememberToken() { return null; }
            public function setRememberToken($value) {}
            public function getRememberTokenName() { return ''; }
        };

        $policy = new HasRoleTestPolicy();
        $this->assertFalse($policy->exposeHasRole($user, 'admin'));
    }

    // ------------------------------------------------------------------
    // checkPermission fallback when user lacks hasPermission
    // ------------------------------------------------------------------

    public function test_check_permission_allows_when_user_lacks_has_permission_method(): void
    {
        // Create a minimal authenticatable without hasPermission
        $user = new class implements Authenticatable
        {
            public function getAuthIdentifierName() { return 'id'; }
            public function getAuthIdentifier() { return 1; }
            public function getAuthPassword() { return ''; }
            public function getAuthPasswordName() { return 'password'; }
            public function getRememberToken() { return null; }
            public function setRememberToken($value) {}
            public function getRememberTokenName() { return ''; }
        };

        $policy = new SoftDeleteTestPolicy();
        // Should fallback to true (backwards compatibility)
        $this->assertTrue($policy->viewAny($user));
    }
}
