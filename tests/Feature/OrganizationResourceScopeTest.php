<?php

namespace Rhino\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Rhino\Tests\TestCase;

class OrganizationResourceScopeTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        Gate::policy(\App\Models\Organization::class, OrganizationScopeTestPolicy::class);
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

    protected function registerRoutes(): void
    {
        config([
            'rhino.models' => ['organizations' => \App\Models\Organization::class],
            'rhino.route_groups' => [
                'tenant' => [
                    'prefix' => '{organization}',
                    'middleware' => [\Rhino\Http\Middleware\ResolveOrganizationFromRoute::class],
                    'models' => '*',
                ],
            ],
            'rhino.multi_tenant' => [
                'organization_identifier_column' => 'slug',
            ],
        ]);

        Route::prefix('api')->group(function () {
            require __DIR__ . '/../../routes/api.php';
        });
    }

    protected function createUserWithOrganization(string $orgSlug, array $permissions = ['*']): \App\Models\User
    {
        $user = \App\Models\User::forceCreate([
            'id' => 1,
            'name' => 'Test User',
            'email' => 'user@example.com',
            'password' => bcrypt('password'),
        ]);

        $org = \App\Models\Organization::firstOrCreate(
            ['slug' => $orgSlug],
            ['name' => ucfirst($orgSlug), 'domain' => null]
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

        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_organizations_index_returns_only_current_organization(): void
    {
        $this->registerRoutes();

        \App\Models\Organization::forceCreate(['id' => 1, 'name' => 'Org One', 'slug' => 'org-one', 'domain' => null]);
        \App\Models\Organization::forceCreate(['id' => 2, 'name' => 'Org Two', 'slug' => 'org-two', 'domain' => null]);

        $this->createUserWithOrganization('org-one');

        $response = $this->getJson('/api/org-one/organizations');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertCount(1, $data);
        $this->assertSame(1, $data[0]['id']);
        $this->assertSame('org-one', $data[0]['slug']);
    }

    public function test_organizations_show_returns_404_when_route_id_does_not_match_current_organization(): void
    {
        $this->registerRoutes();

        \App\Models\Organization::forceCreate(['id' => 1, 'name' => 'Org One', 'slug' => 'org-one', 'domain' => null]);
        \App\Models\Organization::forceCreate(['id' => 2, 'name' => 'Org Two', 'slug' => 'org-two', 'domain' => null]);

        $this->createUserWithOrganization('org-one');

        // Requesting id=2 while in org-one context: route id must match current org, so 404
        $response = $this->getJson('/api/org-one/organizations/2');

        $response->assertStatus(404);
        $response->assertJson(['message' => 'Organization not found']);
    }

    public function test_organizations_show_returns_404_for_invalid_route_id(): void
    {
        $this->registerRoutes();

        \App\Models\Organization::forceCreate(['id' => 1, 'name' => 'Org One', 'slug' => 'org-one', 'domain' => null]);

        $this->createUserWithOrganization('org-one');

        $response = $this->getJson('/api/org-one/organizations/2D');

        $response->assertStatus(404);
        $response->assertJson(['message' => 'Organization not found']);
    }

    public function test_organizations_show_returns_current_organization_when_route_id_matches(): void
    {
        $this->registerRoutes();

        \App\Models\Organization::forceCreate(['id' => 1, 'name' => 'Org One', 'slug' => 'org-one', 'domain' => null]);

        $this->createUserWithOrganization('org-one');

        $response = $this->getJson('/api/org-one/organizations/1');

        $response->assertStatus(200);
        $data = $response->json();
        $this->assertSame(1, $data['id']);
        $this->assertSame('org-one', $data['slug']);
    }
}

class OrganizationScopeTestPolicy
{
    public function viewAny(?Authenticatable $user): bool
    {
        return true;
    }

    public function view(?Authenticatable $user, $model): bool
    {
        return true;
    }

    public function create(?Authenticatable $user): bool
    {
        return true;
    }

    public function update(?Authenticatable $user, $model): bool
    {
        return true;
    }

    public function delete(?Authenticatable $user, $model): bool
    {
        return true;
    }
}
