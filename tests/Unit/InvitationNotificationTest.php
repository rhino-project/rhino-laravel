<?php

namespace Rhino\Tests\Unit;

use App\Models\Organization;
use App\Models\Role;
use App\Models\User;
use App\Models\UserRole;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;
use Rhino\Models\OrganizationInvitation;
use Rhino\Notifications\InvitationNotification;
use Rhino\Tests\TestCase;

class InvitationNotificationTest extends TestCase
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

    // ------------------------------------------------------------------
    // Helpers
    // ------------------------------------------------------------------

    protected function createInvitationWithRelations(): OrganizationInvitation
    {
        $org = Organization::forceCreate(['name' => 'Acme Corp', 'slug' => 'acme']);
        $role = Role::forceCreate(['name' => 'Editor', 'slug' => 'editor']);
        $user = User::forceCreate([
            'name' => 'John Doe',
            'email' => 'john@test.com',
            'password' => bcrypt('password'),
        ]);

        UserRole::forceCreate([
            'user_id' => $user->id,
            'role_id' => $role->id,
            'organization_id' => $org->id,
            'permissions' => ['*'],
        ]);

        return OrganizationInvitation::create([
            'organization_id' => $org->id,
            'email' => 'invitee@test.com',
            'role_id' => $role->id,
            'invited_by' => $user->id,
            'expires_at' => now()->addDays(7),
        ]);
    }

    // ------------------------------------------------------------------
    // Tests
    // ------------------------------------------------------------------

    public function test_notification_builds_with_correct_subject(): void
    {
        $invitation = $this->createInvitationWithRelations();
        $notification = new InvitationNotification($invitation);

        $built = $notification->build();

        $this->assertStringContainsString('Acme Corp', $built->subject);
    }

    protected function renderNotification(OrganizationInvitation $invitation): string
    {
        $notification = new InvitationNotification($invitation);
        $notification->build();
        return $notification->render();
    }

    public function test_notification_contains_accept_link(): void
    {
        $invitation = $this->createInvitationWithRelations();
        $html = $this->renderNotification($invitation);

        $this->assertStringContainsString("token={$invitation->token}", $html);
        $this->assertStringContainsString('Accept Invitation', $html);
    }

    public function test_notification_contains_organization_name(): void
    {
        $invitation = $this->createInvitationWithRelations();
        $html = $this->renderNotification($invitation);

        $this->assertStringContainsString('Acme Corp', $html);
    }

    public function test_notification_contains_role_name(): void
    {
        $invitation = $this->createInvitationWithRelations();
        $html = $this->renderNotification($invitation);

        $this->assertStringContainsString('Editor', $html);
    }

    public function test_notification_contains_inviter_name(): void
    {
        $invitation = $this->createInvitationWithRelations();
        $html = $this->renderNotification($invitation);

        $this->assertStringContainsString('John Doe', $html);
    }

    public function test_notification_contains_expiration_date(): void
    {
        $invitation = $this->createInvitationWithRelations();
        $html = $this->renderNotification($invitation);

        $expectedDate = $invitation->expires_at->format('F j, Y');
        $this->assertStringContainsString($expectedDate, $html);
    }

    public function test_notification_uses_frontend_url_env(): void
    {
        putenv('FRONTEND_URL=https://myapp.com');

        $invitation = $this->createInvitationWithRelations();
        $html = $this->renderNotification($invitation);

        $this->assertStringContainsString('https://myapp.com/accept-invitation', $html);

        putenv('FRONTEND_URL');
    }

    public function test_notification_uses_default_frontend_url(): void
    {
        putenv('FRONTEND_URL');

        $invitation = $this->createInvitationWithRelations();
        $html = $this->renderNotification($invitation);

        $this->assertStringContainsString('http://localhost:5173/accept-invitation', $html);
    }

    public function test_notification_is_mailable(): void
    {
        $invitation = $this->createInvitationWithRelations();
        $notification = new InvitationNotification($invitation);

        $this->assertInstanceOf(\Illuminate\Mail\Mailable::class, $notification);
    }

    public function test_notification_stores_invitation_as_property(): void
    {
        $invitation = $this->createInvitationWithRelations();
        $notification = new InvitationNotification($invitation);

        $this->assertSame($invitation->id, $notification->invitation->id);
    }
}
