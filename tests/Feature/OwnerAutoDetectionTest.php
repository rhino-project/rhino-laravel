<?php

namespace Rhino\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Rhino\Controllers\GlobalController;
use Rhino\Models\RhinoModel;
use Rhino\Traits\BelongsToOrganization;
use Rhino\Tests\TestCase;

// -- Test models (inline) --------------------------------------------------

class AutoDetectBlog extends RhinoModel
{
    use BelongsToOrganization;

    protected $table = 'auto_blogs';

    protected $fillable = ['organization_id', 'name'];
}

class AutoDetectPost extends RhinoModel
{
    protected $table = 'auto_posts';

    protected $fillable = ['blog_id', 'title'];

    // No $owner declared — should be auto-detected via blog() -> organization_id
    public function blog(): BelongsTo
    {
        return $this->belongsTo(AutoDetectBlog::class, 'blog_id');
    }
}

class AutoDetectComment extends RhinoModel
{
    protected $table = 'auto_comments';

    protected $fillable = ['post_id', 'body'];

    // No $owner declared — should be auto-detected: comment -> post -> blog -> org
    public function post(): BelongsTo
    {
        return $this->belongsTo(AutoDetectPost::class, 'post_id');
    }
}

class AutoDetectExplicitOwner extends RhinoModel
{
    protected $table = 'auto_posts';

    protected $fillable = ['blog_id', 'title'];

    public static string $owner = 'blog';

    public function blog(): BelongsTo
    {
        return $this->belongsTo(AutoDetectBlog::class, 'blog_id');
    }
}

class AutoDetectOptOut extends RhinoModel
{
    protected $table = 'auto_posts';

    protected $fillable = ['blog_id', 'title'];

    public static string $owner = 'none';

    public function blog(): BelongsTo
    {
        return $this->belongsTo(AutoDetectBlog::class, 'blog_id');
    }
}

class AutoDetectGlobal extends RhinoModel
{
    protected $table = 'auto_globals';

    protected $fillable = ['name'];

    // No BelongsTo leading to organization — truly global
}

// -- Test class -------------------------------------------------------------

class OwnerAutoDetectionTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        // Create test tables
        Schema::create('auto_blogs', function ($table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('auto_posts', function ($table) {
            $table->id();
            $table->foreignId('blog_id');
            $table->string('title');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('auto_comments', function ($table) {
            $table->id();
            $table->foreignId('post_id');
            $table->string('body');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('auto_globals', function ($table) {
            $table->id();
            $table->string('name');
            $table->timestamps();
            $table->softDeletes();
        });

        Gate::policy(AutoDetectBlog::class, OwnerAutoDetectTestPolicy::class);
        Gate::policy(AutoDetectPost::class, OwnerAutoDetectTestPolicy::class);
        Gate::policy(AutoDetectComment::class, OwnerAutoDetectTestPolicy::class);
        Gate::policy(AutoDetectExplicitOwner::class, OwnerAutoDetectTestPolicy::class);
        Gate::policy(AutoDetectOptOut::class, OwnerAutoDetectTestPolicy::class);
        Gate::policy(AutoDetectGlobal::class, OwnerAutoDetectTestPolicy::class);
        Gate::policy(\App\Models\Organization::class, OwnerAutoDetectTestPolicy::class);

        // Clear the static cache between tests
        $ref = new \ReflectionClass(GlobalController::class);
        $prop = $ref->getProperty('organizationPathCache');
        $prop->setAccessible(true);
        $prop->setValue(null, []);
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
            'rhino.multi_tenant' => [
                'organization_identifier_column' => 'slug',
            ],
        ]);

        Route::prefix('api')->group(function () {
            require __DIR__ . '/../../routes/api.php';
        });
    }

    protected function createUserWithOrganization(string $orgSlug): \App\Models\User
    {
        $user = \App\Models\User::forceCreate([
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
            'permissions' => ['*'],
        ]);

        $this->actingAs($user, 'sanctum');

        return $user;
    }

    // -- Tests ---------------------------------------------------------------

    public function test_auto_detects_one_hop_belongs_to_organization(): void
    {
        $this->registerRoutesForModel('auto-detect-posts', AutoDetectPost::class);
        $this->createUserWithOrganization('acme');

        $org = \App\Models\Organization::where('slug', 'acme')->first();
        $otherOrg = \App\Models\Organization::forceCreate(['name' => 'Other', 'slug' => 'other', 'domain' => null]);

        $blog1 = AutoDetectBlog::forceCreate(['organization_id' => $org->id, 'name' => 'Acme Blog']);
        $blog2 = AutoDetectBlog::forceCreate(['organization_id' => $otherOrg->id, 'name' => 'Other Blog']);

        $post1 = AutoDetectPost::forceCreate(['blog_id' => $blog1->id, 'title' => 'Acme Post']);
        $post2 = AutoDetectPost::forceCreate(['blog_id' => $blog2->id, 'title' => 'Other Post']);

        $response = $this->getJson('/api/acme/auto-detect-posts');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Acme Post', $data[0]['title']);
    }

    public function test_auto_detects_two_hop_chain(): void
    {
        $this->registerRoutesForModel('auto-detect-comments', AutoDetectComment::class);
        $this->createUserWithOrganization('acme');

        $org = \App\Models\Organization::where('slug', 'acme')->first();
        $otherOrg = \App\Models\Organization::forceCreate(['name' => 'Other', 'slug' => 'other', 'domain' => null]);

        $blog1 = AutoDetectBlog::forceCreate(['organization_id' => $org->id, 'name' => 'Acme Blog']);
        $blog2 = AutoDetectBlog::forceCreate(['organization_id' => $otherOrg->id, 'name' => 'Other Blog']);

        $post1 = AutoDetectPost::forceCreate(['blog_id' => $blog1->id, 'title' => 'Acme Post']);
        $post2 = AutoDetectPost::forceCreate(['blog_id' => $blog2->id, 'title' => 'Other Post']);

        $comment1 = AutoDetectComment::forceCreate(['post_id' => $post1->id, 'body' => 'Acme Comment']);
        $comment2 = AutoDetectComment::forceCreate(['post_id' => $post2->id, 'body' => 'Other Comment']);

        $response = $this->getJson('/api/acme/auto-detect-comments');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Acme Comment', $data[0]['body']);
    }

    public function test_explicit_owner_still_takes_priority(): void
    {
        $this->registerRoutesForModel('auto-detect-explicit-owners', AutoDetectExplicitOwner::class);
        $this->createUserWithOrganization('acme');

        $org = \App\Models\Organization::where('slug', 'acme')->first();
        $blog = AutoDetectBlog::forceCreate(['organization_id' => $org->id, 'name' => 'Acme Blog']);
        $post = AutoDetectExplicitOwner::forceCreate(['blog_id' => $blog->id, 'title' => 'Explicit Post']);

        $response = $this->getJson('/api/acme/auto-detect-explicit-owners');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Explicit Post', $data[0]['title']);
    }

    public function test_opt_out_with_owner_none_returns_all_records(): void
    {
        $this->registerRoutesForModel('auto-detect-opt-outs', AutoDetectOptOut::class);
        $this->createUserWithOrganization('acme');

        $org = \App\Models\Organization::where('slug', 'acme')->first();
        $otherOrg = \App\Models\Organization::forceCreate(['name' => 'Other', 'slug' => 'other', 'domain' => null]);

        $blog1 = AutoDetectBlog::forceCreate(['organization_id' => $org->id, 'name' => 'Acme Blog']);
        $blog2 = AutoDetectBlog::forceCreate(['organization_id' => $otherOrg->id, 'name' => 'Other Blog']);

        AutoDetectOptOut::forceCreate(['blog_id' => $blog1->id, 'title' => 'Post 1']);
        AutoDetectOptOut::forceCreate(['blog_id' => $blog2->id, 'title' => 'Post 2']);

        $response = $this->getJson('/api/acme/auto-detect-opt-outs');

        $response->assertStatus(200);
        $data = $response->json('data');
        // With $owner = 'none', no org scoping — returns ALL records
        $this->assertCount(2, $data);
    }

    public function test_global_model_without_org_path_returns_all_records(): void
    {
        $this->registerRoutesForModel('auto-detect-globals', AutoDetectGlobal::class);
        $this->createUserWithOrganization('acme');

        AutoDetectGlobal::forceCreate(['name' => 'Global 1']);
        AutoDetectGlobal::forceCreate(['name' => 'Global 2']);

        $response = $this->getJson('/api/acme/auto-detect-globals');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(2, $data);
    }
}

class OwnerAutoDetectTestPolicy
{
    public function viewAny(?Authenticatable $user): bool { return true; }
    public function view(?Authenticatable $user, $model): bool { return true; }
    public function create(?Authenticatable $user): bool { return true; }
    public function update(?Authenticatable $user, $model): bool { return true; }
    public function delete(?Authenticatable $user, $model): bool { return true; }
}
