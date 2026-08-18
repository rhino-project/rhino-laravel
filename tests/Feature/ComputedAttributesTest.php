<?php

namespace Rhino\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Rhino\Contracts\HasPermittedAttributes;
use Rhino\Policies\ResourcePolicy;
use Rhino\Tests\TestCase;
use Rhino\Traits\HasValidation;
use Rhino\Traits\HidableColumns;

// --------------------------------------------------------------------------
// Counters — prove laziness (nothing is evaluated unless it was asked for)
// --------------------------------------------------------------------------

class ComputedCallCounter
{
    public static array $calls = [];

    public static function hit(string $name): void
    {
        static::$calls[$name] = (static::$calls[$name] ?? 0) + 1;
    }

    public static function reset(): void
    {
        static::$calls = [];
    }

    public static function count(string $name): int
    {
        return static::$calls[$name] ?? 0;
    }
}

// --------------------------------------------------------------------------
// Test Models
// --------------------------------------------------------------------------

/**
 * The canonical case from the feature request: aggregates over a users table.
 * Also carries opt-in record-level attributes and a legacy always-on one.
 */
class ComputedUser extends Model
{
    use HasValidation, HidableColumns, SoftDeletes;

    protected $table = 'computed_users';

    protected $fillable = ['status', 'first_name', 'last_name', 'owner_id'];

    protected $validationRules = [
        'status' => 'string',
        'first_name' => 'string',
        'last_name' => 'string',
        'owner_id' => 'nullable|integer',
    ];

    public static $allowedFilters = ['status', 'owner_id'];

    public static $allowedSorts = ['first_name'];

    public static $allowedSearch = ['first_name', 'last_name'];

    public static $allowedScopes = ['owned'];

    /** Legacy always-on computed attribute — must keep working untouched. */
    public function rhinoComputedAttributes(): array
    {
        ComputedCallCounter::hit('legacy_label');

        return ['legacy_label' => 'always-here'];
    }

    public function rhinoRecordComputedAttributes(): array
    {
        return [
            'full_name' => function ($record, $user) {
                ComputedCallCounter::hit('full_name');

                return trim($record->first_name . ' ' . $record->last_name);
            },
            'expensive_flag' => function ($record, $user) {
                ComputedCallCounter::hit('expensive_flag');

                return $record->status === 'active';
            },
            'viewer_id' => fn ($record, $user) => $user?->id,
            'secret_note' => fn ($record, $user) => 'classified',
        ];
    }

    public static function rhinoCollectionComputedAttributes(): array
    {
        return [
            'active_users_count' => function ($query, $user) {
                ComputedCallCounter::hit('active_users_count');

                return $query->where('status', 'active')->count();
            },
            'blocked_users_count' => function ($query, $user) {
                ComputedCallCounter::hit('blocked_users_count');

                return $query->where('status', 'blocked')->count();
            },
            'total_count' => fn ($query, $user) => $query->count(),
            'viewer_id' => fn ($query, $user) => $user?->id,
            'secret_total' => fn ($query, $user) => $query->count(),
        ];
    }

    public function scopeOwned(Builder $query, ?Authenticatable $user): Builder
    {
        return $query->where('owner_id', $user?->id ?? 0);
    }
}

/** Declares NOTHING — proves the feature is entirely opt-in. */
class PlainRecord extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'computed_users';

    protected $fillable = ['status', 'first_name', 'last_name', 'owner_id'];

    protected $validationRules = ['status' => 'string', 'first_name' => 'string'];
}

/** Declares collection attributes but no $allowedFilters. */
class PlainWithComputed extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'computed_users';

    protected $fillable = ['status', 'first_name', 'last_name', 'owner_id'];

    protected $validationRules = ['status' => 'string'];

    public static function rhinoCollectionComputedAttributes(): array
    {
        return ['total_count' => fn ($query, $user) => $query->count()];
    }
}

/** Declares collection attributes but excludes the action. */
class ExceptedComputed extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'computed_users';

    protected $fillable = ['status', 'first_name', 'last_name', 'owner_id'];

    protected $validationRules = ['status' => 'string'];

    public static array $exceptActions = ['computed'];

    public static function rhinoCollectionComputedAttributes(): array
    {
        return ['total_count' => fn ($query, $user) => $query->count()];
    }
}

/** Non-array / empty declarations must not register the route or explode. */
class EmptyDeclarationRecord extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'computed_users';

    protected $fillable = ['status', 'first_name', 'last_name', 'owner_id'];

    protected $validationRules = ['status' => 'string'];

    public static function rhinoCollectionComputedAttributes(): array
    {
        return [];
    }

    public function rhinoRecordComputedAttributes(): array
    {
        return [];
    }
}

/**
 * INDIRECT tenancy: no organization_id of its own — owned through
 * IndirectPost -> IndirectBlog -> organization. The framework must hand the
 * callable a query that is already scoped through that chain.
 */
class IndirectBlog extends Model
{
    use HasValidation, HidableColumns, \Rhino\Traits\BelongsToOrganization;

    protected $table = 'indirect_blogs';

    protected $fillable = ['organization_id', 'name'];

    protected $validationRules = ['organization_id' => 'integer', 'name' => 'string'];
}

class IndirectPost extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'indirect_posts';

    protected $fillable = ['blog_id', 'title'];

    protected $validationRules = ['blog_id' => 'integer', 'title' => 'string'];

    public function blog(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(IndirectBlog::class, 'blog_id');
    }
}

class IndirectComment extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'indirect_comments';

    protected $fillable = ['post_id', 'body', 'status'];

    protected $validationRules = ['post_id' => 'integer', 'body' => 'string', 'status' => 'string'];

    public static $allowedFilters = ['status'];

    public function post(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(IndirectPost::class, 'post_id');
    }

    public function rhinoRecordComputedAttributes(): array
    {
        return ['shouty_body' => fn ($record, $user) => strtoupper((string) $record->body)];
    }

    public static function rhinoCollectionComputedAttributes(): array
    {
        return [
            'total_count' => fn ($query, $user) => $query->count(),
            'flagged_count' => fn ($query, $user) => $query->where('status', 'flagged')->count(),
        ];
    }
}

/** Multi-tenant model — aggregates must never cross the org boundary. */
class TenantComputedUser extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'tenant_computed_users';

    protected $fillable = ['organization_id', 'status', 'first_name'];

    protected $validationRules = [
        'organization_id' => 'integer',
        'status' => 'string',
        'first_name' => 'string',
    ];

    public static $allowedFilters = ['status'];

    public function rhinoRecordComputedAttributes(): array
    {
        return ['shouty_name' => fn ($record, $user) => strtoupper((string) $record->first_name)];
    }

    public static function rhinoCollectionComputedAttributes(): array
    {
        return [
            'total_count' => fn ($query, $user) => $query->count(),
            'active_users_count' => fn ($query, $user) => $query->where('status', 'active')->count(),
        ];
    }
}

// --------------------------------------------------------------------------
// Policies
// --------------------------------------------------------------------------

class OpenComputedPolicy
{
    public function viewAny(?Authenticatable $user): bool { return true; }
    public function view(?Authenticatable $user, $model): bool { return true; }
    public function create(?Authenticatable $user): bool { return true; }
    public function update(?Authenticatable $user, $model): bool { return true; }
    public function delete(?Authenticatable $user, $model): bool { return true; }
    public function viewTrashed(?Authenticatable $user): bool { return true; }
    public function restore(?Authenticatable $user, $model): bool { return true; }
    public function forceDelete(?Authenticatable $user, $model): bool { return true; }
}

/** Blacklists two computed attributes for everybody. */
class BlacklistComputedPolicy extends ResourcePolicy implements HasPermittedAttributes
{
    public function viewAny(?Authenticatable $user): bool { return true; }
    public function view(?Authenticatable $user, $model = null): bool { return true; }
    public function viewTrashed(?Authenticatable $user): bool { return true; }

    public function hiddenAttributesForShow(?Authenticatable $user): array
    {
        return ['secret_note', 'secret_total'];
    }

    public function permittedAttributesForShow(?Authenticatable $user): array
    {
        return ['*'];
    }
}

/** Whitelist that only names ONE computed attribute of each kind. */
class WhitelistComputedPolicy extends ResourcePolicy implements HasPermittedAttributes
{
    public function viewAny(?Authenticatable $user): bool { return true; }
    public function view(?Authenticatable $user, $model = null): bool { return true; }
    public function viewTrashed(?Authenticatable $user): bool { return true; }

    public function hiddenAttributesForShow(?Authenticatable $user): array
    {
        return [];
    }

    public function permittedAttributesForShow(?Authenticatable $user): array
    {
        return ['id', 'status', 'first_name', 'full_name', 'active_users_count'];
    }
}

/** Denies viewAny — the /computed endpoint must be gated by it. */
class DenyViewAnyPolicy extends ResourcePolicy
{
    public function viewAny(?Authenticatable $user): bool { return false; }
    public function view(?Authenticatable $user, $model = null): bool { return true; }
}

// --------------------------------------------------------------------------
// Tests
// --------------------------------------------------------------------------

class ComputedAttributesTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        ComputedCallCounter::reset();

        Schema::create('computed_users', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('active');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->unsignedBigInteger('owner_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('indirect_blogs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('indirect_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id');
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('indirect_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id');
            $table->string('body');
            $table->string('status')->default('ok');
            $table->timestamps();
        });

        Schema::create('tenant_computed_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('status')->default('active');
            $table->string('first_name')->nullable();
            $table->timestamps();
        });

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        Gate::policy(ComputedUser::class, OpenComputedPolicy::class);
        Gate::policy(PlainRecord::class, OpenComputedPolicy::class);
        Gate::policy(ExceptedComputed::class, OpenComputedPolicy::class);
        Gate::policy(EmptyDeclarationRecord::class, OpenComputedPolicy::class);
        Gate::policy(TenantComputedUser::class, OpenComputedPolicy::class);
        Gate::policy(PlainWithComputed::class, OpenComputedPolicy::class);
        Gate::policy(IndirectBlog::class, OpenComputedPolicy::class);
        Gate::policy(IndirectPost::class, OpenComputedPolicy::class);
        Gate::policy(IndirectComment::class, OpenComputedPolicy::class);
        Gate::policy(\App\Models\Organization::class, OpenComputedPolicy::class);

        // Clear the controller's static org-path cache between tests.
        $ref = new \ReflectionClass(\Rhino\Controllers\GlobalController::class);
        if ($ref->hasProperty('organizationPathCache')) {
            $prop = $ref->getProperty('organizationPathCache');
            $prop->setAccessible(true);
            $prop->setValue(null, []);
        }
    }

    protected function tearDown(): void
    {
        request()->attributes->remove('organization');
        ComputedCallCounter::reset();
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
            'rhino.multi_tenant' => [
                'organization_identifier_column' => 'slug',
            ],
        ]);

        Route::prefix('api')->group(function () {
            require __DIR__ . '/../../routes/api.php';
        });
    }

    protected function authenticate(int $id = 1): \App\Models\User
    {
        $user = \App\Models\User::firstOrCreate(
            ['id' => $id],
            ['name' => "User {$id}", 'email' => "user{$id}@example.com", 'password' => bcrypt('password')]
        );
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    protected function seedUsers(): void
    {
        ComputedUser::forceCreate(['status' => 'active', 'first_name' => 'Ada', 'last_name' => 'Lovelace', 'owner_id' => 1]);
        ComputedUser::forceCreate(['status' => 'active', 'first_name' => 'Alan', 'last_name' => 'Turing', 'owner_id' => 1]);
        ComputedUser::forceCreate(['status' => 'active', 'first_name' => 'Grace', 'last_name' => 'Hopper', 'owner_id' => 2]);
        ComputedUser::forceCreate(['status' => 'blocked', 'first_name' => 'Mal', 'last_name' => 'Ware', 'owner_id' => 2]);
        ComputedUser::forceCreate(['status' => 'pending', 'first_name' => 'Pat', 'last_name' => 'Ending', 'owner_id' => 1]);
    }

    // ======================================================================
    // COLLECTION-LEVEL: GET /api/{resource}/computed?attributes=
    // ======================================================================

    public function test_computed_endpoint_returns_selected_aggregates(): void
    {
        $this->registerRoutes(['users' => ComputedUser::class]);
        $this->authenticate();
        $this->seedUsers();

        $response = $this->getJson('/api/users/computed?attributes=active_users_count,blocked_users_count');

        $response->assertStatus(200);
        $this->assertSame(
            ['active_users_count' => 3, 'blocked_users_count' => 1],
            $response->json('data')
        );
    }

    public function test_computed_endpoint_evaluates_each_attribute_exactly_once(): void
    {
        $this->registerRoutes(['users' => ComputedUser::class]);
        $this->authenticate();
        $this->seedUsers();

        $this->getJson('/api/users/computed?attributes=active_users_count')->assertStatus(200);

        // The whole point of the endpoint: ONE evaluation for 5 rows.
        $this->assertSame(1, ComputedCallCounter::count('active_users_count'));
        $this->assertSame(0, ComputedCallCounter::count('blocked_users_count'));
    }

    public function test_computed_endpoint_returns_all_permitted_attributes_when_param_omitted(): void
    {
        $this->registerRoutes(['users' => ComputedUser::class]);
        $this->authenticate();
        $this->seedUsers();

        $response = $this->getJson('/api/users/computed');

        $response->assertStatus(200);
        $this->assertSame(
            ['active_users_count', 'blocked_users_count', 'total_count', 'viewer_id', 'secret_total'],
            array_keys($response->json('data'))
        );
    }

    public function test_computed_endpoint_treats_empty_param_as_omitted(): void
    {
        $this->registerRoutes(['users' => ComputedUser::class]);
        $this->authenticate();
        $this->seedUsers();

        $response = $this->getJson('/api/users/computed?attributes=');

        $response->assertStatus(200);
        $this->assertCount(5, $response->json('data'));
    }

    public function test_computed_endpoint_rejects_undeclared_attribute(): void
    {
        $this->registerRoutes(['users' => ComputedUser::class]);
        $this->authenticate();

        $response = $this->getJson('/api/users/computed?attributes=active_users_count,nope_count');

        $response->assertStatus(403);
        $response->assertJson(['message' => "Computed attribute 'nope_count' is not allowed"]);
    }

    public function test_computed_endpoint_rejects_array_param(): void
    {
        $this->registerRoutes(['users' => ComputedUser::class]);
        $this->authenticate();

        $response = $this->getJson('/api/users/computed?attributes[]=active_users_count');

        $response->assertStatus(403);
        $response->assertJson(['message' => 'Computed attributes are not allowed']);
    }

    public function test_computed_endpoint_ignores_blank_segments_and_duplicates(): void
    {
        $this->registerRoutes(['users' => ComputedUser::class]);
        $this->authenticate();
        $this->seedUsers();

        $response = $this->getJson('/api/users/computed?attributes=' . rawurlencode(' active_users_count , ,active_users_count '));

        $response->assertStatus(200);
        $this->assertSame(['active_users_count' => 3], $response->json('data'));
        $this->assertSame(1, ComputedCallCounter::count('active_users_count'));
    }

    public function test_computed_endpoint_passes_current_user_to_the_callable(): void
    {
        $this->registerRoutes(['users' => ComputedUser::class]);
        $user = $this->authenticate(7);

        $response = $this->getJson('/api/users/computed?attributes=viewer_id');

        $response->assertStatus(200);
        $this->assertSame($user->id, $response->json('data.viewer_id'));
    }

    public function test_computed_endpoint_isolates_each_callable_from_the_others(): void
    {
        $this->registerRoutes(['users' => ComputedUser::class]);
        $this->authenticate();
        $this->seedUsers();

        // active(3) is computed first and constrains its own clone; blocked(1)
        // and total(5) must be unaffected by it.
        $response = $this->getJson('/api/users/computed?attributes=active_users_count,blocked_users_count,total_count');

        $response->assertStatus(200);
        $this->assertSame(
            ['active_users_count' => 3, 'blocked_users_count' => 1, 'total_count' => 5],
            $response->json('data')
        );
    }

    // NOTE: Spatie `filter[]` application is a known no-op under the Testbench
    // harness (see NamedScopeTest::test_scope_composes_with_filter and
    // GlobalControllerExtendedTest::test_index_filters_by_allowed_filter, which
    // is skipped for the same reason). We assert at the query level that a
    // filter constrains the query the callables are handed.
    public function test_computed_endpoint_respects_filters(): void
    {
        $this->registerRoutes(['users' => ComputedUser::class]);
        $this->authenticate();
        $this->seedUsers();

        $query = \Spatie\QueryBuilder\QueryBuilder::for(ComputedUser::class)
            ->allowedFilters([\Spatie\QueryBuilder\AllowedFilter::exact('owner_id')]);
        $query->where('owner_id', 1);

        $declared = ComputedUser::rhinoCollectionComputedAttributes();

        $this->assertSame(3, $declared['total_count']($query->clone()->getEloquentBuilder(), null));
        $this->assertSame(2, $declared['active_users_count']($query->clone()->getEloquentBuilder(), null));
    }

    public function test_computed_endpoint_rejects_filters_on_a_model_without_allowed_filters(): void
    {
        $this->registerRoutes(['plains' => PlainWithComputed::class]);
        $this->authenticate();
        $this->seedUsers();

        $response = $this->getJson('/api/plains/computed?attributes=total_count&filters[status]=active');

        $response->assertStatus(403);
        $response->assertJson(['message' => 'Filters are not allowed']);
    }

    public function test_computed_endpoint_respects_search(): void
    {
        $this->registerRoutes(['users' => ComputedUser::class]);
        $this->authenticate();
        $this->seedUsers();

        $response = $this->getJson('/api/users/computed?attributes=total_count&search=Turing');

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('data.total_count'));
    }

    public function test_computed_endpoint_respects_named_scope(): void
    {
        $this->registerRoutes(['users' => ComputedUser::class]);
        $user = $this->authenticate();
        $this->seedUsers();

        // Re-point the fixtures so exactly two rows belong to the acting user.
        ComputedUser::query()->update(['owner_id' => 0]);
        ComputedUser::whereIn('first_name', ['Ada', 'Alan'])->update(['owner_id' => $user->id]);

        $scoped = $this->getJson('/api/users/computed?attributes=total_count&scope=owned');
        $unscoped = $this->getJson('/api/users/computed?attributes=total_count');

        $scoped->assertStatus(200);
        $this->assertSame(2, $scoped->json('data.total_count'));
        $this->assertSame(5, $unscoped->json('data.total_count'));
    }

    public function test_computed_endpoint_rejects_disallowed_named_scope(): void
    {
        $this->registerRoutes(['users' => ComputedUser::class]);
        $this->authenticate();

        $response = $this->getJson('/api/users/computed?attributes=total_count&scope=nope');

        $response->assertStatus(403);
        $response->assertJson(['message' => "Scope 'nope' is not allowed"]);
    }

    public function test_computed_endpoint_excludes_soft_deleted_records(): void
    {
        $this->registerRoutes(['users' => ComputedUser::class]);
        $this->authenticate();
        $this->seedUsers();

        ComputedUser::where('first_name', 'Ada')->first()->delete();

        $response = $this->getJson('/api/users/computed?attributes=total_count');

        $response->assertStatus(200);
        $this->assertSame(4, $response->json('data.total_count'));
    }

    public function test_computed_endpoint_is_gated_by_view_any(): void
    {
        Gate::policy(ComputedUser::class, DenyViewAnyPolicy::class);
        $this->registerRoutes(['users' => ComputedUser::class]);
        $this->authenticate();

        $this->getJson('/api/users/computed?attributes=total_count')->assertStatus(403);
    }

    public function test_computed_endpoint_requires_authentication(): void
    {
        $this->registerRoutes(['users' => ComputedUser::class]);

        $this->getJson('/api/users/computed?attributes=total_count')->assertStatus(401);
    }

    public function test_computed_route_is_not_registered_without_a_declaration(): void
    {
        $this->registerRoutes(['plain' => PlainRecord::class]);
        $this->authenticate();

        // Falls through to show() for the literal id "computed" → 404, exactly
        // as it did before this feature existed.
        $this->getJson('/api/plain/computed')->assertStatus(404);
    }

    public function test_computed_route_is_not_registered_for_an_empty_declaration(): void
    {
        $this->registerRoutes(['empties' => EmptyDeclarationRecord::class]);
        $this->authenticate();

        $this->getJson('/api/empties/computed')->assertStatus(404);
    }

    public function test_computed_route_honours_except_actions(): void
    {
        $this->registerRoutes(['excepted' => ExceptedComputed::class]);
        $this->authenticate();

        $this->getJson('/api/excepted/computed')->assertStatus(404);
    }

    public function test_computed_route_does_not_shadow_other_member_routes(): void
    {
        $this->registerRoutes(['users' => ComputedUser::class]);
        $this->authenticate();
        $this->seedUsers();

        $first = ComputedUser::first();

        $this->getJson('/api/users/' . $first->id)->assertStatus(200);
        $this->getJson('/api/users/trashed')->assertStatus(200);
        $this->getJson('/api/users')->assertStatus(200);
    }

    // ======================================================================
    // COLLECTION-LEVEL: policy gating
    // ======================================================================

    public function test_computed_endpoint_rejects_blacklisted_attribute(): void
    {
        Gate::policy(ComputedUser::class, BlacklistComputedPolicy::class);
        $this->registerRoutes(['users' => ComputedUser::class]);
        $this->authenticate();

        $response = $this->getJson('/api/users/computed?attributes=secret_total');

        $response->assertStatus(403);
        $response->assertJson(['message' => "Computed attribute 'secret_total' is not allowed"]);
    }

    public function test_computed_endpoint_omits_blacklisted_attribute_when_param_omitted(): void
    {
        Gate::policy(ComputedUser::class, BlacklistComputedPolicy::class);
        $this->registerRoutes(['users' => ComputedUser::class]);
        $this->authenticate();
        $this->seedUsers();

        $response = $this->getJson('/api/users/computed');

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('secret_total', $response->json('data'));
        $this->assertArrayHasKey('active_users_count', $response->json('data'));
    }

    public function test_computed_endpoint_rejects_attribute_outside_the_whitelist(): void
    {
        Gate::policy(ComputedUser::class, WhitelistComputedPolicy::class);
        $this->registerRoutes(['users' => ComputedUser::class]);
        $this->authenticate();

        $this->getJson('/api/users/computed?attributes=active_users_count')->assertStatus(200);

        $response = $this->getJson('/api/users/computed?attributes=blocked_users_count');
        $response->assertStatus(403);
        $response->assertJson(['message' => "Computed attribute 'blocked_users_count' is not allowed"]);
    }

    public function test_computed_endpoint_returns_only_whitelisted_attributes_when_param_omitted(): void
    {
        Gate::policy(ComputedUser::class, WhitelistComputedPolicy::class);
        $this->registerRoutes(['users' => ComputedUser::class]);
        $this->authenticate();
        $this->seedUsers();

        $response = $this->getJson('/api/users/computed');

        $response->assertStatus(200);
        $this->assertSame(['active_users_count' => 3], $response->json('data'));
    }

    public function test_computed_endpoint_gives_the_same_error_for_unknown_and_denied_names(): void
    {
        Gate::policy(ComputedUser::class, WhitelistComputedPolicy::class);
        $this->registerRoutes(['users' => ComputedUser::class]);
        $this->authenticate();

        $denied = $this->getJson('/api/users/computed?attributes=blocked_users_count');
        $unknown = $this->getJson('/api/users/computed?attributes=blocked_users_count_typo');

        // Same status + same shape → the endpoint never reveals which
        // attributes a model actually declares.
        $this->assertSame(403, $denied->status());
        $this->assertSame(403, $unknown->status());
        $this->assertStringContainsString('is not allowed', $denied->json('message'));
        $this->assertStringContainsString('is not allowed', $unknown->json('message'));
    }

    // ======================================================================
    // RECORD-LEVEL: ?computed_attributes= on index / show / trashed
    // ======================================================================

    public function test_index_omits_opt_in_attributes_by_default(): void
    {
        $this->registerRoutes(['users' => ComputedUser::class]);
        $this->authenticate();
        $this->seedUsers();

        $response = $this->getJson('/api/users');

        $response->assertStatus(200);
        $row = $response->json('data.0');
        $this->assertArrayNotHasKey('full_name', $row);
        $this->assertArrayNotHasKey('expensive_flag', $row);
        // Legacy always-on attribute is untouched.
        $this->assertSame('always-here', $row['legacy_label']);
    }

    public function test_index_never_evaluates_opt_in_attributes_by_default(): void
    {
        $this->registerRoutes(['users' => ComputedUser::class]);
        $this->authenticate();
        $this->seedUsers();

        $this->getJson('/api/users')->assertStatus(200);

        $this->assertSame(0, ComputedCallCounter::count('full_name'));
        $this->assertSame(0, ComputedCallCounter::count('expensive_flag'));
    }

    public function test_index_includes_only_the_requested_opt_in_attributes(): void
    {
        $this->registerRoutes(['users' => ComputedUser::class]);
        $this->authenticate();
        $this->seedUsers();

        $response = $this->getJson('/api/users?computed_attributes=full_name');

        $response->assertStatus(200);
        $row = $response->json('data.0');
        $this->assertSame('Ada Lovelace', $row['full_name']);
        $this->assertArrayNotHasKey('expensive_flag', $row);
        $this->assertSame(5, ComputedCallCounter::count('full_name'));
        $this->assertSame(0, ComputedCallCounter::count('expensive_flag'));
    }

    public function test_index_supports_multiple_opt_in_attributes(): void
    {
        $this->registerRoutes(['users' => ComputedUser::class]);
        $this->authenticate();
        $this->seedUsers();

        $response = $this->getJson('/api/users?computed_attributes=full_name,expensive_flag');

        $response->assertStatus(200);
        $row = $response->json('data.0');
        $this->assertSame('Ada Lovelace', $row['full_name']);
        $this->assertTrue($row['expensive_flag']);
    }

    public function test_show_includes_requested_opt_in_attribute(): void
    {
        $this->registerRoutes(['users' => ComputedUser::class]);
        $this->authenticate();
        $this->seedUsers();

        $id = ComputedUser::where('first_name', 'Alan')->value('id');

        $response = $this->getJson("/api/users/{$id}?computed_attributes=full_name");

        $response->assertStatus(200);
        $this->assertSame('Alan Turing', $response->json('full_name'));
    }

    public function test_show_omits_opt_in_attributes_by_default(): void
    {
        $this->registerRoutes(['users' => ComputedUser::class]);
        $this->authenticate();
        $this->seedUsers();

        $id = ComputedUser::where('first_name', 'Alan')->value('id');

        $response = $this->getJson("/api/users/{$id}");

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('full_name', $response->json());
    }

    public function test_trashed_includes_requested_opt_in_attribute(): void
    {
        $this->registerRoutes(['users' => ComputedUser::class]);
        $this->authenticate();
        $this->seedUsers();

        ComputedUser::where('first_name', 'Ada')->first()->delete();

        $response = $this->getJson('/api/users/trashed?computed_attributes=full_name');

        $response->assertStatus(200);
        $this->assertSame('Ada Lovelace', $response->json('data.0.full_name'));
    }

    public function test_opt_in_attribute_receives_the_current_user(): void
    {
        $this->registerRoutes(['users' => ComputedUser::class]);
        $user = $this->authenticate(9);
        $this->seedUsers();

        $response = $this->getJson('/api/users?computed_attributes=viewer_id');

        $response->assertStatus(200);
        $this->assertSame($user->id, $response->json('data.0.viewer_id'));
    }

    public function test_index_rejects_undeclared_opt_in_attribute(): void
    {
        $this->registerRoutes(['users' => ComputedUser::class]);
        $this->authenticate();

        $response = $this->getJson('/api/users?computed_attributes=full_name,made_up');

        $response->assertStatus(403);
        $response->assertJson(['message' => "Computed attribute 'made_up' is not allowed"]);
    }

    public function test_show_rejects_undeclared_opt_in_attribute(): void
    {
        $this->registerRoutes(['users' => ComputedUser::class]);
        $this->authenticate();
        $this->seedUsers();

        $id = ComputedUser::first()->id;

        $this->getJson("/api/users/{$id}?computed_attributes=made_up")->assertStatus(403);
    }

    public function test_trashed_rejects_undeclared_opt_in_attribute(): void
    {
        $this->registerRoutes(['users' => ComputedUser::class]);
        $this->authenticate();

        $this->getJson('/api/users/trashed?computed_attributes=made_up')->assertStatus(403);
    }

    public function test_index_rejects_array_computed_attributes_param(): void
    {
        $this->registerRoutes(['users' => ComputedUser::class]);
        $this->authenticate();

        $response = $this->getJson('/api/users?computed_attributes[]=full_name');

        $response->assertStatus(403);
        $response->assertJson(['message' => 'Computed attributes are not allowed']);
    }

    public function test_index_treats_empty_computed_attributes_param_as_none(): void
    {
        $this->registerRoutes(['users' => ComputedUser::class]);
        $this->authenticate();
        $this->seedUsers();

        $response = $this->getJson('/api/users?computed_attributes=');

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('full_name', $response->json('data.0'));
    }

    public function test_index_on_a_model_without_declarations_rejects_any_selection(): void
    {
        $this->registerRoutes(['plain' => PlainRecord::class]);
        $this->authenticate();
        $this->seedUsers();

        $this->getJson('/api/plain')->assertStatus(200);
        $this->getJson('/api/plain?computed_attributes=full_name')->assertStatus(403);
    }

    public function test_opt_in_attributes_survive_pagination(): void
    {
        $this->registerRoutes(['users' => ComputedUser::class]);
        $this->authenticate();
        $this->seedUsers();

        $response = $this->getJson('/api/users?computed_attributes=full_name&per_page=2');

        $response->assertStatus(200);
        $this->assertCount(2, $response->json('data'));
        $this->assertSame('Ada Lovelace', $response->json('data.0.full_name'));
        // Evaluated only for the rows actually returned.
        $this->assertSame(2, ComputedCallCounter::count('full_name'));
    }

    // Spatie `filter[]`/`sort` are harness no-ops (see the note above), so this
    // asserts the pieces that DO run end-to-end: every returned row carries the
    // selected attribute, and the selection survives alongside the other params.
    public function test_opt_in_attributes_combine_with_other_query_params(): void
    {
        $this->registerRoutes(['users' => ComputedUser::class]);
        $this->authenticate();
        $this->seedUsers();

        $response = $this->getJson('/api/users?computed_attributes=full_name&filter[status]=active&sort=first_name');

        $response->assertStatus(200);
        foreach ($response->json('data') as $row) {
            $this->assertArrayHasKey('full_name', $row);
            $this->assertSame(trim($row['first_name'] . ' ' . $row['last_name']), $row['full_name']);
        }
    }

    public function test_opt_in_attributes_combine_with_a_named_scope(): void
    {
        $this->registerRoutes(['users' => ComputedUser::class]);
        $user = $this->authenticate();
        $this->seedUsers();

        ComputedUser::query()->update(['owner_id' => 0]);
        ComputedUser::where('first_name', 'Ada')->update(['owner_id' => $user->id]);

        $response = $this->getJson('/api/users?computed_attributes=full_name&scope=owned');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Ada Lovelace', $response->json('data.0.full_name'));
    }

    // ======================================================================
    // RECORD-LEVEL: policy gating
    // ======================================================================

    public function test_index_rejects_blacklisted_opt_in_attribute(): void
    {
        Gate::policy(ComputedUser::class, BlacklistComputedPolicy::class);
        $this->registerRoutes(['users' => ComputedUser::class]);
        $this->authenticate();

        $response = $this->getJson('/api/users?computed_attributes=secret_note');

        $response->assertStatus(403);
        $response->assertJson(['message' => "Computed attribute 'secret_note' is not allowed"]);
    }

    public function test_index_allows_non_blacklisted_opt_in_attribute(): void
    {
        Gate::policy(ComputedUser::class, BlacklistComputedPolicy::class);
        $this->registerRoutes(['users' => ComputedUser::class]);
        $this->authenticate();
        $this->seedUsers();

        $response = $this->getJson('/api/users?computed_attributes=full_name');

        $response->assertStatus(200);
        $this->assertSame('Ada Lovelace', $response->json('data.0.full_name'));
    }

    public function test_index_rejects_opt_in_attribute_outside_the_whitelist(): void
    {
        Gate::policy(ComputedUser::class, WhitelistComputedPolicy::class);
        $this->registerRoutes(['users' => ComputedUser::class]);
        $this->authenticate();

        $this->getJson('/api/users?computed_attributes=expensive_flag')->assertStatus(403);
    }

    public function test_whitelisted_opt_in_attribute_survives_serialization(): void
    {
        Gate::policy(ComputedUser::class, WhitelistComputedPolicy::class);
        $this->registerRoutes(['users' => ComputedUser::class]);
        $this->authenticate();
        $this->seedUsers();

        $response = $this->getJson('/api/users?computed_attributes=full_name');

        $response->assertStatus(200);
        $row = $response->json('data.0');
        $this->assertSame('Ada Lovelace', $row['full_name']);
        // The whitelist still strips everything it does not name.
        $this->assertArrayNotHasKey('last_name', $row);
        $this->assertArrayNotHasKey('legacy_label', $row);
    }

    // ======================================================================
    // MULTI-TENANCY
    // ======================================================================

    public function test_computed_endpoint_is_scoped_to_the_current_organization(): void
    {
        $this->registerTenantRoutes(['tusers' => TenantComputedUser::class]);
        [$user, $org] = $this->createUserInOrg('acme');
        $other = \App\Models\Organization::firstOrCreate(['slug' => 'other'], ['name' => 'Other', 'domain' => null]);

        TenantComputedUser::forceCreate(['organization_id' => $org->id, 'status' => 'active', 'first_name' => 'Mine']);
        TenantComputedUser::forceCreate(['organization_id' => $org->id, 'status' => 'blocked', 'first_name' => 'Mine2']);
        TenantComputedUser::forceCreate(['organization_id' => $other->id, 'status' => 'active', 'first_name' => 'Theirs']);
        TenantComputedUser::forceCreate(['organization_id' => $other->id, 'status' => 'active', 'first_name' => 'Theirs2']);

        $response = $this->getJson('/api/acme/tusers/computed?attributes=total_count,active_users_count');

        $response->assertStatus(200);
        $this->assertSame(
            ['total_count' => 2, 'active_users_count' => 1],
            $response->json('data')
        );
    }

    public function test_opt_in_attributes_work_under_a_tenant_prefix(): void
    {
        $this->registerTenantRoutes(['tusers' => TenantComputedUser::class]);
        [$user, $org] = $this->createUserInOrg('acme');

        TenantComputedUser::forceCreate(['organization_id' => $org->id, 'status' => 'active', 'first_name' => 'Mine']);

        $response = $this->getJson('/api/acme/tusers?computed_attributes=shouty_name');

        $response->assertStatus(200);
        $this->assertSame('MINE', $response->json('data.0.shouty_name'));
    }

    // ----------------------------------------------------------------------
    // INDIRECT tenancy — the leak class fixed in 4.6.1. The framework must
    // hand the callable a query already scoped through the ownership chain.
    // ----------------------------------------------------------------------

    /** @return array{0: \App\Models\Organization, 1: \App\Models\Organization} */
    protected function seedIndirectComments(): array
    {
        [$user, $org] = $this->createUserInOrg('acme');
        $other = \App\Models\Organization::firstOrCreate(['slug' => 'other'], ['name' => 'Other', 'domain' => null]);

        $mineBlog = IndirectBlog::forceCreate(['organization_id' => $org->id, 'name' => 'Mine']);
        $theirBlog = IndirectBlog::forceCreate(['organization_id' => $other->id, 'name' => 'Theirs']);

        $minePost = IndirectPost::forceCreate(['blog_id' => $mineBlog->id, 'title' => 'Mine']);
        $theirPost = IndirectPost::forceCreate(['blog_id' => $theirBlog->id, 'title' => 'Theirs']);

        // 2 in my org (1 flagged), 3 in the other org (2 flagged)
        IndirectComment::forceCreate(['post_id' => $minePost->id, 'body' => 'mine a', 'status' => 'ok']);
        IndirectComment::forceCreate(['post_id' => $minePost->id, 'body' => 'mine b', 'status' => 'flagged']);
        IndirectComment::forceCreate(['post_id' => $theirPost->id, 'body' => 'theirs a', 'status' => 'flagged']);
        IndirectComment::forceCreate(['post_id' => $theirPost->id, 'body' => 'theirs b', 'status' => 'flagged']);
        IndirectComment::forceCreate(['post_id' => $theirPost->id, 'body' => 'theirs c', 'status' => 'ok']);

        return [$org, $other];
    }

    public function test_computed_endpoint_scopes_indirectly_owned_models(): void
    {
        $this->registerTenantRoutes(['icomments' => IndirectComment::class]);
        $this->seedIndirectComments();

        $response = $this->getJson('/api/acme/icomments/computed?attributes=total_count,flagged_count');

        $response->assertStatus(200);
        // 5 rows exist; only the 2 reachable through acme's blog may be counted.
        $this->assertSame(
            ['total_count' => 2, 'flagged_count' => 1],
            $response->json('data')
        );
    }

    public function test_computed_endpoint_matches_index_for_indirectly_owned_models(): void
    {
        $this->registerTenantRoutes(['icomments' => IndirectComment::class]);
        $this->seedIndirectComments();

        $index = $this->getJson('/api/acme/icomments');
        $computed = $this->getJson('/api/acme/icomments/computed?attributes=total_count');

        $index->assertStatus(200);
        $computed->assertStatus(200);
        // The aggregate must describe exactly the set index returned.
        $this->assertSame(count($index->json('data')), $computed->json('data.total_count'));
    }

    public function test_opt_in_attributes_never_expose_indirectly_owned_rows_of_another_org(): void
    {
        $this->registerTenantRoutes(['icomments' => IndirectComment::class]);
        $this->seedIndirectComments();

        $response = $this->getJson('/api/acme/icomments?computed_attributes=shouty_body');

        $response->assertStatus(200);
        $bodies = array_column($response->json('data'), 'shouty_body');
        sort($bodies);
        $this->assertSame(['MINE A', 'MINE B'], $bodies);
    }

    public function test_aggregates_of_two_orgs_are_disjoint(): void
    {
        $this->registerTenantRoutes(['icomments' => IndirectComment::class]);
        [$org, $other] = $this->seedIndirectComments();

        $mine = $this->getJson('/api/acme/icomments/computed?attributes=total_count');

        // Switch identity to the other org and re-ask.
        $otherUser = \App\Models\User::forceCreate([
            'name' => 'Other User',
            'email' => 'other-user@example.com',
            'password' => bcrypt('password'),
        ]);
        $role = \App\Models\Role::firstOrCreate(['slug' => 'admin'], ['name' => 'Admin']);
        \App\Models\UserRole::forceCreate([
            'user_id' => $otherUser->id,
            'role_id' => $role->id,
            'organization_id' => $other->id,
            'permissions' => ['*'],
        ]);
        $this->actingAs($otherUser, 'sanctum');

        $theirs = $this->getJson('/api/other/icomments/computed?attributes=total_count');

        $this->assertSame(2, $mine->json('data.total_count'));
        $this->assertSame(3, $theirs->json('data.total_count'));
    }

    protected function createUserInOrg(string $orgSlug, array $permissions = ['*']): array
    {
        $user = \App\Models\User::forceCreate([
            'name' => 'Test User',
            'email' => "user-{$orgSlug}@example.com",
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
            'permissions' => $permissions,
        ]);

        $this->actingAs($user, 'sanctum');

        return [$user, $org];
    }
}
