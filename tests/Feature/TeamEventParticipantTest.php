<?php

namespace Tests\Feature;

use App\Mail\TeamEventInvitation;
use App\Models\Team;
use App\Models\TeamEvent;
use App\Models\User;
use App\Notifications\EventReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class TeamEventParticipantTest extends TestCase
{
    use RefreshDatabase;

    public function test_team_event_can_add_participants()
    {
        Mail::fake();

        $user = User::factory()->create();
        $participant1 = User::factory()->create();
        $participant2 = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->members()->attach($participant1, ['role' => 'member']);
        $team->members()->attach($participant2, ['role' => 'member']);

        $this->actingAs($user);

        $response = $this->postJson("/api/teams/{$team->public_id}/events", [
            'title' => 'Meeting',
            'start_time' => now()->addDay()->toIso8601String(),
            'end_time' => now()->addDay()->addHour()->toIso8601String(),
            'color' => '#000000',
            'participants' => [$participant1->id, $participant2->id],
            'reminder_minutes_before' => 30,
        ]);

        $response->assertStatus(201);

        $event = TeamEvent::first();
        $this->assertCount(2, $event->participants);
        $this->assertEquals('pending', $event->participants->find($participant1->id)->pivot->status);

        // Verify Mailable
        Mail::assertQueued(TeamEventInvitation::class, function ($mail) use ($event, $participant1) {
            return $mail->event->id === $event->id && $mail->hasTo($participant1->email);
        });
        Mail::assertQueued(TeamEventInvitation::class, 2); // Sent to both
    }

    public function test_reminders_command_sends_notifications()
    {
        Notification::fake();

        $user = User::factory()->create();
        $participant = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);

        // Create event starting in 30 mins, with 30 min reminder
        $startTime = now()->addMinutes(30);

        $event = TeamEvent::create([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'title' => 'Reminder Test',
            'start_time' => $startTime,
            'end_time' => $startTime->copy()->addHour(),
            'color' => '#000000',
            'reminder_minutes_before' => 30,
            'is_all_day' => false,
        ]);

        $event->participants()->attach($participant, ['status' => 'accepted']);
        // Another participant who rejected
        $rejector = User::factory()->create();
        $event->participants()->attach($rejector, ['status' => 'rejected']);

        // Run command
        $this->artisan('events:send-reminders')
            ->expectsOutputToContain('Sending reminder for Team Event: Reminder Test')
            ->assertExitCode(0);

        // Assert Notification sent to Creator
        Notification::assertSentTo($user, EventReminder::class);

        // Assert Notification sent to Accepted Participant
        Notification::assertSentTo($participant, EventReminder::class);

        // Assert Notification NOT sent to Rejected Participant
        Notification::assertNotSentTo($rejector, EventReminder::class);

        // verify notification_sent_at updated
        $this->assertNotNull($event->fresh()->notification_sent_at);
    }
}
