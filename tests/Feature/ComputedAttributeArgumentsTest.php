<?php

namespace Rhino\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
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
// Test models
// --------------------------------------------------------------------------

/**
 * Mixes every declaration form: legacy callables, legacy literals, and
 * extended specs with required, optional and multiple parameters.
 */
class ArgUser extends Model
{
    use HasValidation, HidableColumns, SoftDeletes;

    protected $table = 'arg_users';

    protected $fillable = ['status', 'first_name', 'last_name'];

    protected $validationRules = [
        'status' => 'string',
        'first_name' => 'string',
        'last_name' => 'string',
    ];

    public static $allowedFilters = ['status'];

    public function rhinoRecordComputedAttributes(): array
    {
        return [
            // Legacy: unchanged behavior.
            'full_name' => fn ($record, $user) => trim($record->first_name . ' ' . $record->last_name),
            'literal_version' => 3,
            'literal_tags' => ['a', 'b'],

            // Extended.
            'label_since' => [
                'params' => ['since'],
                'using' => fn ($record, $user, $since) => $record->first_name . '@' . $since,
            ],
            'label_window' => [
                'params' => ['from', 'to'],
                'using' => fn ($record, $user, $from, $to) => "{$from}..{$to}",
            ],
            'label_optional' => [
                'params' => ['prefix', 'suffix'],
                'optional' => ['suffix'],
                'using' => fn ($record, $user, $prefix, $suffix = '!') => $prefix . $suffix,
            ],
            'label_all_optional' => [
                'params' => ['tone'],
                'optional' => ['tone'],
                'using' => fn ($record, $user, $tone = 'plain') => 'tone:' . $tone,
            ],
            'label_flag' => [
                'params' => ['on'],
                'using' => fn ($record, $user, $on) => is_bool($on) ? 'bool:' . var_export($on, true) : 'string:' . $on,
            ],
            'secret_label' => [
                'params' => ['since'],
                'using' => fn ($record, $user, $since) => 'classified',
            ],
        ];
    }

    public static function rhinoCollectionComputedAttributes(): array
    {
        return [
            'total_count' => fn ($query, $user) => $query->count(),
            'literal_version' => 3,

            'status_count' => [
                'params' => ['status'],
                'using' => fn ($query, $user, $status) => $query->where('status', $status)->count(),
            ],
            'range_count' => [
                'params' => ['min', 'max'],
                'using' => fn ($query, $user, $min, $max) => $query->whereBetween('id', [(int) $min, (int) $max])->count(),
            ],
            'optional_count' => [
                'params' => ['status'],
                'optional' => ['status'],
                'using' => fn ($query, $user, $status = null) => $status === null
                    ? $query->count()
                    : $query->where('status', $status)->count(),
            ],
            'flag_echo' => [
                'params' => ['on'],
                'using' => fn ($query, $user, $on) => is_bool($on) ? 'bool:' . var_export($on, true) : 'string:' . $on,
            ],
            'secret_total' => [
                'params' => ['status'],
                'using' => fn ($query, $user, $status) => $query->count(),
            ],
        ];
    }
}

/** Every declaration is legacy — the backward-compatibility lock. */
class LegacyArgUser extends Model
{
    use HasValidation, HidableColumns, SoftDeletes;

    protected $table = 'arg_users';

    protected $fillable = ['status', 'first_name', 'last_name'];

    protected $validationRules = ['status' => 'string', 'first_name' => 'string'];

    public function rhinoRecordComputedAttributes(): array
    {
        return [
            'full_name' => fn ($record, $user) => trim($record->first_name . ' ' . $record->last_name),
            'version' => 3,
            'tags' => ['a', 'b'],
            'meta' => ['color' => 'red'],
        ];
    }

    public static function rhinoCollectionComputedAttributes(): array
    {
        return [
            'total_count' => fn ($query, $user) => $query->count(),
            'version' => 3,
            'tags' => ['a', 'b'],
            'meta' => ['color' => 'red'],
        ];
    }
}

/** Direct tenancy: an organization_id column of its own. */
class TenantArgUser extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'tenant_arg_users';

    protected $fillable = ['organization_id', 'status', 'first_name'];

    protected $validationRules = [
        'organization_id' => 'integer',
        'status' => 'string',
        'first_name' => 'string',
    ];

    public function rhinoRecordComputedAttributes(): array
    {
        return [
            'label_since' => [
                'params' => ['since'],
                'using' => fn ($record, $user, $since) => $record->first_name . '@' . $since,
            ],
        ];
    }

    public static function rhinoCollectionComputedAttributes(): array
    {
        return [
            'status_count' => [
                'params' => ['status'],
                'using' => fn ($query, $user, $status) => $query->where('status', $status)->count(),
            ],
            // The argument is used as a predicate, which is the realistic shape.
            // Naming another org's row must find nothing: the builder handed
            // over is already organization-scoped.
            'named_count' => [
                'params' => ['first_name'],
                'using' => fn ($query, $user, $firstName) => $query->where('first_name', $firstName)->count(),
            ],
            // Even an argument that names the tenant column directly cannot
            // reach another org, because the org scope is a separate, already
            // applied constraint on the same builder.
            'org_probe_count' => [
                'params' => ['organization_id'],
                'using' => fn ($query, $user, $organizationId) => $query
                    ->where('organization_id', $organizationId)
                    ->count(),
            ],
        ];
    }
}

/** INDIRECT tenancy: owned through ArgPost -> ArgBlog -> organization. */
class ArgBlog extends Model
{
    use HasValidation, HidableColumns, \Rhino\Traits\BelongsToOrganization;

    protected $table = 'arg_blogs';

    protected $fillable = ['organization_id', 'name'];

    protected $validationRules = ['organization_id' => 'integer', 'name' => 'string'];
}

class ArgPost extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'arg_posts';

    protected $fillable = ['blog_id', 'title'];

    protected $validationRules = ['blog_id' => 'integer', 'title' => 'string'];

    public function blog(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ArgBlog::class, 'blog_id');
    }
}

class ArgComment extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'arg_comments';

    protected $fillable = ['post_id', 'body', 'status'];

    protected $validationRules = ['post_id' => 'integer', 'body' => 'string', 'status' => 'string'];

    public function post(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(ArgPost::class, 'post_id');
    }

    public function rhinoRecordComputedAttributes(): array
    {
        return [
            'tagged_body' => [
                'params' => ['tag'],
                'using' => fn ($record, $user, $tag) => $tag . ':' . $record->body,
            ],
        ];
    }

    public static function rhinoCollectionComputedAttributes(): array
    {
        return [
            'status_count' => [
                'params' => ['status'],
                'using' => fn ($query, $user, $status) => $query->where('status', $status)->count(),
            ],
            // Naming a post that belongs to ANOTHER org's blog must count zero:
            // the owner-chain scope is already on the builder.
            'post_probe_count' => [
                'params' => ['post_id'],
                'using' => fn ($query, $user, $postId) => $query->where('post_id', $postId)->count(),
            ],
        ];
    }
}

// --------------------------------------------------------------------------
// Policies
// --------------------------------------------------------------------------

class OpenArgPolicy
{
    public function viewAny(?Authenticatable $user): bool { return true; }
    public function view(?Authenticatable $user, $model = null): bool { return true; }
    public function create(?Authenticatable $user): bool { return true; }
    public function update(?Authenticatable $user, $model = null): bool { return true; }
    public function delete(?Authenticatable $user, $model = null): bool { return true; }
    public function viewTrashed(?Authenticatable $user): bool { return true; }
    public function restore(?Authenticatable $user, $model = null): bool { return true; }
    public function forceDelete(?Authenticatable $user, $model = null): bool { return true; }
}

/** Blacklists the two "secret" parameterised attributes for everybody. */
class DenySecretArgPolicy extends ResourcePolicy implements HasPermittedAttributes
{
    public function viewAny(?Authenticatable $user): bool { return true; }
    public function view(?Authenticatable $user, $model = null): bool { return true; }
    public function viewTrashed(?Authenticatable $user): bool { return true; }

    public function hiddenAttributesForShow(?Authenticatable $user): array
    {
        return ['secret_label', 'secret_total'];
    }

    public function permittedAttributesForShow(?Authenticatable $user): array
    {
        return ['*'];
    }
}

// --------------------------------------------------------------------------
// Tests
// --------------------------------------------------------------------------

class ComputedAttributeArgumentsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('arg_users', function (Blueprint $table) {
            $table->id();
            $table->string('status')->default('active');
            $table->string('first_name')->nullable();
            $table->string('last_name')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('tenant_arg_users', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('status')->default('active');
            $table->string('first_name')->nullable();
            $table->timestamps();
        });

        Schema::create('arg_blogs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id');
            $table->string('name');
            $table->timestamps();
        });

        Schema::create('arg_posts', function (Blueprint $table) {
            $table->id();
            $table->foreignId('blog_id');
            $table->string('title');
            $table->timestamps();
        });

        Schema::create('arg_comments', function (Blueprint $table) {
            $table->id();
            $table->foreignId('post_id');
            $table->string('body');
            $table->string('status')->default('ok');
            $table->timestamps();
        });

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        Gate::policy(ArgUser::class, DenySecretArgPolicy::class);
        Gate::policy(LegacyArgUser::class, OpenArgPolicy::class);
        Gate::policy(TenantArgUser::class, OpenArgPolicy::class);
        Gate::policy(ArgBlog::class, OpenArgPolicy::class);
        Gate::policy(ArgPost::class, OpenArgPolicy::class);
        Gate::policy(ArgComment::class, OpenArgPolicy::class);
        Gate::policy(\App\Models\Organization::class, OpenArgPolicy::class);

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
        parent::tearDown();
    }

    protected function getEnvironmentSetUp($app): void
    {
        parent::getEnvironmentSetUp($app);

        $app['config']->set('auth.guards.sanctum', ['driver' => 'session', 'provider' => 'users']);
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
            'rhino.multi_tenant' => ['organization_identifier_column' => 'id'],
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
            'rhino.multi_tenant' => ['organization_identifier_column' => 'slug'],
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

    /** @return array{0: \App\Models\User, 1: \App\Models\Organization} */
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

    protected function seedArgUsers(): void
    {
        ArgUser::forceCreate(['status' => 'active', 'first_name' => 'Ada', 'last_name' => 'Lovelace']);
        ArgUser::forceCreate(['status' => 'active', 'first_name' => 'Alan', 'last_name' => 'Turing']);
        ArgUser::forceCreate(['status' => 'blocked', 'first_name' => 'Mal', 'last_name' => 'Ware']);
        ArgUser::forceCreate(['status' => 'pending', 'first_name' => 'Pat', 'last_name' => 'Ending']);
    }

    // ======================================================================
    // /computed — the four wire forms
    // ======================================================================

    public function test_form_a_legacy_list_is_unchanged(): void
    {
        $this->registerRoutes(['users' => ArgUser::class]);
        $this->authenticate();
        $this->seedArgUsers();

        $response = $this->getJson('/api/users/computed?attributes=total_count,literal_version');

        $response->assertStatus(200);
        $this->assertSame(['total_count' => 4, 'literal_version' => 3], $response->json('data'));
    }

    public function test_form_b_bracket_with_no_arguments(): void
    {
        $this->registerRoutes(['users' => ArgUser::class]);
        $this->authenticate();
        $this->seedArgUsers();

        $response = $this->getJson('/api/users/computed?attributes[total_count]=');

        $response->assertStatus(200);
        $this->assertSame(['total_count' => 4], $response->json('data'));
    }

    public function test_form_c_bare_value_binds_the_single_parameter(): void
    {
        $this->registerRoutes(['users' => ArgUser::class]);
        $this->authenticate();
        $this->seedArgUsers();

        $response = $this->getJson('/api/users/computed?attributes[status_count]=active');

        $response->assertStatus(200);
        $this->assertSame(['status_count' => 2], $response->json('data'));
    }

    public function test_form_d_named_arguments(): void
    {
        $this->registerRoutes(['users' => ArgUser::class]);
        $this->authenticate();
        $this->seedArgUsers();

        $response = $this->getJson('/api/users/computed?attributes[range_count][min]=1&attributes[range_count][max]=2');

        $response->assertStatus(200);
        $this->assertSame(['range_count' => 2], $response->json('data'));
    }

    public function test_named_arguments_bind_by_name_not_by_position(): void
    {
        $this->registerRoutes(['users' => ArgUser::class]);
        $this->authenticate();
        $this->seedArgUsers();

        $response = $this->getJson('/api/users/computed?attributes[range_count][max]=3&attributes[range_count][min]=2');

        $response->assertStatus(200);
        $this->assertSame(['range_count' => 2], $response->json('data'));
    }

    public function test_forms_b_c_and_d_combine_in_one_request(): void
    {
        $this->registerRoutes(['users' => ArgUser::class]);
        $this->authenticate();
        $this->seedArgUsers();

        $response = $this->getJson(
            '/api/users/computed?attributes[total_count]=&attributes[status_count]=blocked'
            . '&attributes[range_count][min]=1&attributes[range_count][max]=4'
        );

        $response->assertStatus(200);
        $this->assertSame(
            ['total_count' => 4, 'status_count' => 1, 'range_count' => 4],
            $response->json('data')
        );
    }

    public function test_optional_parameter_may_be_omitted(): void
    {
        $this->registerRoutes(['users' => ArgUser::class]);
        $this->authenticate();
        $this->seedArgUsers();

        $omitted = $this->getJson('/api/users/computed?attributes[optional_count]=');
        $given = $this->getJson('/api/users/computed?attributes[optional_count]=active');

        $omitted->assertStatus(200);
        $this->assertSame(4, $omitted->json('data.optional_count'));
        $given->assertStatus(200);
        $this->assertSame(2, $given->json('data.optional_count'));
    }

    public function test_true_and_false_arrive_as_real_booleans(): void
    {
        $this->registerRoutes(['users' => ArgUser::class]);
        $this->authenticate();

        $this->assertSame(
            'bool:true',
            $this->getJson('/api/users/computed?attributes[flag_echo]=true')->json('data.flag_echo')
        );
        $this->assertSame(
            'bool:false',
            $this->getJson('/api/users/computed?attributes[flag_echo]=false')->json('data.flag_echo')
        );
        $this->assertSame(
            'string:yes',
            $this->getJson('/api/users/computed?attributes[flag_echo]=yes')->json('data.flag_echo')
        );
    }

    public function test_each_parameterised_callable_gets_its_own_clone(): void
    {
        $this->registerRoutes(['users' => ArgUser::class]);
        $this->authenticate();
        $this->seedArgUsers();

        $response = $this->getJson(
            '/api/users/computed?attributes[status_count]=active&attributes[total_count]='
        );

        $response->assertStatus(200);
        $this->assertSame(['status_count' => 2, 'total_count' => 4], $response->json('data'));
    }

    // ======================================================================
    // /computed — the 403 contract, verbatim
    // ======================================================================

    public function test_undeclared_attribute_in_bracket_form_is_not_allowed(): void
    {
        $this->registerRoutes(['users' => ArgUser::class]);
        $this->authenticate();

        $response = $this->getJson('/api/users/computed?attributes[nope][x]=1');

        $response->assertStatus(403);
        $response->assertExactJson(['message' => "Computed attribute 'nope' is not allowed"]);
    }

    public function test_policy_denied_attribute_returns_the_same_string_as_an_undeclared_one(): void
    {
        $this->registerRoutes(['users' => ArgUser::class]);
        $this->authenticate();

        $denied = $this->getJson('/api/users/computed?attributes[secret_total]=active');
        $undeclared = $this->getJson('/api/users/computed?attributes[ghost_total]=active');

        $denied->assertStatus(403);
        $undeclared->assertStatus(403);
        $this->assertSame(
            "Computed attribute 'secret_total' is not allowed",
            $denied->json('message')
        );
        $this->assertSame(
            "Computed attribute 'ghost_total' is not allowed",
            $undeclared->json('message')
        );
    }

    public function test_the_gate_runs_before_argument_binding(): void
    {
        $this->registerRoutes(['users' => ArgUser::class]);
        $this->authenticate();

        // A denied attribute given a BAD argument must still return the gate
        // message: the specific argument errors must leak nothing.
        $response = $this->getJson('/api/users/computed?attributes[secret_total][bogus]=1');

        $response->assertStatus(403);
        $response->assertExactJson(['message' => "Computed attribute 'secret_total' is not allowed"]);
    }

    public function test_missing_required_parameter(): void
    {
        $this->registerRoutes(['users' => ArgUser::class]);
        $this->authenticate();

        $response = $this->getJson('/api/users/computed?attributes[range_count][min]=1');

        $response->assertStatus(403);
        $response->assertExactJson(['message' => "Computed attribute 'range_count' requires parameter 'max'"]);
    }

    public function test_required_parameter_missing_entirely_in_bracket_form(): void
    {
        $this->registerRoutes(['users' => ArgUser::class]);
        $this->authenticate();

        $response = $this->getJson('/api/users/computed?attributes[status_count]=');

        $response->assertStatus(403);
        $response->assertExactJson(['message' => "Computed attribute 'status_count' requires parameter 'status'"]);
    }

    public function test_required_parameter_missing_in_the_legacy_list_form(): void
    {
        $this->registerRoutes(['users' => ArgUser::class]);
        $this->authenticate();

        $response = $this->getJson('/api/users/computed?attributes=status_count');

        $response->assertStatus(403);
        $response->assertExactJson(['message' => "Computed attribute 'status_count' requires parameter 'status'"]);
    }

    public function test_unknown_parameter_name(): void
    {
        $this->registerRoutes(['users' => ArgUser::class]);
        $this->authenticate();

        $response = $this->getJson('/api/users/computed?attributes[status_count][nope]=1');

        $response->assertStatus(403);
        $response->assertExactJson(['message' => "Computed attribute 'status_count' does not accept parameter 'nope'"]);
    }

    public function test_bare_value_for_a_multi_parameter_attribute(): void
    {
        $this->registerRoutes(['users' => ArgUser::class]);
        $this->authenticate();

        $response = $this->getJson('/api/users/computed?attributes[range_count]=1');

        $response->assertStatus(403);
        $response->assertExactJson(['message' => "Computed attribute 'range_count' requires named parameters"]);
    }

    public function test_positional_argument_list(): void
    {
        $this->registerRoutes(['users' => ArgUser::class]);
        $this->authenticate();

        $response = $this->getJson('/api/users/computed?attributes[range_count][]=1');

        $response->assertStatus(403);
        $response->assertExactJson(['message' => "Computed attribute 'range_count' requires named parameters"]);
    }

    public function test_nested_argument_value(): void
    {
        $this->registerRoutes(['users' => ArgUser::class]);
        $this->authenticate();

        $response = $this->getJson('/api/users/computed?attributes[range_count][min][deep]=1');

        $response->assertStatus(403);
        $response->assertExactJson(['message' => "Computed attribute 'range_count' requires named parameters"]);
    }

    public function test_arguments_sent_to_a_parameterless_attribute(): void
    {
        $this->registerRoutes(['users' => ArgUser::class]);
        $this->authenticate();

        $response = $this->getJson('/api/users/computed?attributes[total_count]=5');

        $response->assertStatus(403);
        $response->assertExactJson(['message' => "Computed attribute 'total_count' does not accept arguments"]);
    }

    public function test_named_arguments_sent_to_a_parameterless_attribute(): void
    {
        $this->registerRoutes(['users' => ArgUser::class]);
        $this->authenticate();

        $response = $this->getJson('/api/users/computed?attributes[total_count][x]=5');

        $response->assertStatus(403);
        $response->assertExactJson(['message' => "Computed attribute 'total_count' does not accept arguments"]);
    }

    public function test_positional_attribute_list_is_structurally_invalid(): void
    {
        $this->registerRoutes(['users' => ArgUser::class]);
        $this->authenticate();

        $response = $this->getJson('/api/users/computed?attributes[]=total_count');

        $response->assertStatus(403);
        $response->assertExactJson(['message' => 'Computed attributes are not allowed']);
    }

    public function test_integer_attribute_key_is_structurally_invalid(): void
    {
        $this->registerRoutes(['users' => ArgUser::class]);
        $this->authenticate();

        $response = $this->getJson('/api/users/computed?attributes[0]=total_count');

        $response->assertStatus(403);
        $response->assertExactJson(['message' => 'Computed attributes are not allowed']);
    }

    // ======================================================================
    // /computed — the bare-call skip rule
    // ======================================================================

    public function test_bare_computed_skips_required_parameter_attributes(): void
    {
        $this->registerRoutes(['users' => ArgUser::class]);
        $this->authenticate();
        $this->seedArgUsers();

        $response = $this->getJson('/api/users/computed');

        $response->assertStatus(200);
        // total_count and literal_version take nothing; optional_count's only
        // parameter is optional. status_count / range_count / flag_echo declare
        // a required parameter and are skipped, NOT 403'd. secret_total is
        // hidden by the policy.
        $this->assertSame(
            ['total_count', 'literal_version', 'optional_count'],
            array_keys($response->json('data'))
        );
        $this->assertSame(4, $response->json('data.optional_count'));
    }

    public function test_empty_attributes_parameter_behaves_like_a_bare_call(): void
    {
        $this->registerRoutes(['users' => ArgUser::class]);
        $this->authenticate();
        $this->seedArgUsers();

        $response = $this->getJson('/api/users/computed?attributes=');

        $response->assertStatus(200);
        $this->assertSame(
            ['total_count', 'literal_version', 'optional_count'],
            array_keys($response->json('data'))
        );
    }

    // ======================================================================
    // index / show / trashed — ?computed_attributes=
    // ======================================================================

    public function test_index_accepts_bracket_arguments(): void
    {
        $this->registerRoutes(['users' => ArgUser::class]);
        $this->authenticate();
        $this->seedArgUsers();

        $response = $this->getJson('/api/users?computed_attributes[label_since]=2026-01-01');

        $response->assertStatus(200);
        $this->assertSame('Ada@2026-01-01', $response->json('data.0.label_since'));
    }

    public function test_index_accepts_named_arguments(): void
    {
        $this->registerRoutes(['users' => ArgUser::class]);
        $this->authenticate();
        $this->seedArgUsers();

        $response = $this->getJson(
            '/api/users?computed_attributes[label_window][from]=a&computed_attributes[label_window][to]=b'
        );

        $response->assertStatus(200);
        $this->assertSame('a..b', $response->json('data.0.label_window'));
    }

    public function test_index_combines_legacy_and_parameterised_attributes(): void
    {
        $this->registerRoutes(['users' => ArgUser::class]);
        $this->authenticate();
        $this->seedArgUsers();

        $response = $this->getJson(
            '/api/users?computed_attributes[full_name]=&computed_attributes[label_since]=2026-01-01'
        );

        $response->assertStatus(200);
        $this->assertSame('Ada Lovelace', $response->json('data.0.full_name'));
        $this->assertSame('Ada@2026-01-01', $response->json('data.0.label_since'));
    }

    public function test_index_drops_an_omitted_optional_argument_to_the_callable_default(): void
    {
        $this->registerRoutes(['users' => ArgUser::class]);
        $this->authenticate();
        $this->seedArgUsers();

        $response = $this->getJson('/api/users?computed_attributes[label_optional][prefix]=hi');

        $response->assertStatus(200);
        // 'suffix' was omitted, so the trailing null is dropped and the
        // callable's own default applies.
        $this->assertSame('hi!', $response->json('data.0.label_optional'));
    }

    public function test_index_evaluates_an_all_optional_attribute_with_no_arguments(): void
    {
        $this->registerRoutes(['users' => ArgUser::class]);
        $this->authenticate();
        $this->seedArgUsers();

        $response = $this->getJson('/api/users?computed_attributes=label_all_optional');

        $response->assertStatus(200);
        $this->assertSame('tone:plain', $response->json('data.0.label_all_optional'));
    }

    public function test_a_bare_value_for_a_multi_parameter_record_attribute_is_refused(): void
    {
        $this->registerRoutes(['users' => ArgUser::class]);
        $this->authenticate();
        $this->seedArgUsers();

        $response = $this->getJson('/api/users?computed_attributes[label_optional]=hi');

        $response->assertStatus(403);
        $response->assertExactJson(['message' => "Computed attribute 'label_optional' requires named parameters"]);
    }

    public function test_index_binds_a_given_optional_argument(): void
    {
        $this->registerRoutes(['users' => ArgUser::class]);
        $this->authenticate();
        $this->seedArgUsers();

        $response = $this->getJson(
            '/api/users?computed_attributes[label_optional][prefix]=hi&computed_attributes[label_optional][suffix]=?'
        );

        $response->assertStatus(200);
        $this->assertSame('hi?', $response->json('data.0.label_optional'));
    }

    public function test_index_coerces_booleans(): void
    {
        $this->registerRoutes(['users' => ArgUser::class]);
        $this->authenticate();
        $this->seedArgUsers();

        $response = $this->getJson('/api/users?computed_attributes[label_flag]=TRUE');

        $response->assertStatus(200);
        $this->assertSame('bool:true', $response->json('data.0.label_flag'));
    }

    public function test_show_accepts_bracket_arguments(): void
    {
        $this->registerRoutes(['users' => ArgUser::class]);
        $this->authenticate();
        $this->seedArgUsers();

        $id = ArgUser::where('first_name', 'Alan')->value('id');

        $response = $this->getJson("/api/users/{$id}?computed_attributes[label_since]=2026-02-02");

        $response->assertStatus(200);
        $this->assertSame('Alan@2026-02-02', $response->json('label_since'));
    }

    public function test_trashed_accepts_bracket_arguments(): void
    {
        $this->registerRoutes(['users' => ArgUser::class]);
        $this->authenticate();
        $this->seedArgUsers();

        ArgUser::where('first_name', 'Mal')->first()->delete();

        $response = $this->getJson('/api/users/trashed?computed_attributes[label_since]=2026-03-03');

        $response->assertStatus(200);
        $this->assertCount(1, $response->json('data'));
        $this->assertSame('Mal@2026-03-03', $response->json('data.0.label_since'));
    }

    public function test_index_gate_runs_before_argument_binding(): void
    {
        $this->registerRoutes(['users' => ArgUser::class]);
        $this->authenticate();
        $this->seedArgUsers();

        $response = $this->getJson('/api/users?computed_attributes[secret_label][bogus]=1');

        $response->assertStatus(403);
        $response->assertExactJson(['message' => "Computed attribute 'secret_label' is not allowed"]);
    }

    public function test_index_reports_a_missing_required_argument(): void
    {
        $this->registerRoutes(['users' => ArgUser::class]);
        $this->authenticate();
        $this->seedArgUsers();

        $response = $this->getJson('/api/users?computed_attributes=label_window');

        $response->assertStatus(403);
        $response->assertExactJson(['message' => "Computed attribute 'label_window' requires parameter 'from'"]);
    }

    public function test_index_rejects_a_positional_attribute_list(): void
    {
        $this->registerRoutes(['users' => ArgUser::class]);
        $this->authenticate();
        $this->seedArgUsers();

        $response = $this->getJson('/api/users?computed_attributes[]=full_name');

        $response->assertStatus(403);
        $response->assertExactJson(['message' => 'Computed attributes are not allowed']);
    }

    public function test_show_rejects_an_unknown_parameter(): void
    {
        $this->registerRoutes(['users' => ArgUser::class]);
        $this->authenticate();
        $this->seedArgUsers();

        $id = ArgUser::first()->id;

        $response = $this->getJson("/api/users/{$id}?computed_attributes[label_since][nope]=x");

        $response->assertStatus(403);
        $response->assertExactJson(['message' => "Computed attribute 'label_since' does not accept parameter 'nope'"]);
    }

    // ======================================================================
    // Backward-compatibility lock — an all-legacy model
    // ======================================================================

    public function test_legacy_model_computed_endpoint_is_byte_identical(): void
    {
        $this->registerRoutes(['legacy' => LegacyArgUser::class]);
        $this->authenticate();
        $this->seedArgUsers();

        $bare = $this->getJson('/api/legacy/computed');
        $named = $this->getJson('/api/legacy/computed?attributes=total_count,version,tags,meta');

        $bare->assertStatus(200);
        $named->assertStatus(200);

        $expected = [
            'total_count' => 4,
            'version' => 3,
            'tags' => ['a', 'b'],
            'meta' => ['color' => 'red'],
        ];

        $this->assertSame($expected, $bare->json('data'));
        $this->assertSame($expected, $named->json('data'));
    }

    public function test_legacy_model_record_attributes_are_byte_identical(): void
    {
        $this->registerRoutes(['legacy' => LegacyArgUser::class]);
        $this->authenticate();
        $this->seedArgUsers();

        $response = $this->getJson('/api/legacy?computed_attributes=full_name,version,tags,meta');

        $response->assertStatus(200);
        $row = $response->json('data.0');
        $this->assertSame('Ada Lovelace', $row['full_name']);
        $this->assertSame(3, $row['version']);
        $this->assertSame(['a', 'b'], $row['tags']);
        $this->assertSame(['color' => 'red'], $row['meta']);
    }

    public function test_legacy_model_without_the_parameter_evaluates_nothing(): void
    {
        $this->registerRoutes(['legacy' => LegacyArgUser::class]);
        $this->authenticate();
        $this->seedArgUsers();

        $response = $this->getJson('/api/legacy');

        $response->assertStatus(200);
        $this->assertArrayNotHasKey('full_name', $response->json('data.0'));
        $this->assertArrayNotHasKey('version', $response->json('data.0'));
    }

    // ======================================================================
    // Direct-call safety — asRhinoJson with names only
    // ======================================================================

    public function test_direct_serialization_skips_required_parameter_attributes(): void
    {
        $this->seedArgUsers();
        $record = ArgUser::first();

        $json = $record->asRhinoJson(null, ['full_name', 'label_since', 'label_optional', 'label_all_optional']);

        $this->assertSame('Ada Lovelace', $json['full_name']);
        // Both declare a REQUIRED parameter and got nothing: skipped, not fatal.
        $this->assertArrayNotHasKey('label_since', $json);
        $this->assertArrayNotHasKey('label_optional', $json);
        // label_all_optional's only parameter is optional: evaluated with none.
        $this->assertSame('tone:plain', $json['label_all_optional']);
    }

    public function test_direct_serialization_accepts_an_arguments_channel(): void
    {
        $this->seedArgUsers();
        $record = ArgUser::first();

        $json = $record->asRhinoJson(null, ['label_since'], ['label_since' => ['2026-01-01']]);

        $this->assertSame('Ada@2026-01-01', $json['label_since']);
    }

    public function test_direct_serialization_still_applies_the_policy_blacklist(): void
    {
        $this->seedArgUsers();
        Gate::policy(ArgUser::class, DenySecretArgPolicy::class);
        $user = $this->authenticate();
        $record = ArgUser::first();

        $json = $record->asRhinoJson($user, ['secret_label'], ['secret_label' => ['2026-01-01']]);

        // Merged before the blacklist, so the blacklist still removes it.
        $this->assertArrayNotHasKey('secret_label', $json);
    }

    // ======================================================================
    // Multi-tenancy — direct and indirect ownership
    // ======================================================================

    public function test_parameterised_aggregate_is_scoped_to_the_org_direct(): void
    {
        $this->registerTenantRoutes(['tusers' => TenantArgUser::class]);
        [$user, $org] = $this->createUserInOrg('acme');
        $other = \App\Models\Organization::firstOrCreate(['slug' => 'other'], ['name' => 'Other', 'domain' => null]);

        TenantArgUser::forceCreate(['organization_id' => $org->id, 'status' => 'active', 'first_name' => 'Mine']);
        TenantArgUser::forceCreate(['organization_id' => $other->id, 'status' => 'active', 'first_name' => 'Theirs']);
        TenantArgUser::forceCreate(['organization_id' => $other->id, 'status' => 'active', 'first_name' => 'Theirs2']);

        $response = $this->getJson('/api/acme/tusers/computed?attributes[status_count]=active');

        $response->assertStatus(200);
        $this->assertSame(1, $response->json('data.status_count'));
    }

    public function test_an_argument_value_cannot_surface_another_orgs_rows_direct(): void
    {
        $this->registerTenantRoutes(['tusers' => TenantArgUser::class]);
        [$user, $org] = $this->createUserInOrg('acme');
        $other = \App\Models\Organization::firstOrCreate(['slug' => 'other'], ['name' => 'Other', 'domain' => null]);

        TenantArgUser::forceCreate(['organization_id' => $org->id, 'status' => 'active', 'first_name' => 'Mine']);
        TenantArgUser::forceCreate(['organization_id' => $other->id, 'status' => 'active', 'first_name' => 'Theirs']);

        $mine = $this->getJson('/api/acme/tusers/computed?attributes[named_count]=Mine');
        $theirs = $this->getJson('/api/acme/tusers/computed?attributes[named_count]=Theirs');

        $mine->assertStatus(200);
        $theirs->assertStatus(200);
        $this->assertSame(1, $mine->json('data.named_count'));
        $this->assertSame(0, $theirs->json('data.named_count'));
    }

    public function test_a_client_supplied_organization_id_argument_reaches_nothing(): void
    {
        $this->registerTenantRoutes(['tusers' => TenantArgUser::class]);
        [$user, $org] = $this->createUserInOrg('acme');
        $other = \App\Models\Organization::firstOrCreate(['slug' => 'other'], ['name' => 'Other', 'domain' => null]);

        TenantArgUser::forceCreate(['organization_id' => $org->id, 'status' => 'active', 'first_name' => 'Mine']);
        TenantArgUser::forceCreate(['organization_id' => $other->id, 'status' => 'active', 'first_name' => 'Theirs']);

        $response = $this->getJson('/api/acme/tusers/computed?attributes[org_probe_count]=' . $other->id);

        $response->assertStatus(200);
        $this->assertSame(0, $response->json('data.org_probe_count'));
    }

    public function test_parameterised_record_attribute_never_leaks_another_org(): void
    {
        $this->registerTenantRoutes(['tusers' => TenantArgUser::class]);
        [$user, $org] = $this->createUserInOrg('acme');
        $other = \App\Models\Organization::firstOrCreate(['slug' => 'other'], ['name' => 'Other', 'domain' => null]);

        TenantArgUser::forceCreate(['organization_id' => $org->id, 'status' => 'active', 'first_name' => 'Mine']);
        TenantArgUser::forceCreate(['organization_id' => $other->id, 'status' => 'active', 'first_name' => 'Theirs']);

        $response = $this->getJson('/api/acme/tusers?computed_attributes[label_since]=2026-01-01');

        $response->assertStatus(200);
        $this->assertSame(['Mine@2026-01-01'], array_column($response->json('data'), 'label_since'));
    }

    /** @return array{0: \App\Models\Organization, 1: \App\Models\Organization} */
    protected function seedIndirect(): array
    {
        [$user, $org] = $this->createUserInOrg('acme');
        $other = \App\Models\Organization::firstOrCreate(['slug' => 'other'], ['name' => 'Other', 'domain' => null]);

        $mineBlog = ArgBlog::forceCreate(['organization_id' => $org->id, 'name' => 'Mine']);
        $theirBlog = ArgBlog::forceCreate(['organization_id' => $other->id, 'name' => 'Theirs']);

        $minePost = ArgPost::forceCreate(['blog_id' => $mineBlog->id, 'title' => 'Mine']);
        $theirPost = ArgPost::forceCreate(['blog_id' => $theirBlog->id, 'title' => 'Theirs']);

        ArgComment::forceCreate(['post_id' => $minePost->id, 'body' => 'mine a', 'status' => 'ok']);
        ArgComment::forceCreate(['post_id' => $minePost->id, 'body' => 'mine b', 'status' => 'flagged']);
        ArgComment::forceCreate(['post_id' => $theirPost->id, 'body' => 'their a', 'status' => 'flagged']);
        ArgComment::forceCreate(['post_id' => $theirPost->id, 'body' => 'their b', 'status' => 'flagged']);
        ArgComment::forceCreate(['post_id' => $theirPost->id, 'body' => 'their c', 'status' => 'ok']);

        return [$org, $other];
    }

    public function test_parameterised_aggregate_is_scoped_through_the_owner_chain(): void
    {
        $this->registerTenantRoutes(['icomments' => ArgComment::class]);
        $this->seedIndirect();

        $response = $this->getJson('/api/acme/icomments/computed?attributes[status_count]=flagged');

        $response->assertStatus(200);
        // 3 flagged rows exist; only the 1 reachable through acme's blog counts.
        $this->assertSame(1, $response->json('data.status_count'));
    }

    public function test_an_argument_value_cannot_reach_across_the_owner_chain(): void
    {
        $this->registerTenantRoutes(['icomments' => ArgComment::class]);
        $this->seedIndirect();

        $minePostId = ArgPost::where('title', 'Mine')->value('id');
        $theirPostId = ArgPost::where('title', 'Theirs')->value('id');

        $mine = $this->getJson('/api/acme/icomments/computed?attributes[post_probe_count]=' . $minePostId);
        $theirs = $this->getJson('/api/acme/icomments/computed?attributes[post_probe_count]=' . $theirPostId);

        $mine->assertStatus(200);
        $theirs->assertStatus(200);
        $this->assertSame(2, $mine->json('data.post_probe_count'));
        // Three comments hang off that post; none is reachable from acme.
        $this->assertSame(0, $theirs->json('data.post_probe_count'));
    }

    public function test_parameterised_record_attribute_is_scoped_through_the_owner_chain(): void
    {
        $this->registerTenantRoutes(['icomments' => ArgComment::class]);
        $this->seedIndirect();

        $response = $this->getJson('/api/acme/icomments?computed_attributes[tagged_body]=x');

        $response->assertStatus(200);
        $bodies = array_column($response->json('data'), 'tagged_body');
        sort($bodies);
        $this->assertSame(['x:mine a', 'x:mine b'], $bodies);
    }

    public function test_arguments_do_not_change_which_rows_index_returns(): void
    {
        $this->registerTenantRoutes(['icomments' => ArgComment::class]);
        $this->seedIndirect();

        $plain = $this->getJson('/api/acme/icomments');
        $withArgs = $this->getJson('/api/acme/icomments?computed_attributes[tagged_body]=x');

        $this->assertSame(count($plain->json('data')), count($withArgs->json('data')));
    }
}
