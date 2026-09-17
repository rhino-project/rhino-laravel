<?php

namespace Rhino\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Rhino\Http\Requests\ResourceRequest;
use Rhino\Policies\ResourcePolicy;
use Rhino\Support\TenantExistsRules;
use Rhino\Tests\TestCase;
use Rhino\Traits\BelongsToOrganization;
use Rhino\Traits\HasValidation;
use Rhino\Traits\HidableColumns;

// --------------------------------------------------------------------------
// Models: RcBlog is owned directly (organization_id); RcArticle reaches its
// organization only through RcBlog — the indirect case that has leaked before.
// --------------------------------------------------------------------------

class RcBlog extends Model
{
    use HasValidation, HidableColumns, BelongsToOrganization;

    protected $table = 'rc_blogs';
    protected $fillable = ['organization_id', 'name'];
}

class RcArticle extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'rc_articles';
    protected $fillable = ['blog_id', 'title'];
}

// --------------------------------------------------------------------------
// Request classes
// --------------------------------------------------------------------------

class RcArticleStoreRequest extends ResourceRequest
{
    public static $seenOrganization = false;

    public function rules(): array
    {
        static::$seenOrganization = $this->organization();

        return [
            // Plain rule: Rhino rewrites it to walk rc_articles → rc_blogs →
            // organizations. Nothing here names organization_id.
            'blog_id' => 'required|integer|exists:rc_blogs,id',
            'title' => 'required|string|max:255',
            // In tenant context Rhino drops this rule entirely, because
            // organization_id is framework-managed.
            'organization_id' => 'required|integer',
        ];
    }
}

// --------------------------------------------------------------------------
// Policy
// --------------------------------------------------------------------------

class RcTenantPolicy extends ResourcePolicy
{
    public function viewAny(?Authenticatable $user): bool { return true; }
    public function view(?Authenticatable $user, $model): bool { return true; }
    public function create(?Authenticatable $user): bool { return true; }
    public function update(?Authenticatable $user, $model): bool { return true; }
    public function delete(?Authenticatable $user, $model): bool { return true; }
}

class RequestClassTenantTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        Schema::create('rc_blogs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->cascadeOnDelete();
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('rc_articles', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id')->constrained('rc_blogs')->cascadeOnDelete();
            $table->string('title');
            $table->timestamps();
        });

        TenantExistsRules::flushCaches();
        RcArticleStoreRequest::$seenOrganization = false;
    }

    protected function tearDown(): void
    {
        TenantExistsRules::flushCaches();

        parent::tearDown();
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

    protected function registerTenantRoutes(array $models): void
    {
        config([
            'rhino.models' => $models,
            'rhino.route_groups' => [
                'tenant' => [
                    'prefix' => '{organization}',
                    'middleware' => [\Rhino\Http\Middleware\ResolveOrganizationFromRoute::class],
                    'models' => '*',
                ],
            ],
            'rhino.multi_tenant' => ['organization_identifier_column' => 'slug'],
            'rhino.nested' => ['path' => 'nested', 'max_operations' => 50, 'allowed_models' => null],
            'rhino.requests.namespace' => __NAMESPACE__,
        ]);

        Route::prefix('api')->group(function () {
            require __DIR__ . '/../../routes/api.php';
        });
    }

    protected function createUserInOrg(string $orgSlug): array
    {
        $user = \App\Models\User::forceCreate([
            'name' => 'Test User',
            'email' => "rc-{$orgSlug}@example.com",
            'password' => bcrypt('password'),
        ]);

        $org = \App\Models\Organization::firstOrCreate(
            ['slug' => $orgSlug],
            ['name' => ucfirst($orgSlug), 'domain' => null]
        );

        $role = \App\Models\Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);

        \App\Models\UserRole::forceCreate([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'permissions' => ['*'],
        ]);

        $this->actingAs($user, 'sanctum');

        return [$user, $org];
    }

    public function test_exists_rules_in_a_request_class_are_scoped_through_the_indirect_fk_chain(): void
    {
        Gate::policy(RcArticle::class, RcTenantPolicy::class);
        $this->registerTenantRoutes(['rcarticles' => RcArticle::class]);

        [$user, $orgA] = $this->createUserInOrg('rc-org-a');
        $orgB = \App\Models\Organization::forceCreate(['name' => 'Org B', 'slug' => 'rc-org-b', 'domain' => null]);

        $blogA = RcBlog::forceCreate(['organization_id' => $orgA->id, 'name' => 'A']);
        $blogB = RcBlog::forceCreate(['organization_id' => $orgB->id, 'name' => 'B']);

        // Another organization's blog: rejected, even though rc_articles has no
        // organization_id of its own.
        $this->postJson("/api/rc-org-a/rcarticles", [
            'blog_id' => $blogB->id,
            'title' => 'Cross tenant',
        ])->assertStatus(422)->assertJsonStructure(['errors' => ['blog_id']]);

        // Our own blog: accepted.
        $this->postJson("/api/rc-org-a/rcarticles", [
            'blog_id' => $blogA->id,
            'title' => 'Fine',
        ])->assertStatus(201);

        // The organization reached the request class, and the organization_id
        // rule was dropped (otherwise 'required' would have failed both calls).
        $this->assertNotNull(RcArticleStoreRequest::$seenOrganization);
        $this->assertSame($orgA->id, RcArticleStoreRequest::$seenOrganization->id);
        $this->assertSame(1, RcArticle::count());
    }
}
