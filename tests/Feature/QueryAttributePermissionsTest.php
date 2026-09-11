<?php

namespace Rhino\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Rhino\Contracts\HasPermittedAttributes;
use Spatie\QueryBuilder\AllowedFilter;
use Spatie\QueryBuilder\AllowedSort;
use Rhino\Tests\TestCase;
use Rhino\Traits\HasValidation;
use Rhino\Traits\HidableColumns;

class GatedEmployee extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'gated_employees';

    protected $fillable = ['name', 'salary', 'ssn'];

    protected $validationRules = ['name' => 'string', 'salary' => 'integer', 'ssn' => 'string'];

    public static $allowedFilters = ['name', 'salary'];
    public static $allowedSorts = ['name', 'salary'];
    public static $allowedSearch = ['name', 'ssn'];
}

// Declares its query allowlists with Spatie objects rather than plain strings,
// which is what `AllowedFilter::exact()` / `AllowedSort::field()` produce.
class SpatieObjectEmployee extends GatedEmployee
{
    protected $table = 'gated_employees';

    public static $allowedFilters = ['name'];

    public static $allowedSorts = ['name'];

    public static function bootSpatieObjectEmployee(): void
    {
        // Set in a boot hook so the objects are built after the container is up.
    }
}

class SecretOnlyEmployee extends GatedEmployee
{
    public static $allowedSearch = ['ssn'];
}

class BlacklistPolicy implements HasPermittedAttributes
{
    public function viewAny(?Authenticatable $user): bool { return true; }
    public function view(?Authenticatable $user, $model): bool { return true; }
    public function create(?Authenticatable $user): bool { return true; }
    public function update(?Authenticatable $user, $model): bool { return true; }
    public function delete(?Authenticatable $user, $model): bool { return true; }
    public function viewTrashed(?Authenticatable $user): bool { return true; }

    public function permittedAttributesForShow(?Authenticatable $user): array { return ['*']; }
    public function hiddenAttributesForShow(?Authenticatable $user): array { return ['salary', 'ssn']; }
    public function permittedAttributesForCreate(?Authenticatable $user): array { return ['*']; }
    public function permittedAttributesForUpdate(?Authenticatable $user): array { return ['*']; }
}

class WhitelistPolicy extends BlacklistPolicy
{
    public function permittedAttributesForShow(?Authenticatable $user): array { return ['id', 'name']; }
    public function hiddenAttributesForShow(?Authenticatable $user): array { return []; }
}

class HidesALabelPolicy extends BlacklistPolicy
{
    public function hiddenAttributesForShow(?Authenticatable $user): array { return ['secret']; }
}

class OpenPolicy extends BlacklistPolicy
{
    public function hiddenAttributesForShow(?Authenticatable $user): array { return []; }
}

class QueryAttributePermissionsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('gated_employees', function (Blueprint $table) {
            $table->id();
            $table->string('name');
            $table->integer('salary')->default(0);
            $table->string('ssn')->nullable();
            $table->timestamps();
        });

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Gate::policy(GatedEmployee::class, BlacklistPolicy::class);
        Gate::policy(SecretOnlyEmployee::class, BlacklistPolicy::class);
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('auth.guards.sanctum', ['driver' => 'session', 'provider' => 'users']);
        $app['config']->set('auth.providers.users', ['driver' => 'eloquent', 'model' => \App\Models\User::class]);
    }

    protected function registerRoutes(array $models): void
    {
        config([
            'rhino.models' => $models,
            'rhino.route_groups' => [
                'default' => ['prefix' => '', 'middleware' => [], 'models' => '*'],
            ],
            'rhino.multi_tenant' => ['organization_identifier_column' => 'id'],
        ]);

        Route::prefix('api')->group(function () {
            require __DIR__.'/../../routes/api.php';
        });
    }

    protected function authenticate(): void
    {
        $user = \App\Models\User::firstOrCreate(
            ['id' => 1],
            ['name' => 'User 1', 'email' => 'user1@example.com', 'password' => bcrypt('password')]
        );
        $this->actingAs($user, 'sanctum');
    }

    protected function seedEmployees(): void
    {
        GatedEmployee::forceCreate(['name' => 'Alice', 'salary' => 300000, 'ssn' => '111-22-3333']);
        GatedEmployee::forceCreate(['name' => 'Bob', 'salary' => 50000, 'ssn' => '444-55-6666']);
    }

    public function test_object_allowlists_do_not_break_the_gate(): void
    {
        // A model may declare its allowlists with Spatie objects. Casting one to
        // a string is a fatal error, so the gate has to read its name instead.
        SpatieObjectEmployee::$allowedFilters = [AllowedFilter::exact('salary'), 'name'];
        SpatieObjectEmployee::$allowedSorts = [AllowedSort::field('salary'), 'name'];

        Gate::policy(SpatieObjectEmployee::class, BlacklistPolicy::class);
        $this->registerRoutes(['employees' => SpatieObjectEmployee::class]);
        $this->authenticate();
        $this->seedEmployees();

        $this->getJson('/api/employees?sort=name')->assertOk();
        $this->getJson('/api/employees?filter[name]=Alice')->assertOk();

        $this->getJson('/api/employees?sort=-salary')
            ->assertStatus(403)
            ->assertJson(['message' => "Sort 'salary' is not allowed"]);

        $this->getJson('/api/employees?filter[salary]=300000')
            ->assertStatus(403)
            ->assertJson(['message' => "Filter 'salary' is not allowed"]);
    }

    public function test_a_renamed_sort_cannot_walk_around_the_policy(): void
    {
        // AllowedSort::field('cost', 'salary') is asked for as ?sort=cost but
        // orders by the hidden 'salary' column. Checking only the name the
        // client sent would hand the ordering over anyway.
        SpatieObjectEmployee::$allowedFilters = ['name'];
        SpatieObjectEmployee::$allowedSorts = [AllowedSort::field('cost', 'salary'), 'name'];

        Gate::policy(SpatieObjectEmployee::class, BlacklistPolicy::class);
        $this->registerRoutes(['employees' => SpatieObjectEmployee::class]);
        $this->authenticate();
        $this->seedEmployees();

        $this->getJson('/api/employees?sort=cost')
            ->assertStatus(403)
            ->assertJson(['message' => "Sort 'cost' is not allowed"]);
    }

    public function test_a_renamed_filter_cannot_walk_around_the_policy(): void
    {
        SpatieObjectEmployee::$allowedFilters = [AllowedFilter::exact('cost', 'salary'), 'name'];
        SpatieObjectEmployee::$allowedSorts = ['name'];

        Gate::policy(SpatieObjectEmployee::class, BlacklistPolicy::class);
        $this->registerRoutes(['employees' => SpatieObjectEmployee::class]);
        $this->authenticate();
        $this->seedEmployees();

        $this->getJson('/api/employees?filter[cost]=300000')
            ->assertStatus(403)
            ->assertJson(['message' => "Filter 'cost' is not allowed"]);
    }

    public function test_a_label_that_is_not_a_column_survives_a_whitelist_policy(): void
    {
        // A callback filter's name is a label, not an attribute: a whitelist
        // policy has no opinion about it, so refusing it would break the
        // endpoint for every restricted role.
        SpatieObjectEmployee::$allowedFilters = [
            AllowedFilter::callback('q', fn ($query, $value) => $query->where('name', 'like', "%{$value}%")),
            'name',
        ];
        SpatieObjectEmployee::$allowedSorts = [AllowedSort::field('label', 'name')];

        Gate::policy(SpatieObjectEmployee::class, WhitelistPolicy::class);
        $this->registerRoutes(['employees' => SpatieObjectEmployee::class]);
        $this->authenticate();
        $this->seedEmployees();

        $this->getJson('/api/employees?filter[q]=Ali')->assertOk();
        $this->getJson('/api/employees?sort=label')->assertOk();
    }

    public function test_a_hidden_name_is_refused_even_when_it_is_not_a_column(): void
    {
        // An explicit blacklist wins over the label rule above.
        SpatieObjectEmployee::$allowedFilters = [
            AllowedFilter::callback('secret', fn ($query, $value) => $query),
        ];
        SpatieObjectEmployee::$allowedSorts = ['name'];

        Gate::policy(SpatieObjectEmployee::class, HidesALabelPolicy::class);
        $this->registerRoutes(['employees' => SpatieObjectEmployee::class]);
        $this->authenticate();
        $this->seedEmployees();

        $this->getJson('/api/employees?filter[secret]=1')
            ->assertStatus(403)
            ->assertJson(['message' => "Filter 'secret' is not allowed"]);
    }

    public function test_a_renamed_sort_on_a_visible_column_still_works(): void
    {
        SpatieObjectEmployee::$allowedFilters = [AllowedFilter::exact('label', 'name')];
        SpatieObjectEmployee::$allowedSorts = [AllowedSort::field('label', 'name')];

        Gate::policy(SpatieObjectEmployee::class, BlacklistPolicy::class);
        $this->registerRoutes(['employees' => SpatieObjectEmployee::class]);
        $this->authenticate();
        $this->seedEmployees();

        $this->getJson('/api/employees?sort=-label')->assertOk();
        $this->getJson('/api/employees?filter[label]=Alice')->assertOk();
    }

    public function test_hidden_attribute_is_still_stripped_from_the_response(): void
    {
        $this->registerRoutes(['employees' => GatedEmployee::class]);
        $this->authenticate();
        $this->seedEmployees();

        $response = $this->getJson('/api/employees');

        $response->assertOk();
        $this->assertSame(['id', 'name'], array_keys($response->json('data.0')));
    }

    public function test_filtering_by_a_hidden_attribute_is_refused(): void
    {
        $this->registerRoutes(['employees' => GatedEmployee::class]);
        $this->authenticate();
        $this->seedEmployees();

        $response = $this->getJson('/api/employees?filter[salary]=300000');

        $response->assertStatus(403);
        $response->assertJson(['message' => "Filter 'salary' is not allowed"]);
    }

    public function test_sorting_by_a_hidden_attribute_is_refused(): void
    {
        $this->registerRoutes(['employees' => GatedEmployee::class]);
        $this->authenticate();
        $this->seedEmployees();

        $response = $this->getJson('/api/employees?sort=-salary');

        $response->assertStatus(403);
        $response->assertJson(['message' => "Sort 'salary' is not allowed"]);
    }

    public function test_a_whitelist_policy_denies_everything_outside_it(): void
    {
        Gate::policy(GatedEmployee::class, WhitelistPolicy::class);
        $this->registerRoutes(['employees' => GatedEmployee::class]);
        $this->authenticate();
        $this->seedEmployees();

        $this->getJson('/api/employees?filter[salary]=300000')->assertStatus(403);
        $this->getJson('/api/employees?sort=salary')->assertStatus(403);
        $this->getJson('/api/employees?filter[name]=Alice')->assertOk();
    }

    public function test_permitted_attributes_still_filter_and_sort(): void
    {
        $this->registerRoutes(['employees' => GatedEmployee::class]);
        $this->authenticate();
        $this->seedEmployees();

        $this->getJson('/api/employees?filter[name]=Alice')->assertOk();
        $this->getJson('/api/employees?sort=-name')->assertOk();
    }

    public function test_a_column_outside_the_model_allowlist_is_ignored_not_refused(): void
    {
        $this->registerRoutes(['employees' => GatedEmployee::class]);
        $this->authenticate();
        $this->seedEmployees();

        // 'ssn' is hidden AND never allowlisted for filtering: refusing here would
        // tell the caller the column exists.
        $this->getJson('/api/employees?filter[ssn]=111-22-3333')->assertOk();
        $this->getJson('/api/employees?sort=ssn')->assertOk();
    }

    public function test_search_skips_hidden_columns(): void
    {
        $this->registerRoutes(['employees' => GatedEmployee::class]);
        $this->authenticate();
        $this->seedEmployees();

        // 'ssn' is searchable on the model but hidden from this user, so the term
        // only reaches 'name' and matches nothing.
        $response = $this->getJson('/api/employees?search=111-22');

        $response->assertOk();
        $this->assertSame([], $response->json('data'));

        $this->assertSame(
            ['Alice'],
            array_column($this->getJson('/api/employees?search=alice')->json('data'), 'name')
        );
    }

    public function test_search_returns_nothing_when_every_searchable_column_is_hidden(): void
    {
        $this->registerRoutes(['employees' => SecretOnlyEmployee::class]);
        $this->authenticate();
        SecretOnlyEmployee::forceCreate(['name' => 'Alice', 'salary' => 1, 'ssn' => '111-22-3333']);

        $response = $this->getJson('/api/employees?search=111-22');

        $response->assertOk();
        $this->assertSame([], $response->json('data'));
    }

    public function test_search_is_unaffected_when_nothing_is_hidden(): void
    {
        Gate::policy(GatedEmployee::class, OpenPolicy::class);
        $this->registerRoutes(['employees' => GatedEmployee::class]);
        $this->authenticate();
        $this->seedEmployees();

        $response = $this->getJson('/api/employees?search=111-22');

        $response->assertOk();
        $this->assertSame(['Alice'], array_column($response->json('data'), 'name'));
    }

    public function test_the_gate_runs_before_any_row_is_read(): void
    {
        $this->registerRoutes(['employees' => GatedEmployee::class]);
        $this->authenticate();
        $this->seedEmployees();

        // A filter value that matches nothing still 403s: the refusal comes from
        // the gate, not from an empty result.
        $this->getJson('/api/employees?filter[salary]=1')->assertStatus(403);
    }
}
