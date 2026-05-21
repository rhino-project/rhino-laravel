<?php

namespace Rhino\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
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

class SearchPost extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'search_posts';

    protected $fillable = ['blog_id', 'title', 'content'];

    protected $validationRules = [
        'title' => 'string|max:255',
        'content' => 'string',
    ];

    protected $validationRulesStore = ['title', 'content'];
    protected $validationRulesUpdate = ['title', 'content'];

    public static $allowedFilters = ['title'];
    public static $allowedSorts = ['title', 'created_at'];
    public static $allowedSearch = ['title', 'content'];

    public function blog()
    {
        return $this->belongsTo(SearchBlog::class, 'blog_id');
    }
}

class SearchPostWithRelationshipSearch extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'search_posts';

    protected $fillable = ['blog_id', 'title', 'content'];

    protected $validationRules = ['title' => 'string', 'content' => 'string'];
    protected $validationRulesStore = ['title', 'content'];
    protected $validationRulesUpdate = ['title', 'content'];

    public static $allowedSearch = ['title', 'content', 'blog.title'];

    public function blog()
    {
        return $this->belongsTo(SearchBlog::class, 'blog_id');
    }
}

class SearchPostNoSearch extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'search_posts';

    protected $fillable = ['title', 'content'];

    protected $validationRules = ['title' => 'string', 'content' => 'string'];
    protected $validationRulesStore = ['title', 'content'];
    protected $validationRulesUpdate = ['title', 'content'];

    public static $allowedFilters = ['title'];
    public static $allowedSorts = ['title'];
    // no $allowedSearch - search param should be ignored
}

class SearchBlog extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'search_blogs';

    protected $fillable = ['title'];

    protected $validationRules = ['title' => 'string|max:255'];
    protected $validationRulesStore = ['title'];
    protected $validationRulesUpdate = ['title'];

    public static $allowedSearch = ['title'];

    public function posts()
    {
        return $this->hasMany(SearchPost::class, 'blog_id');
    }
}

class SearchPostPolicy
{
    public function viewAny(?Authenticatable $user): bool { return true; }
    public function view(?Authenticatable $user, $model): bool { return true; }
    public function create(?Authenticatable $user): bool { return true; }
    public function update(?Authenticatable $user, $model): bool { return true; }
    public function delete(?Authenticatable $user, $model): bool { return true; }
}

class SearchBlogPolicy
{
    public function viewAny(?Authenticatable $user): bool { return true; }
    public function view(?Authenticatable $user, $model): bool { return true; }
    public function create(?Authenticatable $user): bool { return true; }
    public function update(?Authenticatable $user, $model): bool { return true; }
    public function delete(?Authenticatable $user, $model): bool { return true; }
}

// --------------------------------------------------------------------------
// Tests
// --------------------------------------------------------------------------

class SearchTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('search_blogs', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('search_posts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('blog_id')->nullable();
            $table->string('title');
            $table->text('content')->nullable();
            $table->timestamps();
        });

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        Gate::policy(SearchPost::class, SearchPostPolicy::class);
        Gate::policy(SearchPostWithRelationshipSearch::class, SearchPostPolicy::class);
        Gate::policy(SearchPostNoSearch::class, SearchPostPolicy::class);
        Gate::policy(SearchBlog::class, SearchBlogPolicy::class);
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

    protected function registerRoutes(array $models): void
    {
        config([
            'rhino.models' => $models,
            'rhino.route_groups' => [
                'default' => ['prefix' => '', 'middleware' => [], 'models' => '*'],
            ],
            'rhino.multi_tenant' => [
                'organization_identifier_column' => 'id',
            ],
        ]);

        Route::prefix('api')->group(function () {
            require __DIR__ . '/../../routes/api.php';
        });
    }

    protected function authenticate(): \App\Models\User
    {
        $user = \App\Models\User::firstOrCreate(
            ['id' => 1],
            ['name' => 'Test User', 'email' => 'test@example.com', 'password' => bcrypt('password')]
        );
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    public function test_search_returns_matching_rows(): void
    {
        $this->registerRoutes(['posts' => SearchPost::class]);
        $this->authenticate();

        SearchPost::forceCreate(['title' => 'Needle in title', 'content' => 'Some content']);
        SearchPost::forceCreate(['title' => 'Other', 'content' => 'Needle in content']);
        SearchPost::forceCreate(['title' => 'No match', 'content' => 'Nothing']);

        $response = $this->getJson('/api/posts?search=needle');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertIsArray($data);
        $this->assertCount(2, $data);
        $titles = array_column($data, 'title');
        $this->assertContains('Needle in title', $titles);
        $this->assertContains('Other', $titles);
        $this->assertNotContains('No match', $titles);
    }

    public function test_search_excludes_non_matching(): void
    {
        $this->registerRoutes(['posts' => SearchPost::class]);
        $this->authenticate();

        SearchPost::forceCreate(['title' => 'Foo', 'content' => 'Bar']);

        $response = $this->getJson('/api/posts?search=nonexistent');

        $response->assertStatus(200);
        $this->assertSame([], $response->json('data'));
    }

    public function test_search_composes_with_filters(): void
    {
        $this->registerRoutes(['posts' => SearchPost::class]);
        $this->authenticate();

        SearchPost::forceCreate(['title' => 'Needle', 'content' => 'A']);
        SearchPost::forceCreate(['title' => 'Needle', 'content' => 'B']);
        SearchPost::forceCreate(['title' => 'Other', 'content' => 'C']);

        $response = $this->getJson('/api/posts?search=needle&filter[title]=Needle');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(2, $data);
        foreach ($data as $item) {
            $this->assertStringContainsString('needle', strtolower($item['title']));
        }
    }

    public function test_no_allowed_search_silently_ignores(): void
    {
        $this->registerRoutes(['posts' => SearchPostNoSearch::class]);
        $this->authenticate();

        SearchPostNoSearch::forceCreate(['title' => 'Foo', 'content' => 'Bar']);
        SearchPostNoSearch::forceCreate(['title' => 'Baz', 'content' => 'Quux']);

        $response = $this->getJson('/api/posts?search=foo');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(2, $data);
    }

    public function test_search_relationship_dot_notation(): void
    {
        $this->registerRoutes(['posts' => SearchPostWithRelationshipSearch::class, 'blogs' => SearchBlog::class]);
        $this->authenticate();

        $blog = SearchBlog::forceCreate(['title' => 'BlogWithNeedle']);
        SearchPostWithRelationshipSearch::forceCreate([
            'blog_id' => $blog->id,
            'title' => 'Post title',
            'content' => 'Content',
        ]);
        SearchBlog::forceCreate(['title' => 'Other blog']);
        SearchPostWithRelationshipSearch::forceCreate([
            'blog_id' => null,
            'title' => 'Standalone',
            'content' => 'No blog',
        ]);

        $response = $this->getJson('/api/posts?search=blogwithneedle');

        $response->assertStatus(200);
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertSame('Post title', $data[0]['title']);
    }

    public function test_search_empty_or_missing_returns_all(): void
    {
        $this->registerRoutes(['posts' => SearchPost::class]);
        $this->authenticate();

        SearchPost::forceCreate(['title' => 'A', 'content' => 'X']);
        SearchPost::forceCreate(['title' => 'B', 'content' => 'Y']);

        $r1 = $this->getJson('/api/posts');
        $r1->assertStatus(200);
        $this->assertCount(2, $r1->json('data'));

        $r2 = $this->getJson('/api/posts?search=');
        $r2->assertStatus(200);
        $this->assertCount(2, $r2->json('data'));
    }

    public function test_search_with_pagination_headers(): void
    {
        $this->registerRoutes(['posts' => SearchPost::class]);
        $this->authenticate();

        SearchPost::forceCreate(['title' => 'Needle one', 'content' => 'A']);
        SearchPost::forceCreate(['title' => 'Needle two', 'content' => 'B']);
        SearchPost::forceCreate(['title' => 'Other', 'content' => 'C']);

        $response = $this->getJson('/api/posts?search=needle&per_page=1');

        $response->assertStatus(200);
        $response->assertHeader('X-Total', '2');
        $response->assertHeader('X-Per-Page', '1');
        $data = $response->json();
        $this->assertCount(1, $data);
    }
}
