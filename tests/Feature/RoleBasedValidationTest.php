<?php

namespace Rhino\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Rhino\Contracts\HasRoleBasedValidation;
use Rhino\Tests\TestCase;
use Rhino\Traits\HasValidation;
use Rhino\Traits\HidableColumns;

// --------------------------------------------------------------------------
// Test models
// --------------------------------------------------------------------------

/** Legacy format: flat array of field names (backwards compatible). */
class LegacyRoleModel extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'legacy_role_table';

    protected $fillable = ['title', 'content'];

    protected $validationRules = [
        'title' => 'required|string|max:255',
        'content' => 'required|string',
    ];

    protected $validationRulesStore = ['title', 'content'];
    protected $validationRulesUpdate = ['title', 'content'];
}

/** Role-keyed format: per-role fields and presence. Uses integer for blog_id in tests to avoid DB. */
class RoleTestPost extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'role_test_posts';

    protected $fillable = ['blog_id', 'title', 'content', 'is_published'];

    protected $validationRules = [
        'blog_id' => 'integer',
        'title' => 'string|max:255',
        'content' => 'string',
        'is_published' => 'boolean',
    ];

    protected $validationRulesStore = [
        'admin' => [
            'blog_id' => 'required',
            'title' => 'required',
            'content' => 'required',
            'is_published' => 'nullable',
        ],
        'assistant' => [
            'title' => 'required',
            'content' => 'required',
        ],
        '*' => [
            'title' => 'required',
            'content' => 'required',
        ],
    ];

    protected $validationRulesUpdate = [
        'admin' => [
            'title' => 'sometimes',
            'content' => 'sometimes',
            'is_published' => 'nullable',
        ],
        'assistant' => [
            'title' => 'sometimes',
            'content' => 'sometimes',
        ],
        '*' => [
            'title' => 'sometimes',
            'content' => 'sometimes',
        ],
    ];
}

/** Role-keyed with full rule override (value contains |). */
class RoleTestPostWithOverride extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'role_test_posts_override';

    protected $fillable = ['title'];

    protected $validationRules = [
        'title' => 'string|max:255',
    ];

    protected $validationRulesStore = [
        'admin' => [
            'title' => 'required|string|max:500',
        ],
    ];
}

/** Mock user that returns a fixed role slug for validation. */
class MockRoleUser extends Model implements Authenticatable, HasRoleBasedValidation
{
    protected $table = 'users';

    public $roleSlug;

    public function __construct($roleSlug = null)
    {
        parent::__construct([]);
        $this->roleSlug = $roleSlug;
    }

    public function getRoleSlugForValidation($organization): ?string
    {
        return $this->roleSlug;
    }

    public function getAuthIdentifierName()
    {
        return 'id';
    }

    public function getAuthIdentifier()
    {
        return $this->id ?? 1;
    }

    public function getAuthPassword()
    {
        return '';
    }

    public function getAuthPasswordName()
    {
        return 'password';
    }

    public function getRememberToken()
    {
        return null;
    }

    public function setRememberToken($value) {}

    public function getRememberTokenName()
    {
        return null;
    }
}

class RoleTestPostPolicy
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

class RoleBasedValidationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('legacy_role_table', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content')->nullable();
            $table->timestamps();
        });

        Schema::create('role_test_posts', function (Blueprint $table) {
            $table->id();
            $table->unsignedBigInteger('blog_id')->nullable();
            $table->string('title');
            $table->text('content')->nullable();
            $table->boolean('is_published')->default(false);
            $table->timestamps();
        });

        Schema::create('role_test_posts_override', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->timestamps();
        });

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        Gate::policy(RoleTestPost::class, RoleTestPostPolicy::class);
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

    public function test_legacy_flat_array_uses_static_rules(): void
    {
        $model = new LegacyRoleModel;
        $request = Request::create('', 'POST', [
            'title' => 'A title',
            'content' => 'Some content',
        ]);

        $validator = $model->validateStore($request);
        $this->assertFalse($validator->fails());
        $validated = $validator->validated();
        $this->assertArrayHasKey('title', $validated);
        $this->assertArrayHasKey('content', $validated);
    }

    public function test_legacy_flat_array_fails_when_required_missing(): void
    {
        $model = new LegacyRoleModel;
        $request = Request::create('', 'POST', ['title' => 'Only title']);

        $validator = $model->validateStore($request);
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('content', $validator->errors()->toArray());
    }

    public function test_role_keyed_admin_receives_all_fields_in_validated(): void
    {
        $user = new MockRoleUser('admin');
        Auth::guard('sanctum')->setUser($user);

        $model = new RoleTestPost;
        $request = Request::create('', 'POST', [
            'blog_id' => 1,
            'title' => 'Post title',
            'content' => 'Content',
            'is_published' => true,
        ]);

        $validator = $model->validateStore($request);
        $this->assertFalse($validator->fails(), $validator->errors()->toJson());
        $validated = $validator->validated();
        $this->assertArrayHasKey('blog_id', $validated);
        $this->assertArrayHasKey('title', $validated);
        $this->assertArrayHasKey('content', $validated);
        $this->assertArrayHasKey('is_published', $validated);
    }

    public function test_role_keyed_assistant_receives_only_title_and_content_in_validated(): void
    {
        $user = new MockRoleUser('assistant');
        Auth::guard('sanctum')->setUser($user);

        $model = new RoleTestPost;
        $request = Request::create('', 'POST', [
            'blog_id' => 1,
            'title' => 'Post title',
            'content' => 'Content',
            'is_published' => true,
        ]);

        $validator = $model->validateStore($request);
        $this->assertFalse($validator->fails());
        $validated = $validator->validated();
        $this->assertArrayNotHasKey('blog_id', $validated);
        $this->assertArrayNotHasKey('is_published', $validated);
        $this->assertArrayHasKey('title', $validated);
        $this->assertArrayHasKey('content', $validated);
    }

    public function test_role_keyed_wildcard_fallback_used_when_role_unknown(): void
    {
        $user = new MockRoleUser('unknown_role');
        Auth::guard('sanctum')->setUser($user);

        $model = new RoleTestPost;
        $request = Request::create('', 'POST', [
            'title' => 'Post title',
            'content' => 'Content',
        ]);

        $validator = $model->validateStore($request);
        $this->assertFalse($validator->fails());
        $validated = $validator->validated();
        $this->assertArrayHasKey('title', $validated);
        $this->assertArrayHasKey('content', $validated);
        $this->assertArrayNotHasKey('blog_id', $validated);
    }

    public function test_role_keyed_no_match_and_no_wildcard_returns_empty_validated(): void
    {
        $modelWithNoWildcard = new class extends Model {
            use HasValidation, HidableColumns;
            protected $table = 'role_test_posts';
            protected $fillable = ['title'];
            protected $validationRules = ['title' => 'string|max:255'];
            protected $validationRulesStore = [
                'admin' => ['title' => 'required'],
            ];
            protected $validationRulesUpdate = [];
        };

        $user = new MockRoleUser('assistant');
        Auth::guard('sanctum')->setUser($user);

        $request = Request::create('', 'POST', ['title' => 'Any']);
        $validator = $modelWithNoWildcard->validateStore($request);
        $validated = $validator->validated();
        $this->assertSame([], $validated);
    }

    public function test_presence_merging_produces_required_plus_base_format(): void
    {
        $user = new MockRoleUser('assistant');
        Auth::guard('sanctum')->setUser($user);

        $model = new RoleTestPost;
        $request = Request::create('', 'POST', [
            'title' => '',
            'content' => 'Content',
        ]);

        $validator = $model->validateStore($request);
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('title', $validator->errors()->toArray());
    }

    public function test_full_rule_override_replaces_base(): void
    {
        $user = new MockRoleUser('admin');
        Auth::guard('sanctum')->setUser($user);

        $model = new RoleTestPostWithOverride;
        $request = Request::create('', 'POST', [
            'title' => str_repeat('a', 400),
        ]);

        $validator = $model->validateStore($request);
        $this->assertFalse($validator->fails());
    }

    public function test_full_rule_override_enforces_override_constraint(): void
    {
        $user = new MockRoleUser('admin');
        Auth::guard('sanctum')->setUser($user);

        $model = new RoleTestPostWithOverride;
        $request = Request::create('', 'POST', [
            'title' => str_repeat('a', 501),
        ]);

        $validator = $model->validateStore($request);
        $this->assertTrue($validator->fails());
        $this->assertArrayHasKey('title', $validator->errors()->toArray());
    }

    public function test_user_without_interface_falls_back_to_wildcard(): void
    {
        $user = new \App\Models\User;
        $user->id = 999;
        $user->email = 'norole@test.com';
        Auth::guard('sanctum')->setUser($user);

        $model = new RoleTestPost;
        $request = Request::create('', 'POST', [
            'title' => 'Post title',
            'content' => 'Content',
        ]);

        $validator = $model->validateStore($request);
        $this->assertFalse($validator->fails());
        $validated = $validator->validated();
        $this->assertArrayHasKey('title', $validated);
        $this->assertArrayHasKey('content', $validated);
    }

    public function test_integration_with_real_user_and_organization_resolves_role(): void
    {
        $org = \App\Models\Organization::create([
            'name' => 'Test Org',
            'slug' => 'test-org-rb',
            'domain' => null,
        ]);
        $roleAdmin = \App\Models\Role::firstOrCreate(
            ['slug' => 'admin'],
            ['name' => 'Admin', 'description' => null]
        );
        $roleAssistant = \App\Models\Role::firstOrCreate(
            ['slug' => 'assistant'],
            ['name' => 'Assistant', 'description' => null]
        );

        $userAdmin = \App\Models\User::create([
            'name' => 'Admin User',
            'email' => 'admin-rb@test.com',
            'password' => bcrypt('password'),
        ]);
        $userAssistant = \App\Models\User::create([
            'name' => 'Assistant User',
            'email' => 'assistant-rb@test.com',
            'password' => bcrypt('password'),
        ]);

        \App\Models\UserRole::create([
            'user_id' => $userAdmin->id,
            'role_id' => $roleAdmin->id,
            'organization_id' => $org->id,
            'permissions' => ['*'],
        ]);
        \App\Models\UserRole::create([
            'user_id' => $userAssistant->id,
            'role_id' => $roleAssistant->id,
            'organization_id' => $org->id,
            'permissions' => ['posts.store'],
        ]);

        request()->attributes->set('organization', $org);

        Auth::guard('sanctum')->setUser($userAdmin);
        $model = new RoleTestPost;
        $reqAdmin = Request::create('', 'POST', [
            'blog_id' => 1,
            'title' => 'T',
            'content' => 'C',
            'is_published' => true,
        ]);
        $vAdmin = $model->validateStore($reqAdmin);
        $this->assertFalse($vAdmin->fails());
        $this->assertArrayHasKey('is_published', $vAdmin->validated());

        Auth::guard('sanctum')->setUser($userAssistant);
        $reqAssistant = Request::create('', 'POST', [
            'title' => 'T',
            'content' => 'C',
        ]);
        $vAssistant = $model->validateStore($reqAssistant);
        $this->assertFalse($vAssistant->fails());
        $this->assertArrayNotHasKey('is_published', $vAssistant->validated());
    }
}
