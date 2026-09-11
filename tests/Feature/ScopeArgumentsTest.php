<?php

namespace Rhino\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Route;
use Illuminate\Support\Facades\Schema;
use Rhino\Tests\TestCase;
use Rhino\Traits\HasValidation;
use Rhino\Traits\HidableColumns;

// --------------------------------------------------------------------------
// Test models
// --------------------------------------------------------------------------

class ArgRoute extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'arg_routes';

    protected $fillable = ['status', 'title', 'distance', 'flagged'];

    protected $validationRules = [
        'status' => 'string',
        'title' => 'string',
        'distance' => 'integer',
        'flagged' => 'boolean',
    ];

    public static $allowedScopes = [
        'archived',                                                       // no params
        'longerThan' => 'distance',                                       // one param
        'window' => ['min', 'max'],                                       // two, required
        'titled' => ['params' => ['title', 'status'], 'optional' => ['status']],
        'flaggedIs' => 'flag',
    ];

    public static $defaultScope = null;

    public function scopeArchived(Builder $query, ?Authenticatable $user): Builder
    {
        return $query->where('status', 'archived');
    }

    public function scopeLongerThan(Builder $query, ?Authenticatable $user, $distance): Builder
    {
        return $query->where('distance', '>', (int) $distance);
    }

    public function scopeWindow(Builder $query, ?Authenticatable $user, $min, $max): Builder
    {
        return $query->whereBetween('distance', [(int) $min, (int) $max]);
    }

    public function scopeTitled(Builder $query, ?Authenticatable $user, $title, $status = null): Builder
    {
        $query->where('title', $title);

        if ($status !== null) {
            $query->where('status', $status);
        }

        return $query;
    }

    public function scopeFlaggedIs(Builder $query, ?Authenticatable $user, $flag): Builder
    {
        // A string "false" would be truthy: the binder must hand over a real bool.
        return $query->where('flagged', $flag === true);
    }
}

class DefaultArgRoute extends ArgRoute
{
    public static $defaultScope = 'archived';
}

class ArgRoutePolicy
{
    public function viewAny(?Authenticatable $user): bool { return true; }
    public function view(?Authenticatable $user, $model): bool { return true; }
    public function create(?Authenticatable $user): bool { return true; }
    public function update(?Authenticatable $user, $model): bool { return true; }
    public function delete(?Authenticatable $user, $model): bool { return true; }
    public function viewTrashed(?Authenticatable $user): bool { return true; }
}

class RestrictedScopePolicy extends ArgRoutePolicy
{
    public function permittedScopes(?Authenticatable $user): array
    {
        return ['archived'];
    }
}

// --------------------------------------------------------------------------

class ScopeArgumentsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('arg_routes', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('active');
            $table->string('title')->default('');
            $table->integer('distance')->default(0);
            $table->boolean('flagged')->default(false);
            $table->timestamps();
        });

        $this->loadMigrationsFrom(__DIR__.'/../database/migrations');

        Gate::policy(ArgRoute::class, ArgRoutePolicy::class);
        Gate::policy(DefaultArgRoute::class, ArgRoutePolicy::class);
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

    protected function authenticate(): \App\Models\User
    {
        $user = \App\Models\User::firstOrCreate(
            ['id' => 1],
            ['name' => 'User 1', 'email' => 'user1@example.com', 'password' => bcrypt('password')]
        );
        $this->actingAs($user, 'sanctum');

        return $user;
    }

    protected function seedRoutes(): void
    {
        ArgRoute::forceCreate(['status' => 'archived', 'title' => 'Old', 'distance' => 10, 'flagged' => true]);
        ArgRoute::forceCreate(['status' => 'active', 'title' => 'Near', 'distance' => 20, 'flagged' => false]);
        ArgRoute::forceCreate(['status' => 'active', 'title' => 'Far', 'distance' => 90, 'flagged' => false]);
    }

    protected function titles($response): array
    {
        return array_column($response->json('data'), 'title');
    }

    // ---- backward compatibility -------------------------------------------

    public function test_legacy_string_form_still_works(): void
    {
        $this->registerRoutes(['routes' => ArgRoute::class]);
        $this->authenticate();
        $this->seedRoutes();

        $response = $this->getJson('/api/routes?scope=archived');

        $response->assertOk();
        $this->assertSame(['Old'], $this->titles($response));
    }

    public function test_bracket_form_with_empty_value_runs_a_no_argument_scope(): void
    {
        $this->registerRoutes(['routes' => ArgRoute::class]);
        $this->authenticate();
        $this->seedRoutes();

        $response = $this->getJson('/api/routes?scope[archived]=');

        $response->assertOk();
        $this->assertSame(['Old'], $this->titles($response));
    }

    public function test_default_scope_still_applies_when_no_scope_is_sent(): void
    {
        $this->registerRoutes(['defaults' => DefaultArgRoute::class]);
        $this->authenticate();
        $this->seedRoutes();

        $response = $this->getJson('/api/defaults');

        $response->assertOk();
        $this->assertSame(['Old'], $this->titles($response));
    }

    // ---- arguments ---------------------------------------------------------

    public function test_single_argument_binds_to_the_declared_parameter(): void
    {
        $this->registerRoutes(['routes' => ArgRoute::class]);
        $this->authenticate();
        $this->seedRoutes();

        $response = $this->getJson('/api/routes?scope[longerThan]=50');

        $response->assertOk();
        $this->assertSame(['Far'], $this->titles($response));
    }

    public function test_named_arguments_bind_in_declared_order(): void
    {
        $this->registerRoutes(['routes' => ArgRoute::class]);
        $this->authenticate();
        $this->seedRoutes();

        // Keys deliberately out of declared order: binding is by name, not position.
        $response = $this->getJson('/api/routes?scope[window][max]=30&scope[window][min]=15');

        $response->assertOk();
        $this->assertSame(['Near'], $this->titles($response));
    }

    public function test_optional_parameter_may_be_omitted(): void
    {
        $this->registerRoutes(['routes' => ArgRoute::class]);
        $this->authenticate();
        $this->seedRoutes();

        $response = $this->getJson('/api/routes?scope[titled][title]=Far');

        $response->assertOk();
        $this->assertSame(['Far'], $this->titles($response));
    }

    public function test_optional_parameter_is_used_when_sent(): void
    {
        $this->registerRoutes(['routes' => ArgRoute::class]);
        $this->authenticate();
        $this->seedRoutes();

        $response = $this->getJson('/api/routes?scope[titled][title]=Far&scope[titled][status]=archived');

        $response->assertOk();
        $this->assertSame([], $this->titles($response));
    }

    public function test_string_false_reaches_the_scope_as_a_boolean(): void
    {
        $this->registerRoutes(['routes' => ArgRoute::class]);
        $this->authenticate();
        $this->seedRoutes();

        $response = $this->getJson('/api/routes?scope[flaggedIs]=false');

        $response->assertOk();
        $this->assertSame(['Near', 'Far'], $this->titles($response));
    }

    // ---- argument errors ---------------------------------------------------

    public function test_unknown_parameter_is_rejected(): void
    {
        $this->registerRoutes(['routes' => ArgRoute::class]);
        $this->authenticate();
        $this->seedRoutes();

        $response = $this->getJson('/api/routes?scope[window][min]=1&scope[window][nope]=2');

        $response->assertStatus(403);
        $response->assertJson(['message' => "Scope 'window' does not accept parameter 'nope'"]);
    }

    public function test_missing_required_parameter_is_rejected(): void
    {
        $this->registerRoutes(['routes' => ArgRoute::class]);
        $this->authenticate();
        $this->seedRoutes();

        $response = $this->getJson('/api/routes?scope[window][min]=1');

        $response->assertStatus(403);
        $response->assertJson(['message' => "Scope 'window' requires parameter 'max'"]);
    }

    public function test_bare_value_is_rejected_for_a_multi_parameter_scope(): void
    {
        $this->registerRoutes(['routes' => ArgRoute::class]);
        $this->authenticate();
        $this->seedRoutes();

        $response = $this->getJson('/api/routes?scope[window]=1,2');

        $response->assertStatus(403);
        $response->assertJson(['message' => "Scope 'window' requires named parameters"]);
    }

    public function test_positional_list_is_rejected(): void
    {
        $this->registerRoutes(['routes' => ArgRoute::class]);
        $this->authenticate();
        $this->seedRoutes();

        $response = $this->getJson('/api/routes?scope[window][]=1&scope[window][]=2');

        $response->assertStatus(403);
        $response->assertJson(['message' => "Scope 'window' requires named parameters"]);
    }

    public function test_arguments_to_a_scope_without_parameters_are_rejected(): void
    {
        $this->registerRoutes(['routes' => ArgRoute::class]);
        $this->authenticate();
        $this->seedRoutes();

        $response = $this->getJson('/api/routes?scope[archived]=yesterday');

        $response->assertStatus(403);
        $response->assertJson(['message' => "Scope 'archived' does not accept arguments"]);
    }

    public function test_legacy_form_on_a_scope_with_required_parameters_is_rejected(): void
    {
        $this->registerRoutes(['routes' => ArgRoute::class]);
        $this->authenticate();
        $this->seedRoutes();

        $response = $this->getJson('/api/routes?scope=window');

        $response->assertStatus(403);
        $response->assertJson(['message' => "Scope 'window' requires parameter 'min'"]);
    }

    // ---- composition -------------------------------------------------------

    public function test_multiple_scopes_compose(): void
    {
        $this->registerRoutes(['routes' => ArgRoute::class]);
        $this->authenticate();
        $this->seedRoutes();

        $response = $this->getJson('/api/routes?scope[longerThan]=5&scope[titled][title]=Old');

        $response->assertOk();
        $this->assertSame(['Old'], $this->titles($response));
    }

    public function test_no_argument_scope_composes_with_an_argument_scope(): void
    {
        $this->registerRoutes(['routes' => ArgRoute::class]);
        $this->authenticate();
        $this->seedRoutes();

        $response = $this->getJson('/api/routes?scope[archived]=&scope[longerThan]=5');

        $response->assertOk();
        $this->assertSame(['Old'], $this->titles($response));
    }

    public function test_more_than_three_scopes_is_rejected(): void
    {
        $this->registerRoutes(['routes' => ArgRoute::class]);
        $this->authenticate();
        $this->seedRoutes();

        $response = $this->getJson(
            '/api/routes?scope[archived]=&scope[longerThan]=1&scope[window][min]=1&scope[window][max]=2&scope[titled][title]=x'
        );

        $response->assertStatus(403);
        $response->assertJson(['message' => 'Too many scopes requested']);
    }

    // ---- policy ------------------------------------------------------------

    public function test_policy_can_deny_a_declared_scope(): void
    {
        Gate::policy(ArgRoute::class, RestrictedScopePolicy::class);
        $this->registerRoutes(['routes' => ArgRoute::class]);
        $this->authenticate();
        $this->seedRoutes();

        $response = $this->getJson('/api/routes?scope[longerThan]=5');

        $response->assertStatus(403);
        $response->assertJson(['message' => "Scope 'longerThan' is not allowed"]);
    }

    public function test_policy_permitted_scope_still_runs(): void
    {
        Gate::policy(ArgRoute::class, RestrictedScopePolicy::class);
        $this->registerRoutes(['routes' => ArgRoute::class]);
        $this->authenticate();
        $this->seedRoutes();

        $response = $this->getJson('/api/routes?scope=archived');

        $response->assertOk();
        $this->assertSame(['Old'], $this->titles($response));
    }

    public function test_policy_without_permitted_scopes_allows_every_declared_scope(): void
    {
        $this->registerRoutes(['routes' => ArgRoute::class]);
        $this->authenticate();
        $this->seedRoutes();

        $response = $this->getJson('/api/routes?scope[longerThan]=5');

        $response->assertOk();
    }

    public function test_undeclared_scope_is_still_rejected(): void
    {
        $this->registerRoutes(['routes' => ArgRoute::class]);
        $this->authenticate();
        $this->seedRoutes();

        $response = $this->getJson('/api/routes?scope[secret]=1');

        $response->assertStatus(403);
        $response->assertJson(['message' => "Scope 'secret' is not allowed"]);
    }
}
