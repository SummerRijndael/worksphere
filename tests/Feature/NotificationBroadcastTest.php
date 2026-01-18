<?php

namespace Tests\Feature;

use App\Models\RoleChangeRequest;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use App\Notifications\InvitationResponseNotification;
use App\Notifications\RoleChangeRequestNotification;
use App\Notifications\SystemNotification;
use App\Notifications\TeamInvitationNotification;
use App\Notifications\TicketDeadlineReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Notification;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NotificationBroadcastTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_invitation_notification_is_broadcast()
    {
        Notification::fake();

        $user = User::factory()->create();
        $inviter = User::factory()->create();
        $team = Team::factory()->create();

        $user->notify(new TeamInvitationNotification($team, $inviter));

        Notification::assertSentTo(
            [$user],
            TeamInvitationNotification::class,
            function ($notification, $channels) {
                return in_array('broadcast', $channels);
            }
        );
    }

    public function test_ticket_deadline_reminder_is_broadcast()
    {
        Notification::fake();

        $user = User::factory()->create();
        $ticket = Ticket::factory()->create([
            'reporter_id' => $user->id,
            'title' => 'Urgent Ticket',
            'due_date' => now()->addDay(),
        ]);

        $user->notify(new TicketDeadlineReminder($ticket));

        Notification::assertSentTo(
            [$user],
            TicketDeadlineReminder::class,
            function ($notification, $channels) {
                return in_array('broadcast', $channels);
            }
        );
    }

    public function test_role_change_request_notification_is_broadcast()
    {
        Notification::fake();

        $admin = User::factory()->create();
        $requester = User::factory()->create();
        $targetRole = Role::create(['name' => 'admin', 'guard_name' => 'web']);

        $request = RoleChangeRequest::create([
            'user_id' => $requester->id,
            'role_id' => $targetRole->id,
            'type' => 'role_permission_change',
            'requested_changes' => ['permissions' => ['create_posts']],
            'reason' => 'Promotion',
            'status' => 'pending',
            'requested_by' => $requester->id,
            'expires_at' => now()->addWeek(),
            'required_approvals' => 1,
        ]);

        $admin->notify(new RoleChangeRequestNotification($request));

        Notification::assertSentTo(
            [$admin],
            RoleChangeRequestNotification::class,
            function ($notification, $channels) {
                return in_array('broadcast', $channels);
            }
        );
    }

    public function test_system_notification_is_broadcast()
    {
        Notification::fake();

        $user = User::factory()->create();

        $user->notify(new SystemNotification(
            'system',
            'Welcome',
            'Welcome to the system'
        ));

        Notification::assertSentTo(
            [$user],
            SystemNotification::class,
            function ($notification, $channels) {
                return in_array('broadcast', $channels);
            }
        );
    }

    public function test_invitation_response_notification_is_broadcast()
    {
        Notification::fake();

        $inviter = User::factory()->create();
        $responder = User::factory()->create();
        $team = Team::factory()->create();

        $inviter->notify(new InvitationResponseNotification($responder, $team, 'accepted'));

        Notification::assertSentTo(
            [$inviter],
            InvitationResponseNotification::class,
            function ($notification, $channels) {
                return in_array('broadcast', $channels);
            }
        );
    }
}
