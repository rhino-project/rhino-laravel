<?php

namespace Rhino\Tests\Unit;

use Illuminate\Contracts\Auth\Authenticatable;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\Schema;
use Illuminate\Database\Eloquent\Casts\Attribute;
use Rhino\Contracts\HasHiddenColumns;
use Rhino\Contracts\HasPermittedAttributes;
use Rhino\Policies\ResourcePolicy;
use Rhino\Tests\TestCase;
use Rhino\Traits\HidableColumns;

// --------------------------------------------------------------------------
// Test Models
// --------------------------------------------------------------------------

class HidablePost extends Model
{
    use HidableColumns;

    protected $table = 'hidable_posts';
    protected $fillable = ['title', 'content', 'cost_price', 'margin', 'internal_notes'];
}

class HidablePostWithAdditional extends Model
{
    use HidableColumns;

    protected $table = 'hidable_posts';
    protected $fillable = ['title', 'content', 'cost_price', 'margin', 'internal_notes'];
    protected $additionalHiddenColumns = ['internal_notes'];
}

class HidablePostWithNoPolicy extends Model
{
    use HidableColumns;

    protected $table = 'hidable_posts';
    protected $fillable = ['title', 'content', 'cost_price', 'margin', 'internal_notes'];
}

class HidablePostWithInterfacePolicy extends Model
{
    use HidableColumns;

    protected $table = 'hidable_posts';
    protected $fillable = ['title', 'content', 'cost_price', 'margin', 'internal_notes'];
}

class HidablePostWithPermittedAttributes extends Model
{
    use HidableColumns;

    protected $table = 'hidable_posts';
    protected $fillable = ['title', 'content', 'cost_price', 'margin', 'internal_notes'];
}

/** Model with a computed (virtual) attribute via $appends + Accessor. */
class HidablePostWithComputed extends Model
{
    use HidableColumns;

    protected $table = 'hidable_posts';
    protected $fillable = ['title', 'content', 'cost_price', 'margin', 'internal_notes'];
    protected $appends = ['rank', 'summary'];

    protected function rank(): Attribute
    {
        return Attribute::make(
            get: fn () => 42
        );
    }

    protected function summary(): Attribute
    {
        return Attribute::make(
            get: fn () => 'Summary of: ' . ($this->title ?? '')
        );
    }
}

/** Model with rhinoComputedAttributes() override. */
class HidablePostWithRhinoComputed extends Model
{
    use HidableColumns;

    protected $table = 'hidable_posts';
    protected $fillable = ['title', 'content', 'cost_price', 'margin', 'internal_notes'];

    public function rhinoComputedAttributes(): array
    {
        return [
            'test_value' => 'hello',
            'secret_score' => 'classified',
        ];
    }
}

// --------------------------------------------------------------------------
// Test Policies
// --------------------------------------------------------------------------

class HidablePostPolicy extends ResourcePolicy
{
    public function hiddenColumns(?Authenticatable $user): array
    {
        if (!$user) {
            return ['cost_price', 'margin', 'internal_notes'];
        }

        // Simulate role check: user with id=1 is "admin"
        if ($user->getAuthIdentifier() === 1) {
            return [];
        }

        return ['cost_price', 'margin'];
    }
}

class HidablePostWithAdditionalPolicy extends ResourcePolicy
{
    public function hiddenColumns(?Authenticatable $user): array
    {
        if (!$user) {
            return ['cost_price'];
        }

        return [];
    }
}

/**
 * A policy that does NOT extend ResourcePolicy and does NOT implement HasHiddenColumns.
 */
class PlainPolicy
{
    public function viewAny($user): bool
    {
        return true;
    }
}

/**
 * A policy that implements HasHiddenColumns directly without extending ResourcePolicy.
 */
class InterfaceOnlyPolicy implements HasHiddenColumns
{
    public function hiddenColumns(?Authenticatable $user): array
    {
        return ['margin'];
    }
}

/**
 * Policy that hides computed attributes for non-admin users.
 */
class ComputedAttributePolicy extends ResourcePolicy
{
    public function hiddenColumns(?Authenticatable $user): array
    {
        if (!$user) {
            return ['rank', 'summary'];
        }

        if ($user->getAuthIdentifier() === 1) {
            return []; // admin sees everything
        }

        return ['rank']; // regular user: hide rank, show summary
    }
}

/**
 * Spy policy that counts how many times hiddenColumns is called.
 */
class SpyPolicy extends ResourcePolicy
{
    public static int $callCount = 0;

    public function hiddenColumns(?Authenticatable $user): array
    {
        static::$callCount++;
        return ['cost_price'];
    }
}

/**
 * Policy that blacklists secret_score for rhinoComputedAttributes tests.
 */
class RhinoComputedBlacklistPolicy extends ResourcePolicy
{
    public function hiddenAttributesForShow(?Authenticatable $user): array
    {
        return ['secret_score'];
    }
}

/**
 * Policy that whitelists only id, title, test_value for rhinoComputedAttributes tests.
 */
class RhinoComputedWhitelistPolicy extends ResourcePolicy
{
    public function permittedAttributesForShow(?Authenticatable $user): array
    {
        return ['id', 'title', 'test_value'];
    }
}

/**
 * Policy that whitelists only id and title (excludes all computed attrs).
 */
class RhinoComputedStrictWhitelistPolicy extends ResourcePolicy
{
    public function permittedAttributesForShow(?Authenticatable $user): array
    {
        return ['id', 'title'];
    }
}

/**
 * Policy using the NEW HasPermittedAttributes methods (permittedAttributesForShow + hiddenAttributesForShow).
 */
class PermittedAttributesPolicy extends ResourcePolicy
{
    public function permittedAttributesForShow(?Authenticatable $user): array
    {
        if (!$user) {
            return ['id', 'title']; // guest only sees id and title
        }

        if ($user->getAuthIdentifier() === 1) {
            return ['*']; // admin sees everything
        }

        return ['id', 'title', 'content']; // regular user
    }

    public function hiddenAttributesForShow(?Authenticatable $user): array
    {
        if (!$user) {
            return ['internal_notes'];
        }

        return [];
    }
}

/**
 * Spy policy that counts calls to hiddenAttributesForShow (new method).
 */
class SpyPermittedAttributesPolicy extends ResourcePolicy
{
    public static int $callCount = 0;

    public function hiddenAttributesForShow(?Authenticatable $user): array
    {
        static::$callCount++;
        return ['cost_price'];
    }
}

// --------------------------------------------------------------------------
// Tests
// --------------------------------------------------------------------------

class HidableColumnsTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        // Run users migration for actingAs support
        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        Schema::create('hidable_posts', function (Blueprint $table) {
            $table->id();
            $table->string('title');
            $table->text('content')->nullable();
            $table->decimal('cost_price', 10, 2)->nullable();
            $table->decimal('margin', 10, 2)->nullable();
            $table->text('internal_notes')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    protected function tearDown(): void
    {
        // Clear static cache between tests to avoid cross-test contamination
        HidablePost::clearHiddenColumnsCache();
        HidablePostWithAdditional::clearHiddenColumnsCache();
        HidablePostWithNoPolicy::clearHiddenColumnsCache();
        HidablePostWithInterfacePolicy::clearHiddenColumnsCache();
        HidablePostWithComputed::clearHiddenColumnsCache();
        HidablePostWithPermittedAttributes::clearHiddenColumnsCache();
        HidablePostWithRhinoComputed::clearHiddenColumnsCache();
        SpyPolicy::$callCount = 0;
        SpyPermittedAttributesPolicy::$callCount = 0;

        parent::tearDown();
    }

    /**
     * Register a policy for a model in the Gate.
     */
    protected function registerPolicy(string $modelClass, string $policyClass): void
    {
        Gate::policy($modelClass, $policyClass);
    }

    /**
     * Create a simple test user.
     */
    protected function createUser(int $id = 1, string $name = 'Test User'): \App\Models\User
    {
        return \App\Models\User::forceCreate([
            'id' => $id,
            'name' => $name,
            'email' => "user{$id}@example.com",
            'password' => bcrypt('password'),
        ]);
    }

    // ------------------------------------------------------------------
    // Base behavior tests
    // ------------------------------------------------------------------

    public function test_base_hidden_columns_are_always_applied(): void
    {
        $post = HidablePost::forceCreate([
            'title' => 'Test Post',
            'content' => 'Content here',
            'cost_price' => 100.00,
        ]);

        $array = $post->toArray();

        $this->assertArrayNotHasKey('password', $array);
        $this->assertArrayNotHasKey('remember_token', $array);
        $this->assertArrayNotHasKey('created_at', $array);
        $this->assertArrayNotHasKey('updated_at', $array);
        $this->assertArrayNotHasKey('deleted_at', $array);
    }

    public function test_additional_hidden_columns_are_applied(): void
    {
        $post = HidablePostWithAdditional::forceCreate([
            'title' => 'Test Post',
            'content' => 'Content here',
            'internal_notes' => 'Secret notes',
        ]);

        $array = $post->toArray();

        $this->assertArrayNotHasKey('internal_notes', $array);
        $this->assertArrayHasKey('title', $array);
        $this->assertArrayHasKey('content', $array);
    }

    // ------------------------------------------------------------------
    // Policy-based hiding tests
    // ------------------------------------------------------------------

    public function test_policy_hidden_columns_applied_for_guest_user(): void
    {
        $this->registerPolicy(HidablePost::class, HidablePostPolicy::class);

        $post = HidablePost::forceCreate([
            'title' => 'Test Post',
            'content' => 'Content',
            'cost_price' => 100.00,
            'margin' => 20.00,
            'internal_notes' => 'Secret',
        ]);

        // No authenticated user — guest
        $array = $post->toArray();

        $this->assertArrayNotHasKey('cost_price', $array);
        $this->assertArrayNotHasKey('margin', $array);
        $this->assertArrayNotHasKey('internal_notes', $array);
        $this->assertArrayHasKey('title', $array);
        $this->assertArrayHasKey('content', $array);
    }

    public function test_policy_hidden_columns_applied_for_regular_user(): void
    {
        $this->registerPolicy(HidablePost::class, HidablePostPolicy::class);

        $user = $this->createUser(2, 'Regular User');
        $this->actingAs($user);

        $post = HidablePost::forceCreate([
            'title' => 'Test Post',
            'content' => 'Content',
            'cost_price' => 100.00,
            'margin' => 20.00,
            'internal_notes' => 'Secret',
        ]);

        $array = $post->toArray();

        // Regular user (id=2) should not see cost_price and margin
        $this->assertArrayNotHasKey('cost_price', $array);
        $this->assertArrayNotHasKey('margin', $array);
        // But should see internal_notes (policy only hides cost_price, margin for auth users)
        $this->assertArrayHasKey('internal_notes', $array);
        $this->assertArrayHasKey('title', $array);
    }

    public function test_policy_hidden_columns_admin_sees_everything(): void
    {
        $this->registerPolicy(HidablePost::class, HidablePostPolicy::class);

        $admin = $this->createUser(1, 'Admin User');
        $this->actingAs($admin);

        $post = HidablePost::forceCreate([
            'title' => 'Test Post',
            'content' => 'Content',
            'cost_price' => 100.00,
            'margin' => 20.00,
            'internal_notes' => 'Secret',
        ]);

        $array = $post->toArray();

        // Admin (id=1) should see cost_price, margin, and internal_notes
        $this->assertArrayHasKey('cost_price', $array);
        $this->assertArrayHasKey('margin', $array);
        $this->assertArrayHasKey('internal_notes', $array);
        $this->assertArrayHasKey('title', $array);
    }

    // ------------------------------------------------------------------
    // Additive behavior tests
    // ------------------------------------------------------------------

    public function test_policy_columns_are_additive_with_additional_hidden_columns(): void
    {
        $this->registerPolicy(HidablePostWithAdditional::class, HidablePostWithAdditionalPolicy::class);

        $post = HidablePostWithAdditional::forceCreate([
            'title' => 'Test Post',
            'content' => 'Content',
            'cost_price' => 100.00,
            'internal_notes' => 'Secret',
        ]);

        // Guest: policy hides cost_price, $additionalHiddenColumns hides internal_notes
        $array = $post->toArray();

        $this->assertArrayNotHasKey('cost_price', $array);
        $this->assertArrayNotHasKey('internal_notes', $array);
        $this->assertArrayHasKey('title', $array);
    }

    public function test_policy_returning_empty_array_does_not_unhide_base_columns(): void
    {
        $this->registerPolicy(HidablePostWithAdditional::class, HidablePostWithAdditionalPolicy::class);

        $user = $this->createUser(1, 'Admin');
        $this->actingAs($user);

        $post = HidablePostWithAdditional::forceCreate([
            'title' => 'Test Post',
            'content' => 'Content',
            'internal_notes' => 'Secret',
        ]);

        $array = $post->toArray();

        // Policy returns [] for auth user, but $additionalHiddenColumns still hides internal_notes
        $this->assertArrayNotHasKey('internal_notes', $array);
        // Base columns are still hidden
        $this->assertArrayNotHasKey('password', $array);
        $this->assertArrayNotHasKey('created_at', $array);
    }

    // ------------------------------------------------------------------
    // Fallback behavior tests
    // ------------------------------------------------------------------

    public function test_model_with_no_policy_falls_back_to_static_hidden(): void
    {
        // Don't register any policy for HidablePostWithNoPolicy
        $post = HidablePostWithNoPolicy::forceCreate([
            'title' => 'Test Post',
            'content' => 'Content',
            'cost_price' => 100.00,
            'margin' => 20.00,
        ]);

        $array = $post->toArray();

        // No policy, so only base hidden columns are applied
        $this->assertArrayHasKey('cost_price', $array);
        $this->assertArrayHasKey('margin', $array);
        $this->assertArrayHasKey('title', $array);
        // Base columns still hidden
        $this->assertArrayNotHasKey('created_at', $array);
    }

    public function test_model_with_plain_policy_not_implementing_interface_falls_back(): void
    {
        $this->registerPolicy(HidablePostWithNoPolicy::class, PlainPolicy::class);

        $post = HidablePostWithNoPolicy::forceCreate([
            'title' => 'Test Post',
            'cost_price' => 100.00,
        ]);

        $array = $post->toArray();

        // PlainPolicy doesn't implement HasHiddenColumns, so no extra hiding
        $this->assertArrayHasKey('cost_price', $array);
        $this->assertArrayHasKey('title', $array);
    }

    // ------------------------------------------------------------------
    // Interface-only policy tests
    // ------------------------------------------------------------------

    public function test_policy_implementing_interface_directly_works(): void
    {
        $this->registerPolicy(HidablePostWithInterfacePolicy::class, InterfaceOnlyPolicy::class);

        $post = HidablePostWithInterfacePolicy::forceCreate([
            'title' => 'Test Post',
            'margin' => 20.00,
            'cost_price' => 100.00,
        ]);

        $array = $post->toArray();

        // InterfaceOnlyPolicy hides 'margin'
        $this->assertArrayNotHasKey('margin', $array);
        $this->assertArrayHasKey('cost_price', $array);
        $this->assertArrayHasKey('title', $array);
    }

    // ------------------------------------------------------------------
    // Cache tests
    // ------------------------------------------------------------------

    public function test_cache_prevents_multiple_policy_resolutions(): void
    {
        Gate::policy(HidablePost::class, SpyPolicy::class);

        // Create multiple posts
        HidablePost::forceCreate(['title' => 'Post 1', 'cost_price' => 10]);
        HidablePost::forceCreate(['title' => 'Post 2', 'cost_price' => 20]);
        HidablePost::forceCreate(['title' => 'Post 3', 'cost_price' => 30]);

        $posts = HidablePost::all();

        // Serialize all posts (triggers getHidden on each)
        $posts->toArray();

        // hiddenColumns() should have been called only once due to caching
        $this->assertEquals(1, SpyPolicy::$callCount);
    }

    public function test_clear_hidden_columns_cache_resets_cache(): void
    {
        $this->registerPolicy(HidablePost::class, HidablePostPolicy::class);

        $post = HidablePost::forceCreate([
            'title' => 'Test Post',
            'cost_price' => 100.00,
        ]);

        // Guest — cost_price is hidden
        $array = $post->toArray();
        $this->assertArrayNotHasKey('cost_price', $array);

        // Now authenticate as admin
        $admin = $this->createUser(1, 'Admin');
        $this->actingAs($admin);

        // Clear cache to pick up the new user context
        HidablePost::clearHiddenColumnsCache();

        // Re-fetch to get a fresh model instance
        $post = HidablePost::find($post->id);
        $array = $post->toArray();

        // Admin sees everything
        $this->assertArrayHasKey('cost_price', $array);
    }

    // ------------------------------------------------------------------
    // ResourcePolicy default behavior tests
    // ------------------------------------------------------------------

    public function test_resource_policy_default_returns_empty_array(): void
    {
        $policy = new ResourcePolicy();
        $result = $policy->hiddenColumns(null);

        $this->assertIsArray($result);
        $this->assertEmpty($result);
    }

    public function test_resource_policy_implements_has_hidden_columns(): void
    {
        $policy = new ResourcePolicy();

        $this->assertInstanceOf(HasHiddenColumns::class, $policy);
    }

    // ------------------------------------------------------------------
    // hideAdditionalColumns method tests
    // ------------------------------------------------------------------

    public function test_hide_additional_columns_method_still_works(): void
    {
        $post = HidablePost::forceCreate([
            'title' => 'Test Post',
            'content' => 'Content',
            'cost_price' => 100.00,
        ]);

        $post->hideAdditionalColumns(['cost_price']);
        $array = $post->toArray();

        $this->assertArrayNotHasKey('cost_price', $array);
        $this->assertArrayHasKey('title', $array);
    }

    // ------------------------------------------------------------------
    // Computed (virtual) attribute tests
    // ------------------------------------------------------------------

    public function test_computed_attributes_are_included_in_response_when_appended(): void
    {
        $post = HidablePostWithComputed::forceCreate([
            'title' => 'Test Post',
            'content' => 'Content',
        ]);

        $array = $post->toArray();

        $this->assertArrayHasKey('rank', $array);
        $this->assertSame(42, $array['rank']);
        $this->assertArrayHasKey('summary', $array);
        $this->assertSame('Summary of: Test Post', $array['summary']);
        $this->assertArrayHasKey('title', $array);
    }

    public function test_policy_hides_computed_attributes_for_guest(): void
    {
        $this->registerPolicy(HidablePostWithComputed::class, ComputedAttributePolicy::class);

        $post = HidablePostWithComputed::forceCreate([
            'title' => 'Test Post',
            'content' => 'Content',
        ]);

        // No auth user (guest) — policy hides both rank and summary
        $array = $post->toArray();

        $this->assertArrayNotHasKey('rank', $array);
        $this->assertArrayNotHasKey('summary', $array);
        $this->assertArrayHasKey('title', $array);
    }

    public function test_policy_hides_computed_attributes_for_regular_user(): void
    {
        $this->registerPolicy(HidablePostWithComputed::class, ComputedAttributePolicy::class);

        $user = $this->createUser(2, 'Regular User');
        $this->actingAs($user);

        $post = HidablePostWithComputed::forceCreate([
            'title' => 'Test Post',
            'content' => 'Content',
        ]);

        $array = $post->toArray();

        // Regular user: rank hidden, summary visible
        $this->assertArrayNotHasKey('rank', $array);
        $this->assertArrayHasKey('summary', $array);
        $this->assertSame('Summary of: Test Post', $array['summary']);
        $this->assertArrayHasKey('title', $array);
    }

    public function test_admin_sees_all_computed_attributes(): void
    {
        $this->registerPolicy(HidablePostWithComputed::class, ComputedAttributePolicy::class);

        $admin = $this->createUser(1, 'Admin');
        $this->actingAs($admin);

        $post = HidablePostWithComputed::forceCreate([
            'title' => 'Test Post',
            'content' => 'Content',
        ]);

        $array = $post->toArray();

        // Admin sees rank and summary
        $this->assertArrayHasKey('rank', $array);
        $this->assertSame(42, $array['rank']);
        $this->assertArrayHasKey('summary', $array);
        $this->assertSame('Summary of: Test Post', $array['summary']);
        $this->assertArrayHasKey('title', $array);
    }

    // ------------------------------------------------------------------
    // HasPermittedAttributes tests (new interface)
    // ------------------------------------------------------------------

    public function test_resource_policy_implements_has_permitted_attributes(): void
    {
        $policy = new ResourcePolicy();

        $this->assertInstanceOf(HasPermittedAttributes::class, $policy);
    }

    public function test_resource_policy_default_permitted_attributes_for_show(): void
    {
        $policy = new ResourcePolicy();

        $this->assertSame(['*'], $policy->permittedAttributesForShow(null));
    }

    public function test_resource_policy_default_hidden_attributes_for_show(): void
    {
        $policy = new ResourcePolicy();

        $this->assertSame([], $policy->hiddenAttributesForShow(null));
    }

    public function test_permitted_attributes_for_show_filters_columns_for_guest(): void
    {
        $this->registerPolicy(HidablePostWithPermittedAttributes::class, PermittedAttributesPolicy::class);

        $post = HidablePostWithPermittedAttributes::forceCreate([
            'title' => 'Test Post',
            'content' => 'Content',
            'cost_price' => 100.00,
            'margin' => 20.00,
            'internal_notes' => 'Secret',
        ]);

        // Guest: permittedAttributesForShow returns ['id', 'title']
        // hiddenAttributesForShow returns ['internal_notes']
        // All other columns should be hidden
        $array = $post->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('title', $array);
        $this->assertArrayNotHasKey('content', $array);
        $this->assertArrayNotHasKey('cost_price', $array);
        $this->assertArrayNotHasKey('margin', $array);
        $this->assertArrayNotHasKey('internal_notes', $array);
    }

    public function test_permitted_attributes_for_show_wildcard_shows_all(): void
    {
        $this->registerPolicy(HidablePostWithPermittedAttributes::class, PermittedAttributesPolicy::class);

        $admin = $this->createUser(1, 'Admin');
        $this->actingAs($admin);

        $post = HidablePostWithPermittedAttributes::forceCreate([
            'title' => 'Test Post',
            'content' => 'Content',
            'cost_price' => 100.00,
            'margin' => 20.00,
            'internal_notes' => 'Secret',
        ]);

        // Admin: permittedAttributesForShow returns ['*'], hiddenAttributesForShow returns []
        $array = $post->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('title', $array);
        $this->assertArrayHasKey('content', $array);
        $this->assertArrayHasKey('cost_price', $array);
        $this->assertArrayHasKey('margin', $array);
        $this->assertArrayHasKey('internal_notes', $array);
    }

    public function test_permitted_attributes_for_show_regular_user_sees_subset(): void
    {
        $this->registerPolicy(HidablePostWithPermittedAttributes::class, PermittedAttributesPolicy::class);

        $user = $this->createUser(2, 'Regular User');
        $this->actingAs($user);

        $post = HidablePostWithPermittedAttributes::forceCreate([
            'title' => 'Test Post',
            'content' => 'Content',
            'cost_price' => 100.00,
            'margin' => 20.00,
            'internal_notes' => 'Secret',
        ]);

        // Regular user: permittedAttributesForShow returns ['id', 'title', 'content']
        $array = $post->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('title', $array);
        $this->assertArrayHasKey('content', $array);
        $this->assertArrayNotHasKey('cost_price', $array);
        $this->assertArrayNotHasKey('margin', $array);
        $this->assertArrayNotHasKey('internal_notes', $array);
    }

    public function test_hidden_attributes_for_show_merges_with_permitted(): void
    {
        $this->registerPolicy(HidablePostWithPermittedAttributes::class, PermittedAttributesPolicy::class);

        // Guest: permitted=['id','title'], hidden=['internal_notes']
        // internal_notes would already be excluded by permitted filter, but both should work together
        $post = HidablePostWithPermittedAttributes::forceCreate([
            'title' => 'Test Post',
            'content' => 'Content',
            'internal_notes' => 'Secret',
        ]);

        $array = $post->toArray();

        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('title', $array);
        $this->assertArrayNotHasKey('content', $array);
        $this->assertArrayNotHasKey('internal_notes', $array);
    }

    public function test_deprecated_hidden_columns_still_works_on_child_policies(): void
    {
        // HidablePostPolicy overrides hiddenColumns() (legacy method) on ResourcePolicy.
        // This test verifies backward compatibility: the override is still respected
        // even though ResourcePolicy now implements HasPermittedAttributes.
        $this->registerPolicy(HidablePost::class, HidablePostPolicy::class);

        $post = HidablePost::forceCreate([
            'title' => 'Test Post',
            'content' => 'Content',
            'cost_price' => 100.00,
            'margin' => 20.00,
            'internal_notes' => 'Secret',
        ]);

        // Guest: HidablePostPolicy.hiddenColumns() returns ['cost_price', 'margin', 'internal_notes']
        $array = $post->toArray();

        $this->assertArrayNotHasKey('cost_price', $array);
        $this->assertArrayNotHasKey('margin', $array);
        $this->assertArrayNotHasKey('internal_notes', $array);
        $this->assertArrayHasKey('title', $array);
        $this->assertArrayHasKey('content', $array);
    }

    public function test_cache_works_with_permitted_attributes_policy(): void
    {
        Gate::policy(HidablePostWithPermittedAttributes::class, SpyPermittedAttributesPolicy::class);

        HidablePostWithPermittedAttributes::forceCreate(['title' => 'Post 1', 'cost_price' => 10]);
        HidablePostWithPermittedAttributes::forceCreate(['title' => 'Post 2', 'cost_price' => 20]);
        HidablePostWithPermittedAttributes::forceCreate(['title' => 'Post 3', 'cost_price' => 30]);

        $posts = HidablePostWithPermittedAttributes::all();
        $posts->toArray();

        // hiddenAttributesForShow() should have been called only once due to caching
        $this->assertEquals(1, SpyPermittedAttributesPolicy::$callCount);
    }

    // ------------------------------------------------------------------
    // asRhinoJson tests
    // ------------------------------------------------------------------

    public function test_as_rhino_json_excludes_base_hidden_columns(): void
    {
        $post = HidablePost::forceCreate([
            'title' => 'Test Post',
            'content' => 'Content here',
            'cost_price' => 100.00,
        ]);

        $array = $post->asRhinoJson(null);

        $this->assertArrayNotHasKey('password', $array);
        $this->assertArrayNotHasKey('remember_token', $array);
        $this->assertArrayNotHasKey('created_at', $array);
        $this->assertArrayNotHasKey('updated_at', $array);
        $this->assertArrayNotHasKey('deleted_at', $array);
        $this->assertArrayHasKey('title', $array);
        $this->assertArrayHasKey('cost_price', $array);
    }

    public function test_as_rhino_json_applies_policy_blacklist(): void
    {
        $this->registerPolicy(HidablePost::class, HidablePostPolicy::class);

        $post = HidablePost::forceCreate([
            'title' => 'Test Post',
            'content' => 'Content',
            'cost_price' => 100.00,
            'margin' => 20.00,
            'internal_notes' => 'Secret',
        ]);

        // Guest — policy hides cost_price, margin, internal_notes
        $array = $post->asRhinoJson(null);
        $this->assertArrayNotHasKey('cost_price', $array);
        $this->assertArrayNotHasKey('margin', $array);
        $this->assertArrayNotHasKey('internal_notes', $array);
        $this->assertArrayHasKey('title', $array);

        // Admin (id=1) sees everything
        $admin = $this->createUser(1, 'Admin');
        HidablePost::clearHiddenColumnsCache();
        $array = $post->asRhinoJson($admin);
        $this->assertArrayHasKey('cost_price', $array);
        $this->assertArrayHasKey('margin', $array);
        $this->assertArrayHasKey('title', $array);
    }

    public function test_as_rhino_json_applies_policy_whitelist(): void
    {
        $this->registerPolicy(HidablePostWithPermittedAttributes::class, PermittedAttributesPolicy::class);

        $post = HidablePostWithPermittedAttributes::forceCreate([
            'title' => 'Test Post',
            'content' => 'Content',
            'cost_price' => 100.00,
            'margin' => 20.00,
            'internal_notes' => 'Secret',
        ]);

        // Guest: permittedAttributesForShow returns ['id', 'title']
        $array = $post->asRhinoJson(null);
        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('title', $array);
        $this->assertArrayNotHasKey('content', $array);
        $this->assertArrayNotHasKey('cost_price', $array);

        // Admin: permittedAttributesForShow returns ['*']
        $admin = $this->createUser(1, 'Admin');
        HidablePostWithPermittedAttributes::clearHiddenColumnsCache();
        $array = $post->asRhinoJson($admin);
        $this->assertArrayHasKey('title', $array);
        $this->assertArrayHasKey('content', $array);
        $this->assertArrayHasKey('cost_price', $array);
    }

    public function test_as_rhino_json_hides_computed_attributes_via_blacklist(): void
    {
        $this->registerPolicy(HidablePostWithComputed::class, ComputedAttributePolicy::class);

        $post = HidablePostWithComputed::forceCreate([
            'title' => 'Test Post',
            'content' => 'Content',
        ]);

        // Guest: policy hides both rank and summary
        $array = $post->asRhinoJson(null);
        $this->assertArrayNotHasKey('rank', $array);
        $this->assertArrayNotHasKey('summary', $array);
        $this->assertArrayHasKey('title', $array);

        // Admin: sees everything including computed
        $admin = $this->createUser(1, 'Admin');
        HidablePostWithComputed::clearHiddenColumnsCache();
        $array = $post->asRhinoJson($admin);
        $this->assertArrayHasKey('rank', $array);
        $this->assertSame(42, $array['rank']);
        $this->assertArrayHasKey('summary', $array);
    }

    public function test_as_rhino_json_hides_computed_attributes_via_whitelist(): void
    {
        // Policy that whitelists only id+title for guest, * for auth users
        $policyClass = new class extends ResourcePolicy {
            public function permittedAttributesForShow(?\Illuminate\Contracts\Auth\Authenticatable $user): array
            {
                if (!$user) {
                    return ['id', 'title'];
                }
                return ['*'];
            }
        };

        Gate::policy(HidablePostWithComputed::class, get_class($policyClass));

        $post = HidablePostWithComputed::forceCreate([
            'title' => 'Test Post',
            'content' => 'Content',
        ]);

        // Guest: only id + title, computed attributes excluded
        $array = $post->asRhinoJson(null);
        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('title', $array);
        $this->assertArrayNotHasKey('rank', $array);
        $this->assertArrayNotHasKey('summary', $array);
        $this->assertArrayNotHasKey('content', $array);

        // Auth user: sees everything
        $user = $this->createUser(3, 'Regular');
        HidablePostWithComputed::clearHiddenColumnsCache();
        $array = $post->asRhinoJson($user);
        $this->assertArrayHasKey('rank', $array);
        $this->assertArrayHasKey('summary', $array);
        $this->assertArrayHasKey('content', $array);
    }

    // ------------------------------------------------------------------
    // rhinoComputedAttributes tests
    // ------------------------------------------------------------------

    public function test_rhino_computed_attributes_returns_empty_by_default(): void
    {
        $post = HidablePost::forceCreate([
            'title' => 'Test Post',
            'content' => 'Content',
        ]);

        $this->assertSame([], $post->rhinoComputedAttributes());
    }

    public function test_rhino_computed_attributes_are_included_in_as_rhino_json(): void
    {
        $post = HidablePostWithRhinoComputed::forceCreate([
            'title' => 'Test Post',
            'content' => 'Content',
        ]);

        $array = $post->asRhinoJson(null);

        $this->assertArrayHasKey('test_value', $array);
        $this->assertSame('hello', $array['test_value']);
        $this->assertArrayHasKey('secret_score', $array);
        $this->assertSame('classified', $array['secret_score']);
        $this->assertArrayHasKey('title', $array);
    }

    public function test_rhino_computed_attributes_are_hidden_by_blacklist(): void
    {
        $this->registerPolicy(HidablePostWithRhinoComputed::class, RhinoComputedBlacklistPolicy::class);

        $post = HidablePostWithRhinoComputed::forceCreate([
            'title' => 'Test Post',
            'content' => 'Content',
        ]);

        $array = $post->asRhinoJson(null);

        // secret_score is blacklisted by policy
        $this->assertArrayNotHasKey('secret_score', $array);
        // test_value is not blacklisted
        $this->assertArrayHasKey('test_value', $array);
        $this->assertSame('hello', $array['test_value']);
        $this->assertArrayHasKey('title', $array);
    }

    public function test_rhino_computed_attributes_are_filtered_by_whitelist(): void
    {
        $this->registerPolicy(HidablePostWithRhinoComputed::class, RhinoComputedWhitelistPolicy::class);

        $post = HidablePostWithRhinoComputed::forceCreate([
            'title' => 'Test Post',
            'content' => 'Content',
        ]);

        $array = $post->asRhinoJson(null);

        // Whitelist allows id, title, test_value
        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('title', $array);
        $this->assertArrayHasKey('test_value', $array);
        $this->assertSame('hello', $array['test_value']);
        // secret_score is NOT in the whitelist
        $this->assertArrayNotHasKey('secret_score', $array);
        // content is also NOT in the whitelist
        $this->assertArrayNotHasKey('content', $array);
    }

    public function test_rhino_computed_attributes_not_in_whitelist_are_excluded(): void
    {
        $this->registerPolicy(HidablePostWithRhinoComputed::class, RhinoComputedStrictWhitelistPolicy::class);

        $post = HidablePostWithRhinoComputed::forceCreate([
            'title' => 'Test Post',
            'content' => 'Content',
        ]);

        $array = $post->asRhinoJson(null);

        // Strict whitelist only allows id and title
        $this->assertArrayHasKey('id', $array);
        $this->assertArrayHasKey('title', $array);
        // Both computed attributes are excluded
        $this->assertArrayNotHasKey('test_value', $array);
        $this->assertArrayNotHasKey('secret_score', $array);
    }
}
