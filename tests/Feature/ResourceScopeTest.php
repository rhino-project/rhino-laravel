<?php

namespace Rhino\Tests\Feature;

use App\Models\Scopes\ScopeOwnedTaskScope;
use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Rhino\Controllers\GlobalController;
use Rhino\Exceptions\MissingTenantContext;
use Rhino\Facades\Rhino;
use Rhino\Models\RhinoModel;
use Rhino\Support\InteractsWithRhinoResources;
use Rhino\Support\RhinoContext;
use Rhino\Traits\BelongsToOrganization;
use Rhino\Tests\TestCase;

// ==========================================================================
// Test models (inline)
// ==========================================================================

/**
 * Column-scoped model: uses BelongsToOrganization (organization_id column).
 */
class ScopeTask extends RhinoModel
{
    use BelongsToOrganization;

    protected $table = 'scope_tasks';

    protected $fillable = ['organization_id', 'title', 'points'];

    public static $allowedScopes = ['highValue'];

    public function scopeHighValue(Builder $query, ?Authenticatable $user): Builder
    {
        return $query->where('points', '>=', 100);
    }
}

/**
 * Column-scoped model whose {Model}Scope filters by the CURRENT user (owner).
 * Proves the app's user-aware global scope resolves in explicit mode.
 */
class ScopeOwnedTask extends RhinoModel
{
    use BelongsToOrganization;

    protected $table = 'scope_owned_tasks';

    protected $fillable = ['organization_id', 'owner_id', 'title'];
}

/**
 * Relationship-scoped chain: ScopeComment -> ScopePost -> ScopeBlog(org_id).
 * Org is reached only via a BelongsTo chain — a naive Model::query() leaks.
 */
class ScopeBlog extends RhinoModel
{
    use BelongsToOrganization;

    protected $table = 'scope_blogs';

    protected $fillable = ['organization_id', 'name'];
}

class ScopePost extends RhinoModel
{
    protected $table = 'scope_posts';

    protected $fillable = ['blog_id', 'title'];

    public function blog(): BelongsTo
    {
        return $this->belongsTo(ScopeBlog::class, 'blog_id');
    }
}

class ScopeComment extends RhinoModel
{
    protected $table = 'scope_comments';

    protected $fillable = ['post_id', 'body'];

    public function post(): BelongsTo
    {
        return $this->belongsTo(ScopePost::class, 'post_id');
    }
}

/**
 * Truly global model: no organization mechanism at all.
 */
class ScopeGlobalTag extends RhinoModel
{
    protected $table = 'scope_global_tags';

    protected $fillable = ['name'];
}

// ==========================================================================
// Test class
// ==========================================================================

class ResourceScopeTest extends TestCase
{
    protected \App\Models\Organization $orgA;

    protected \App\Models\Organization $orgB;

    protected \App\Models\User $userA;

    protected \App\Models\User $userB;

    protected function setUp(): void
    {
        parent::setUp();

        // Ensure the convention-based auto-scope class is loaded (matches the
        // harness pattern of require_once-ing test model files directly).
        require_once __DIR__ . '/../Models/Scopes/ScopeOwnedTaskScope.php';

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        Schema::create('scope_tasks', function ($table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->string('title');
            $table->integer('points')->default(0);
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('scope_owned_tasks', function ($table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->foreignId('owner_id');
            $table->string('title');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('scope_blogs', function ($table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('scope_posts', function ($table) {
            $table->id();
            $table->foreignId('blog_id');
            $table->string('title');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('scope_comments', function ($table) {
            $table->id();
            $table->foreignId('post_id');
            $table->string('body');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('scope_global_tags', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });

        Gate::policy(ScopeTask::class, ScopeResourceTestPolicy::class);
        Gate::policy(ScopeOwnedTask::class, ScopeResourceTestPolicy::class);
        Gate::policy(ScopeComment::class, ScopeResourceTestPolicy::class);
        Gate::policy(ScopeGlobalTag::class, ScopeResourceTestPolicy::class);
        Gate::policy(\App\Models\Organization::class, ScopeResourceTestPolicy::class);

        // Clear the auto-detect path caches (per-using-class static).
        $this->clearPathCache(GlobalController::class);
        $this->clearPathCache(\Rhino\Support\ResourceScope::class);

        // Reset the ScopeOwnedTask owner-scope toggle each test.
        ScopeOwnedTaskScope::$enabled = false;

        // Seed two organizations and two users.
        $this->orgA = \App\Models\Organization::forceCreate(['name' => 'Org A', 'slug' => 'org-a', 'domain' => null]);
        $this->orgB = \App\Models\Organization::forceCreate(['name' => 'Org B', 'slug' => 'org-b', 'domain' => null]);

        $this->userA = \App\Models\User::forceCreate([
            'name' => 'User A', 'email' => 'a@example.com', 'password' => bcrypt('password'),
        ]);
        $this->userB = \App\Models\User::forceCreate([
            'name' => 'User B', 'email' => 'b@example.com', 'password' => bcrypt('password'),
        ]);

        $role = \App\Models\Role::firstOrCreate(['id' => 1], ['name' => 'Role', 'slug' => 'role']);
        foreach ([[$this->userA, $this->orgA], [$this->userB, $this->orgB]] as [$u, $o]) {
            \App\Models\UserRole::forceCreate([
                'user_id' => $u->id, 'role_id' => $role->id,
                'organization_id' => $o->id, 'permissions' => ['*'],
            ]);
        }
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

    // ---- helpers ----------------------------------------------------------

    protected function clearPathCache(string $class): void
    {
        $ref = new \ReflectionClass($class);
        $prop = $ref->getProperty('organizationPathCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
    }

    protected function setRequestOrg($org): void
    {
        request()->attributes->set('organization', $org);
    }

    protected function clearRequestOrg(): void
    {
        request()->attributes->remove('organization');
    }

    /** Register CRUD routes for a single model slug so we can compare with the index. */
    protected function registerRoutesForModel(string $slug, string $modelClass): void
    {
        config([
            'rhino.models' => [$slug => $modelClass, 'organizations' => \App\Models\Organization::class],
            'rhino.route_groups' => [
                'tenant' => [
                    'prefix' => '{organization}',
                    'middleware' => [\Rhino\Http\Middleware\ResolveOrganizationFromRoute::class],
                    'models' => '*',
                ],
            ],
            'rhino.multi_tenant' => ['organization_identifier_column' => 'slug'],
        ]);

        Route::prefix('api')->group(function () {
            require __DIR__ . '/../../routes/api.php';
        });
    }

    // Seed the relationship chain for both orgs; returns [aComments, bComments].
    protected function seedRelationshipChain(): array
    {
        $blogA = ScopeBlog::forceCreate(['organization_id' => $this->orgA->id, 'name' => 'A Blog']);
        $blogB = ScopeBlog::forceCreate(['organization_id' => $this->orgB->id, 'name' => 'B Blog']);
        $postA = ScopePost::forceCreate(['blog_id' => $blogA->id, 'title' => 'A Post']);
        $postB = ScopePost::forceCreate(['blog_id' => $blogB->id, 'title' => 'B Post']);
        $cA1 = ScopeComment::forceCreate(['post_id' => $postA->id, 'body' => 'A1']);
        $cA2 = ScopeComment::forceCreate(['post_id' => $postA->id, 'body' => 'A2']);
        $cB1 = ScopeComment::forceCreate(['post_id' => $postB->id, 'body' => 'B1']);

        return [[$cA1, $cA2], [$cB1]];
    }

    // Seed column tasks for both orgs.
    protected function seedTasks(): void
    {
        ScopeTask::forceCreate(['organization_id' => $this->orgA->id, 'title' => 'A low', 'points' => 10]);
        ScopeTask::forceCreate(['organization_id' => $this->orgA->id, 'title' => 'A high', 'points' => 200]);
        ScopeTask::forceCreate(['organization_id' => $this->orgB->id, 'title' => 'B high', 'points' => 300]);
    }

    // ======================================================================
    // 1. Direct — column model
    // ======================================================================

    public function test_direct_column_model_matches_crud_index_for_org_a(): void
    {
        $this->registerRoutesForModel('scope-tasks', ScopeTask::class);
        $this->seedTasks();
        $this->actingAs($this->userA, 'sanctum');
        $this->setRequestOrg($this->orgA);

        $count = Rhino::query(ScopeTask::class)->count();
        $this->assertSame(2, $count);

        // Never includes org B.
        $titles = Rhino::query(ScopeTask::class)->pluck('title')->all();
        $this->assertNotContains('B high', $titles);

        // Equals the CRUD index count for org A.
        $indexCount = count($this->getJson('/api/org-a/scope-tasks')->assertStatus(200)->json('data'));
        $this->assertSame($indexCount, $count);
    }

    // ======================================================================
    // 2. Direct — relationship model (THE KEY LEAK CASE)
    // ======================================================================

    public function test_direct_relationship_model_returns_only_org_a_comments(): void
    {
        $this->seedRelationshipChain();
        $this->actingAs($this->userA, 'sanctum');
        $this->setRequestOrg($this->orgA);

        $bodies = Rhino::query(ScopeComment::class)->pluck('body')->sort()->values()->all();
        $this->assertSame(['A1', 'A2'], $bodies);
    }

    // ======================================================================
    // 3. Direct — aggregates respect the scope
    // ======================================================================

    public function test_direct_aggregates_respect_org_scope(): void
    {
        $this->seedTasks();
        $this->actingAs($this->userA, 'sanctum');
        $this->setRequestOrg($this->orgA);

        // Only org A points: 10 + 200 = 210.
        $this->assertSame(210, (int) Rhino::query(ScopeTask::class)->sum('points'));
        $this->assertSame(105.0, (float) Rhino::query(ScopeTask::class)->avg('points'));

        $grouped = Rhino::query(ScopeTask::class)
            ->selectRaw('organization_id, count(*) as c')
            ->groupBy('organization_id')
            ->pluck('c', 'organization_id')
            ->all();
        $this->assertSame([$this->orgA->id => 2], $grouped);
    }

    // ======================================================================
    // 4. Direct — global model returns all rows, does NOT throw
    // ======================================================================

    public function test_direct_global_model_returns_all_and_does_not_throw(): void
    {
        ScopeGlobalTag::forceCreate(['name' => 'one']);
        ScopeGlobalTag::forceCreate(['name' => 'two']);

        $this->actingAs($this->userA, 'sanctum');
        $this->setRequestOrg($this->orgA);

        $this->assertSame(2, Rhino::query(ScopeGlobalTag::class)->count());

        // Also does not throw with NO org context.
        $this->clearRequestOrg();
        $this->assertSame(2, Rhino::query(ScopeGlobalTag::class)->count());
    }

    // ======================================================================
    // 5. Direct — fail closed
    // ======================================================================

    public function test_direct_fail_closed_throws_when_no_org_context(): void
    {
        $this->seedTasks();
        $this->actingAs($this->userA, 'sanctum');
        $this->clearRequestOrg();

        $this->expectException(MissingTenantContext::class);
        Rhino::query(ScopeTask::class);
    }

    // ======================================================================
    // 6. Explicit — org isolation WITHOUT a route (the user's question)
    // ======================================================================

    public function test_explicit_org_isolation_without_a_route(): void
    {
        $this->seedTasks();
        $this->clearRequestOrg(); // simulate console/job: no request org

        $aCount = Rhino::forUser($this->userA)->inOrganization($this->orgA)->query(ScopeTask::class)->count();
        $this->assertSame(2, $aCount);

        $bCount = Rhino::forUser($this->userB)->inOrganization($this->orgB)->query(ScopeTask::class)->count();
        $this->assertSame(1, $bCount);
    }

    /**
     * The explicit context must not leak into a later ambient query in the same
     * process (fail-closed must still hold for long-lived workers / Octane).
     */
    public function test_explicit_query_does_not_leak_context_into_a_later_ambient_query(): void
    {
        $this->seedTasks();
        $this->clearRequestOrg(); // no ambient org (console/job)

        // An explicit-context query must not leave its organization active…
        $aCount = Rhino::forUser($this->userA)->inOrganization($this->orgA)->query(ScopeTask::class)->count();
        $this->assertSame(2, $aCount);

        // …so a later AMBIENT query with no context fails closed instead of
        // silently reusing org A.
        $this->expectException(MissingTenantContext::class);
        Rhino::query(ScopeTask::class);
    }

    // ======================================================================
    // 7. Explicit — relationship model without a route
    // ======================================================================

    public function test_explicit_relationship_model_without_a_route(): void
    {
        $this->seedRelationshipChain();
        $this->clearRequestOrg();

        $aBodies = Rhino::forUser($this->userA)->inOrganization($this->orgA)
            ->query(ScopeComment::class)->pluck('body')->sort()->values()->all();
        $this->assertSame(['A1', 'A2'], $aBodies);

        $bBodies = Rhino::forUser($this->userB)->inOrganization($this->orgB)
            ->query(ScopeComment::class)->pluck('body')->sort()->values()->all();
        $this->assertSame(['B1'], $bBodies);
    }

    // ======================================================================
    // 8. Explicit — user-aware auto-scope reaches the app's global scope
    // ======================================================================

    public function test_explicit_user_aware_auto_scope(): void
    {
        // Enable the owner-filtering {Model}Scope.
        ScopeOwnedTaskScope::$enabled = true;

        ScopeOwnedTask::forceCreate(['organization_id' => $this->orgA->id, 'owner_id' => $this->userA->id, 'title' => 'A owns 1']);
        ScopeOwnedTask::forceCreate(['organization_id' => $this->orgA->id, 'owner_id' => $this->userA->id, 'title' => 'A owns 2']);
        ScopeOwnedTask::forceCreate(['organization_id' => $this->orgA->id, 'owner_id' => $this->userB->id, 'title' => 'B owns 1']);

        $this->clearRequestOrg();

        // userA (in orgA) sees only their own rows.
        $aTitles = Rhino::forUser($this->userA)->inOrganization($this->orgA)
            ->query(ScopeOwnedTask::class)->pluck('title')->sort()->values()->all();
        $this->assertSame(['A owns 1', 'A owns 2'], $aTitles);

        // userB owns a row in orgA too; query userB in orgA -> only B's row.
        $bTitles = Rhino::forUser($this->userB)->inOrganization($this->orgA)
            ->query(ScopeOwnedTask::class)->pluck('title')->all();
        $this->assertSame(['B owns 1'], $bTitles);
    }

    // ======================================================================
    // 9. Explicit — run() isolation restores ambient
    // ======================================================================

    public function test_explicit_run_restores_ambient_context(): void
    {
        $this->actingAs($this->userA, 'sanctum');
        $this->setRequestOrg($this->orgA);

        $before = [auth('sanctum')->user()->id, request()->attributes->get('organization')->id];

        Rhino::forUser($this->userB)->inOrganization($this->orgB)->run(function () {
            // Inside: ambient reflects the override.
            $this->assertSame($this->userB->id, auth('sanctum')->user()->id);
            $this->assertSame($this->orgB->id, request()->attributes->get('organization')->id);
        });

        $after = [auth('sanctum')->user()->id, request()->attributes->get('organization')->id];
        $this->assertSame($before, $after);
    }

    public function test_run_restores_absent_org_attribute(): void
    {
        $this->actingAs($this->userA, 'sanctum');
        $this->clearRequestOrg();
        $this->assertFalse(request()->attributes->has('organization'));

        Rhino::forUser($this->userB)->inOrganization($this->orgB)->run(fn () => true);

        // Was absent before -> must be absent after (not left set).
        $this->assertFalse(request()->attributes->has('organization'));
    }

    // ======================================================================
    // 10. Explicit — run() returns the callback value
    // ======================================================================

    public function test_explicit_run_returns_callback_value(): void
    {
        $this->seedTasks();
        $this->clearRequestOrg();

        $result = Rhino::forUser($this->userA)->inOrganization($this->orgA)->run(function () {
            return Rhino::query(ScopeTask::class)->count();
        });

        $this->assertSame(2, $result);
    }

    // ======================================================================
    // 11. Console/job leak contrast
    // ======================================================================

    public function test_console_job_leak_contrast(): void
    {
        $this->seedTasks();
        $this->clearRequestOrg(); // console/job-like: no request org

        // Footgun: a raw query returns ALL orgs (the org global scope is a
        // no-op in console context).
        $this->assertSame(3, ScopeTask::query()->count());

        // Rhino explicit scoping returns only org A.
        $scoped = Rhino::forUser($this->userA)->inOrganization($this->orgA)->query(ScopeTask::class)->count();
        $this->assertSame(2, $scoped);
    }

    // ======================================================================
    // 12. scopedQuery + named scope applies org scope AND the named scope
    // ======================================================================

    public function test_scoped_query_applies_named_scope_and_org_scope(): void
    {
        $this->seedTasks(); // org A: 10, 200; org B: 300
        $this->actingAs($this->userA, 'sanctum');
        $this->setRequestOrg($this->orgA);

        // highValue = points >= 100. Org A has ONE such row (200). Org B's 300
        // must be excluded by the org scope.
        $count = Rhino::scopedQuery(ScopeTask::class, 'highValue')->count();
        $this->assertSame(1, $count);

        $titles = Rhino::scopedQuery(ScopeTask::class, 'highValue')->pluck('title')->all();
        $this->assertSame(['A high'], $titles);
    }

    // ======================================================================
    // 13. Controller trait ifCanView
    // ======================================================================

    public function test_controller_trait_if_can_view(): void
    {
        $this->seedTasks();
        $this->actingAs($this->userA, 'sanctum');
        $this->setRequestOrg($this->orgA);

        $controller = new ScopeMetricsController();

        // Allowed: returns the scoped metric (org A count = 2).
        ScopeResourceTestPolicy::$allowViewAny = true;
        $this->assertSame(2, $controller->taskCount());

        // Denied: returns null.
        ScopeResourceTestPolicy::$allowViewAny = false;
        $this->assertNull($controller->taskCount());
        ScopeResourceTestPolicy::$allowViewAny = true;
    }

    // ======================================================================
    // 14. GlobalController parity (regression sentinel)
    // ======================================================================

    public function test_parity_with_crud_index_for_column_and_relationship_models(): void
    {
        // Column model parity.
        $this->registerRoutesForModel('scope-tasks', ScopeTask::class);
        $this->seedTasks();
        $this->actingAs($this->userA, 'sanctum');
        $this->setRequestOrg($this->orgA);

        $rhinoCount = Rhino::query(ScopeTask::class)->count();
        $indexCount = count($this->getJson('/api/org-a/scope-tasks')->assertStatus(200)->json('data'));
        $this->assertSame($indexCount, $rhinoCount);

        // Relationship model parity (fresh routes for the comment slug).
        $this->registerRoutesForModel('scope-comments', ScopeComment::class);
        $this->seedRelationshipChain();
        $this->setRequestOrg($this->orgA);

        $rhinoComments = Rhino::query(ScopeComment::class)->count();
        $indexComments = count($this->getJson('/api/org-a/scope-comments')->assertStatus(200)->json('data'));
        $this->assertSame($indexComments, $rhinoComments);
    }

    // ======================================================================
    // 15. Double-scope safety
    // ======================================================================

    public function test_double_scope_safety_no_duplicate_or_dropped_rows(): void
    {
        $this->seedTasks();
        $this->actingAs($this->userA, 'sanctum');
        $this->setRequestOrg($this->orgA);

        // In a request (org present), Rhino strips the 'organization' global
        // scope and applies its own once. Result must equal a single manual
        // org filter — no duplicated/conflicting filter, no dropped rows.
        $rhino = Rhino::query(ScopeTask::class)->orderBy('id')->pluck('id')->all();

        $manual = ScopeTask::withoutGlobalScope('organization')
            ->where('organization_id', $this->orgA->id)
            ->orderBy('id')->pluck('id')->all();

        $this->assertSame($manual, $rhino);
        $this->assertCount(2, $rhino);
    }
}

// ==========================================================================
// Support classes
// ==========================================================================

class ScopeResourceTestPolicy
{
    public static bool $allowViewAny = true;

    public function viewAny(?Authenticatable $user): bool { return static::$allowViewAny; }
    public function view(?Authenticatable $user, $model): bool { return true; }
    public function create(?Authenticatable $user): bool { return true; }
    public function update(?Authenticatable $user, $model): bool { return true; }
    public function delete(?Authenticatable $user, $model): bool { return true; }
}

class ScopeMetricsController
{
    use InteractsWithRhinoResources;

    public function taskCount(): ?int
    {
        return $this->ifCanView(ScopeTask::class, fn ($query) => $query->count());
    }
}
