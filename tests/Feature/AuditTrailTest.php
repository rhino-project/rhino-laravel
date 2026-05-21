<?php

namespace Rhino\Tests\Feature;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Rhino\Models\AuditLog;
use Rhino\Tests\TestCase;
use Rhino\Traits\HasAuditTrail;
use Rhino\Traits\HasValidation;
use Rhino\Traits\HidableColumns;

// --------------------------------------------------------------------------
// Test Models
// --------------------------------------------------------------------------

class AuditPost extends Model
{
    use SoftDeletes, HasValidation, HidableColumns, HasAuditTrail;

    protected $table = 'audit_posts';
    protected $fillable = ['title', 'content', 'user_id'];

    protected $validationRules = [
        'title' => 'required|string|max:255',
        'content' => 'string',
    ];

    protected $validationRulesStore = ['title', 'content'];
    protected $validationRulesUpdate = ['title', 'content'];
}

class AuditPostWithExclusions extends Model
{
    use HasValidation, HidableColumns, HasAuditTrail;

    protected $table = 'audit_posts';
    protected $fillable = ['title', 'content', 'secret_field'];

    protected array $auditExclude = [
        'password',
        'remember_token',
        'secret_field',
    ];
}

class AuditPostMinimal extends Model
{
    use HasValidation, HidableColumns, HasAuditTrail;

    protected $table = 'audit_posts';
    protected $fillable = ['title', 'content'];

    // No $auditExclude declared, no SoftDeletes — minimal usage
}

class AuditPostNoTrail extends Model
{
    use HasValidation, HidableColumns;

    protected $table = 'audit_posts';
    protected $fillable = ['title', 'content'];
}

// --------------------------------------------------------------------------
// Test Policies
// --------------------------------------------------------------------------

class AuditPostPermissivePolicy
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

// --------------------------------------------------------------------------
// Tests
// --------------------------------------------------------------------------

class AuditTrailTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        Schema::create('audit_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content')->nullable();
            $table->string('secret_field')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->morphs('auditable');
            $table->string('action');
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->unsignedBigInteger('user_id')->nullable();
            $table->unsignedBigInteger('organization_id')->nullable();
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->timestamps();
        });

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        Gate::policy(AuditPost::class, AuditPostPermissivePolicy::class);
        Gate::policy(AuditPostWithExclusions::class, AuditPostPermissivePolicy::class);
        Gate::policy(AuditPostMinimal::class, AuditPostPermissivePolicy::class);
        Gate::policy(AuditPostNoTrail::class, AuditPostPermissivePolicy::class);
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

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function registerRoutes(array $models, array $public = []): void
    {
        if (!empty($public)) {
            $nonPublicSlugs = array_diff(array_keys($models), $public);
            $routeGroups = [
                'public' => ['prefix' => '', 'middleware' => [], 'models' => $public],
            ];
            if (!empty($nonPublicSlugs)) {
                $routeGroups['default'] = ['prefix' => '', 'middleware' => [], 'models' => array_values($nonPublicSlugs)];
            }
        } else {
            $routeGroups = [
                'default' => ['prefix' => '', 'middleware' => [], 'models' => '*'],
            ];
        }

        config([
            'rhino.models' => $models,
            'rhino.route_groups' => $routeGroups,
            'rhino.multi_tenant' => [
                'organization_identifier_column' => 'id',
            ],
        ]);

        Route::prefix('api')->group(function () {
            require __DIR__ . '/../../routes/api.php';
        });
    }

    protected function authenticateUser(int $userId = 1): \App\Models\User
    {
        $user = \App\Models\User::firstOrCreate(
            ['id' => $userId],
            [
                'name' => "User {$userId}",
                'email' => "user{$userId}@example.com",
                'password' => bcrypt('password'),
            ]
        );

        $this->actingAs($user, 'sanctum');

        return $user;
    }

    protected function authenticateWithPermissions(array $permissions, int $userId = 1): \App\Models\User
    {
        $user = $this->authenticateUser($userId);

        $org = \App\Models\Organization::firstOrCreate(
            ['id' => 1],
            ['name' => 'Test Org', 'slug' => 'test-org']
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

        request()->attributes->set('organization', $org);

        return $user;
    }

    // ==================================================================
    // Trait: Logging on model events
    // ==================================================================

    public function test_logs_created_event(): void
    {
        $post = AuditPost::create(['title' => 'New Post', 'content' => 'Body']);

        $logs = AuditLog::where('auditable_type', AuditPost::class)
            ->where('auditable_id', $post->id)
            ->get();

        $this->assertCount(1, $logs);
        $this->assertEquals('created', $logs[0]->action);
        $this->assertNull($logs[0]->old_values);
        $this->assertEquals('New Post', $logs[0]->new_values['title']);
    }

    public function test_logs_updated_event_with_only_dirty_fields(): void
    {
        $post = AuditPost::create(['title' => 'Original', 'content' => 'Body']);

        // Clear the "created" log
        AuditLog::query()->delete();

        $post->update(['title' => 'Changed']);

        $logs = AuditLog::where('auditable_type', AuditPost::class)
            ->where('auditable_id', $post->id)
            ->get();

        $this->assertCount(1, $logs);
        $this->assertEquals('updated', $logs[0]->action);
        $this->assertEquals('Original', $logs[0]->old_values['title']);
        $this->assertEquals('Changed', $logs[0]->new_values['title']);

        // Content was NOT changed, so it should NOT be in old/new values
        $this->assertArrayNotHasKey('content', $logs[0]->old_values);
        $this->assertArrayNotHasKey('content', $logs[0]->new_values);
    }

    public function test_does_not_log_update_when_nothing_changed(): void
    {
        $post = AuditPost::create(['title' => 'Same', 'content' => 'Body']);
        AuditLog::query()->delete();

        // "Update" with the same values — getDirty() returns empty
        $post->title = 'Same';
        $post->save();

        $this->assertCount(0, AuditLog::all());
    }

    public function test_logs_deleted_event(): void
    {
        $post = AuditPost::create(['title' => 'To Delete', 'content' => 'Body']);
        AuditLog::query()->delete();

        $post->delete();

        $logs = AuditLog::where('action', 'deleted')->get();

        $this->assertCount(1, $logs);
        $this->assertEquals('deleted', $logs[0]->action);
        $this->assertEquals('To Delete', $logs[0]->old_values['title']);
        $this->assertNull($logs[0]->new_values);
    }

    public function test_logs_restored_event(): void
    {
        $post = AuditPost::create(['title' => 'Restore Me', 'content' => 'Body']);
        $post->delete();
        AuditLog::query()->delete();

        $post->restore();

        $logs = AuditLog::where('action', 'restored')->get();

        $this->assertCount(1, $logs);
        $this->assertEquals('restored', $logs[0]->action);
        $this->assertNull($logs[0]->old_values);
        $this->assertEquals('Restore Me', $logs[0]->new_values['title']);
    }

    public function test_logs_force_deleted_event(): void
    {
        $post = AuditPost::create(['title' => 'Force Delete', 'content' => 'Body']);
        $post->delete();
        AuditLog::query()->delete();

        $post->forceDelete();

        $logs = AuditLog::where('action', 'force_deleted')->get();

        $this->assertCount(1, $logs);
        $this->assertEquals('force_deleted', $logs[0]->action);
        $this->assertEquals('Force Delete', $logs[0]->old_values['title']);
        $this->assertNull($logs[0]->new_values);
    }

    // ==================================================================
    // Trait: Excluded columns
    // ==================================================================

    public function test_excluded_columns_not_logged(): void
    {
        $post = AuditPostWithExclusions::create([
            'title' => 'Post',
            'content' => 'Body',
            'secret_field' => 'super-secret',
        ]);

        $log = AuditLog::first();

        $this->assertArrayNotHasKey('secret_field', $log->new_values);
        $this->assertArrayHasKey('title', $log->new_values);
    }

    public function test_excluded_columns_not_logged_on_update(): void
    {
        $post = AuditPostWithExclusions::create([
            'title' => 'Post',
            'secret_field' => 'old-secret',
        ]);
        AuditLog::query()->delete();

        $post->update(['title' => 'New Title', 'secret_field' => 'new-secret']);

        $log = AuditLog::first();

        // Title should be logged
        $this->assertEquals('Post', $log->old_values['title']);
        $this->assertEquals('New Title', $log->new_values['title']);

        // Secret field should NOT be logged
        $this->assertArrayNotHasKey('secret_field', $log->old_values);
        $this->assertArrayNotHasKey('secret_field', $log->new_values);
    }

    // ==================================================================
    // Trait: User and metadata tracking
    // ==================================================================

    public function test_logs_authenticated_user_id(): void
    {
        $user = $this->authenticateUser(5);

        AuditPost::create(['title' => 'By User', 'content' => 'Body']);

        $log = AuditLog::first();
        $this->assertEquals($user->id, $log->user_id);
    }

    public function test_logs_null_user_for_unauthenticated(): void
    {
        AuditPost::create(['title' => 'No Auth', 'content' => 'Body']);

        $log = AuditLog::first();
        $this->assertNull($log->user_id);
    }

    public function test_logs_ip_address(): void
    {
        AuditPost::create(['title' => 'IP Test', 'content' => 'Body']);

        $log = AuditLog::first();
        $this->assertNotNull($log->ip_address);
    }

    // ==================================================================
    // Trait: morphMany relationship
    // ==================================================================

    public function test_audit_logs_relationship_on_model(): void
    {
        $post = AuditPost::create(['title' => 'Relationship', 'content' => 'Body']);
        $post->update(['title' => 'Changed']);

        $logs = $post->auditLogs;

        $this->assertCount(2, $logs);
        $this->assertEquals('created', $logs[0]->action);
        $this->assertEquals('updated', $logs[1]->action);
    }

    // ==================================================================
    // Full lifecycle audit log (programmatic — no audit route)
    // ==================================================================

    // ==================================================================
    // Regression: model without $auditExclude or SoftDeletes
    // ==================================================================

    public function test_model_without_audit_exclude_property_does_not_crash(): void
    {
        // Regression: accessing static::$auditExclude on a model that never
        // declared the property caused "Access to undeclared static property".
        $post = AuditPostMinimal::create(['title' => 'Minimal', 'content' => 'Body']);

        $logs = AuditLog::where('auditable_type', AuditPostMinimal::class)
            ->where('auditable_id', $post->id)
            ->get();

        $this->assertCount(1, $logs);
        $this->assertEquals('created', $logs[0]->action);
        $this->assertEquals('Minimal', $logs[0]->new_values['title']);
    }

    public function test_model_without_audit_exclude_uses_defaults(): void
    {
        $model = new AuditPostMinimal();
        $excluded = $model->getAuditExclude();

        $this->assertEquals(['password', 'remember_token'], $excluded);
    }

    public function test_model_without_audit_exclude_logs_update(): void
    {
        $post = AuditPostMinimal::create(['title' => 'Original', 'content' => 'Body']);
        AuditLog::query()->delete();

        $post->update(['title' => 'Changed']);

        $log = AuditLog::first();
        $this->assertNotNull($log);
        $this->assertEquals('updated', $log->action);
        $this->assertEquals('Original', $log->old_values['title']);
        $this->assertEquals('Changed', $log->new_values['title']);
    }

    public function test_model_without_audit_exclude_logs_delete(): void
    {
        $post = AuditPostMinimal::create(['title' => 'Delete Me', 'content' => 'Body']);
        AuditLog::query()->delete();

        $post->delete();

        $log = AuditLog::first();
        $this->assertNotNull($log);
        // Without SoftDeletes, isForceDeleting() is not available, so action is 'deleted'
        $this->assertEquals('deleted', $log->action);
        $this->assertEquals('Delete Me', $log->old_values['title']);
    }

    // ==================================================================
    // Full lifecycle audit log (programmatic — no audit route)
    // ==================================================================

    // ==================================================================
    // Regression: getAuditExclude uses instance property, not static
    // ==================================================================

    public function test_audit_exclude_works_as_instance_property(): void
    {
        // Regression: HasAuditTrail used static::$auditExclude which failed
        // because RhinoModel declares $auditExclude as a non-static property.
        // The fix uses $this->auditExclude (instance access) instead.
        $model = new AuditPostWithExclusions();
        $excluded = $model->getAuditExclude();

        $this->assertContains('secret_field', $excluded);
        $this->assertContains('password', $excluded);
        $this->assertContains('remember_token', $excluded);
    }

    public function test_audit_exclude_instance_property_respected_on_create(): void
    {
        // Ensure instance $auditExclude actually filters fields during logging
        $post = AuditPostWithExclusions::create([
            'title' => 'Instance Test',
            'content' => 'Body',
            'secret_field' => 'hidden-value',
        ]);

        $log = AuditLog::where('auditable_type', AuditPostWithExclusions::class)
            ->where('auditable_id', $post->id)
            ->first();

        $this->assertArrayNotHasKey('secret_field', $log->new_values);
        $this->assertArrayNotHasKey('password', $log->new_values);
        $this->assertArrayHasKey('title', $log->new_values);
    }

    // ==================================================================
    // Full lifecycle audit log (programmatic — no audit route)
    // ==================================================================

    public function test_full_crud_lifecycle_audit_trail(): void
    {
        // Create
        $post = AuditPost::create(['title' => 'Lifecycle', 'content' => 'Original']);

        // Update
        $post->update(['title' => 'Lifecycle Updated', 'content' => 'Changed']);

        // Delete (soft)
        $post->delete();

        // Restore
        $post->restore();

        // Assert audit log via relationship (no HTTP endpoint)
        $data = $post->auditLogs()->orderByDesc('id')->get()->toArray();
        $this->assertCount(4, $data);
        $actions = array_column($data, 'action');
        $this->assertEquals(['restored', 'deleted', 'updated', 'created'], $actions);
    }
}
