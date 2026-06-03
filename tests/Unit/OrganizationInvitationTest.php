<?php

namespace Rhino\Tests\Unit;

use Carbon\Carbon;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rhino\Models\OrganizationInvitation;
use Rhino\Tests\TestCase;

class OrganizationInvitationTest extends TestCase
{
    protected function setUp(): void
    {
        parent::setUp();

        $this->loadMigrationsFrom(__DIR__ . '/../database/migrations');

        Schema::create('organization_invitations', function (Blueprint $table) {
            $table->id();
            $table->foreignId('organization_id')->constrained()->onDelete('cascade');
            $table->string('email')->index();
            $table->string('route_group')->nullable();
            $table->foreignId('role_id')->constrained()->onDelete('cascade');
            $table->string('token', 64)->unique()->index();
            $table->foreignId('invited_by')->constrained('users')->onDelete('cascade');
            $table->string('status')->default('pending');
            $table->timestamp('expires_at')->nullable();
            $table->timestamp('accepted_at')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });
    }

    protected function createOrg(array $attrs = []): \App\Models\Organization
    {
        return \App\Models\Organization::forceCreate(array_merge([
            'name' => 'Test Org',
            'slug' => 'test-org',
        ], $attrs));
    }

    protected function createRole(array $attrs = []): \App\Models\Role
    {
        return \App\Models\Role::forceCreate(array_merge([
            'name' => 'Editor',
            'slug' => 'editor',
        ], $attrs));
    }

    protected function createUser(array $attrs = []): \App\Models\User
    {
        return \App\Models\User::forceCreate(array_merge([
            'name' => 'Test User',
            'email' => 'test@example.com',
            'password' => bcrypt('password'),
        ], $attrs));
    }

    protected function createInvitation(array $attrs = []): OrganizationInvitation
    {
        $org = $this->createOrg();
        $role = $this->createRole();
        $user = $this->createUser();

        return OrganizationInvitation::create(array_merge([
            'organization_id' => $org->id,
            'email' => 'invitee@example.com',
            'role_id' => $role->id,
            'invited_by' => $user->id,
        ], $attrs));
    }

    // ------------------------------------------------------------------
    // Boot behavior: auto-set token and expires_at
    // ------------------------------------------------------------------

    public function test_auto_generates_token_on_creation(): void
    {
        $invitation = $this->createInvitation();

        $this->assertNotEmpty($invitation->token);
        $this->assertEquals(64, strlen($invitation->token));
    }

    public function test_auto_sets_expires_at_on_creation(): void
    {
        $invitation = $this->createInvitation();

        $this->assertNotNull($invitation->expires_at);
        $this->assertTrue($invitation->expires_at->isFuture());
    }

    public function test_respects_custom_expiry_config(): void
    {
        config(['rhino.invitations.expires_days' => 14]);

        $before = Carbon::now()->addDays(13);
        $invitation = $this->createInvitation();
        $after = Carbon::now()->addDays(15);

        $this->assertTrue($invitation->expires_at->gt($before));
        $this->assertTrue($invitation->expires_at->lt($after));
    }

    public function test_does_not_overwrite_custom_token(): void
    {
        $invitation = $this->createInvitation(['token' => 'custom-token-123']);

        $this->assertEquals('custom-token-123', $invitation->token);
    }

    public function test_does_not_overwrite_custom_expires_at(): void
    {
        $customDate = Carbon::now()->addDays(30);
        $invitation = $this->createInvitation(['expires_at' => $customDate]);

        $this->assertTrue($invitation->expires_at->isSameDay($customDate));
    }

    // ------------------------------------------------------------------
    // isExpired
    // ------------------------------------------------------------------

    public function test_is_expired_returns_true_when_past_expires_at(): void
    {
        $invitation = $this->createInvitation();

        // Manually set expires_at to past date after creation
        $invitation->expires_at = Carbon::now()->subDay();
        $invitation->save();
        $invitation->refresh();

        $this->assertTrue($invitation->isExpired());
    }

    public function test_is_expired_returns_false_when_future_expires_at(): void
    {
        $invitation = $this->createInvitation(['expires_at' => Carbon::now()->addDay()]);

        $this->assertFalse($invitation->isExpired());
    }

    public function test_is_expired_returns_false_when_not_pending(): void
    {
        $invitation = $this->createInvitation([
            'status' => 'accepted',
            'expires_at' => Carbon::now()->subDay(),
        ]);

        $this->assertFalse($invitation->isExpired());
    }

    // ------------------------------------------------------------------
    // isPending
    // ------------------------------------------------------------------

    public function test_is_pending_returns_true_for_valid_pending_invitation(): void
    {
        $invitation = $this->createInvitation(['status' => 'pending']);
        $invitation->refresh();

        $this->assertEquals('pending', $invitation->status);
        $this->assertNotNull($invitation->expires_at);
        $this->assertTrue($invitation->expires_at->isFuture());
        $this->assertTrue($invitation->isPending());
    }

    public function test_is_pending_returns_false_when_expired(): void
    {
        $invitation = $this->createInvitation();
        $invitation->expires_at = Carbon::now()->subDay();
        $invitation->save();
        $invitation->refresh();

        $this->assertFalse($invitation->isPending());
    }

    public function test_is_pending_returns_false_when_not_pending_status(): void
    {
        $invitation = $this->createInvitation(['status' => 'accepted']);

        $this->assertFalse($invitation->isPending());
    }

    // ------------------------------------------------------------------
    // accept
    // ------------------------------------------------------------------

    public function test_accept_sets_status_and_accepted_at(): void
    {
        $invitation = $this->createInvitation(['status' => 'pending']);
        $invitation->refresh();

        $result = $invitation->accept();

        $this->assertTrue($result);
        $this->assertEquals('accepted', $invitation->fresh()->status);
        $this->assertNotNull($invitation->fresh()->accepted_at);
    }

    public function test_accept_returns_false_when_not_pending(): void
    {
        $invitation = $this->createInvitation([
            'status' => 'accepted',
            'expires_at' => Carbon::now()->addDay(),
        ]);

        $result = $invitation->accept();

        $this->assertFalse($result);
    }

    public function test_accept_returns_false_when_expired(): void
    {
        $invitation = $this->createInvitation();
        $invitation->expires_at = Carbon::now()->subDay();
        $invitation->save();
        $invitation->refresh();

        $result = $invitation->accept();

        $this->assertFalse($result);
    }

    public function test_accept_with_user_adds_user_to_organization(): void
    {
        $org = $this->createOrg();
        $role = $this->createRole();
        $inviter = $this->createUser();
        $acceptingUser = \App\Models\User::forceCreate([
            'name' => 'New User',
            'email' => 'newuser@example.com',
            'password' => bcrypt('password'),
        ]);

        $invitation = OrganizationInvitation::create([
            'organization_id' => $org->id,
            'email' => 'newuser@example.com',
            'role_id' => $role->id,
            'invited_by' => $inviter->id,
            'status' => 'pending',
        ]);
        $invitation->refresh();

        $result = $invitation->accept($acceptingUser);

        $this->assertTrue($result);
        $this->assertTrue(
            $org->users()->where('users.id', $acceptingUser->id)->exists()
        );
    }

    public function test_accept_with_user_does_not_duplicate_membership(): void
    {
        $org = $this->createOrg();
        $role = $this->createRole();
        $inviter = $this->createUser();
        $acceptingUser = \App\Models\User::forceCreate([
            'name' => 'Existing User',
            'email' => 'existing@example.com',
            'password' => bcrypt('password'),
        ]);

        // Already a member
        $org->users()->attach($acceptingUser->id, [
            'role_id' => $role->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $invitation = OrganizationInvitation::create([
            'organization_id' => $org->id,
            'email' => 'existing@example.com',
            'role_id' => $role->id,
            'invited_by' => $inviter->id,
            'status' => 'pending',
        ]);
        $invitation->refresh();

        $result = $invitation->accept($acceptingUser);

        $this->assertTrue($result);
        // Should still be only one membership
        $this->assertEquals(1, $org->users()->where('users.id', $acceptingUser->id)->count());
    }

    // ------------------------------------------------------------------
    // Relationships
    // ------------------------------------------------------------------

    public function test_organization_relationship(): void
    {
        $invitation = $this->createInvitation();

        $this->assertInstanceOf(
            \Illuminate\Database\Eloquent\Relations\BelongsTo::class,
            $invitation->organization()
        );
    }

    public function test_role_relationship(): void
    {
        $invitation = $this->createInvitation();

        $this->assertInstanceOf(
            \Illuminate\Database\Eloquent\Relations\BelongsTo::class,
            $invitation->role()
        );
    }

    public function test_invited_by_relationship(): void
    {
        $invitation = $this->createInvitation();

        $this->assertInstanceOf(
            \Illuminate\Database\Eloquent\Relations\BelongsTo::class,
            $invitation->invitedBy()
        );
    }

    // ------------------------------------------------------------------
    // Scopes
    // ------------------------------------------------------------------

    public function test_scope_pending(): void
    {
        // Create a pending invitation
        $this->createInvitation(['expires_at' => Carbon::now()->addDay()]);

        // Create an expired one
        $org = \App\Models\Organization::first();
        $role = \App\Models\Role::first();
        $user = \App\Models\User::first();
        OrganizationInvitation::create([
            'organization_id' => $org->id,
            'email' => 'expired@example.com',
            'role_id' => $role->id,
            'invited_by' => $user->id,
            'expires_at' => Carbon::now()->subDay(),
        ]);

        $pending = OrganizationInvitation::pending()->get();

        $this->assertEquals(1, $pending->count());
        $this->assertEquals('invitee@example.com', $pending->first()->email);
    }

    public function test_scope_expired(): void
    {
        // Create a pending (not expired)
        $this->createInvitation(['expires_at' => Carbon::now()->addDay()]);

        // Create an expired one
        $org = \App\Models\Organization::first();
        $role = \App\Models\Role::first();
        $user = \App\Models\User::first();
        OrganizationInvitation::create([
            'organization_id' => $org->id,
            'email' => 'expired@example.com',
            'role_id' => $role->id,
            'invited_by' => $user->id,
            'expires_at' => Carbon::now()->subDay(),
        ]);

        $expired = OrganizationInvitation::expired()->get();

        $this->assertEquals(1, $expired->count());
        $this->assertEquals('expired@example.com', $expired->first()->email);
    }

    // ------------------------------------------------------------------
    // Casts
    // ------------------------------------------------------------------

    public function test_expires_at_is_cast_to_datetime(): void
    {
        $invitation = $this->createInvitation();

        $this->assertInstanceOf(Carbon::class, $invitation->expires_at);
    }

    public function test_accepted_at_is_cast_to_datetime(): void
    {
        $invitation = $this->createInvitation(['status' => 'pending']);
        $invitation->refresh();
        $invitation->accept();

        $invitation->refresh();
        $this->assertInstanceOf(Carbon::class, $invitation->accepted_at);
    }
}
