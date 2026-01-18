<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\Team;
use App\Models\TeamEvent;
use App\Models\User;
use App\Notifications\EventInviteNotification;
use App\Services\CalendarExportService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CalendarInviteExportTest extends TestCase
{
    use RefreshDatabase;

    public function test_organizer_can_send_invite_to_attendees(): void
    {
        Notification::fake();

        $organizer = User::factory()->create();
        $attendee = User::factory()->create();

        $event = Event::create([
            'user_id' => $organizer->id,
            'title' => 'Test Event',
            'description' => 'Test Description',
            'start_time' => now()->addDay(),
            'end_time' => now()->addDay()->addHour(),
            'is_all_day' => false,
        ]);

        $event->attendees()->attach($attendee->id);

        $this->actingAs($organizer)
            ->postJson("/api/calendar/events/{$event->id}/invite")
            ->assertStatus(200)
            ->assertJson(['message' => 'Invites sent to 1 attendee(s).']);

        Notification::assertSentTo($attendee, EventInviteNotification::class);
    }

    public function test_non_organizer_cannot_send_invites(): void
    {
        $organizer = User::factory()->create();
        $attendee = User::factory()->create();
        $otherUser = User::factory()->create();

        $event = Event::create([
            'user_id' => $organizer->id,
            'title' => 'Test Event',
            'start_time' => now()->addDay(),
            'end_time' => now()->addDay()->addHour(),
            'is_all_day' => false,
        ]);

        $event->attendees()->attach($attendee->id);

        $this->actingAs($otherUser)
            ->postJson("/api/calendar/events/{$event->id}/invite")
            ->assertStatus(403);
    }

    public function test_user_can_download_event_ics(): void
    {
        $user = User::factory()->create();

        $event = Event::create([
            'user_id' => $user->id,
            'title' => 'Download Test Event',
            'description' => 'Event for ICS download',
            'start_time' => now()->addDay(),
            'end_time' => now()->addDay()->addHour(),
            'location' => 'Conference Room A',
            'is_all_day' => false,
        ]);

        $response = $this->actingAs($user)
            ->get("/api/calendar/events/{$event->id}/ics");

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'text/calendar; charset=UTF-8');

        $content = $response->getContent();
        $this->assertStringContainsString('BEGIN:VCALENDAR', $content);
        $this->assertStringContainsString('BEGIN:VEVENT', $content);
        $this->assertStringContainsString('SUMMARY:Download Test Event', $content);
        $this->assertStringContainsString('END:VCALENDAR', $content);
    }

    public function test_attendee_can_download_event_ics(): void
    {
        $organizer = User::factory()->create();
        $attendee = User::factory()->create();

        $event = Event::create([
            'user_id' => $organizer->id,
            'title' => 'Shared Event',
            'start_time' => now()->addDay(),
            'end_time' => now()->addDay()->addHour(),
            'is_all_day' => false,
        ]);

        $event->attendees()->attach($attendee->id);

        $this->actingAs($attendee)
            ->get("/api/calendar/events/{$event->id}/ics")
            ->assertStatus(200)
            ->assertHeader('Content-Type', 'text/calendar; charset=UTF-8');
    }

    public function test_user_can_export_events_in_date_range(): void
    {
        $user = User::factory()->create();

        // Create events in range
        Event::create([
            'user_id' => $user->id,
            'title' => 'Event 1',
            'start_time' => now()->addDay(),
            'end_time' => now()->addDay()->addHour(),
            'is_all_day' => false,
        ]);

        Event::create([
            'user_id' => $user->id,
            'title' => 'Event 2',
            'start_time' => now()->addDays(2),
            'end_time' => now()->addDays(2)->addHour(),
            'is_all_day' => false,
        ]);

        $response = $this->actingAs($user)
            ->get('/api/calendar/events/export?'.http_build_query([
                'start' => now()->toDateString(),
                'end' => now()->addWeek()->toDateString(),
            ]));

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'text/calendar; charset=UTF-8');

        $content = $response->getContent();
        $this->assertStringContainsString('SUMMARY:Event 1', $content);
        $this->assertStringContainsString('SUMMARY:Event 2', $content);
    }

    public function test_ics_file_format_is_valid(): void
    {
        $user = User::factory()->create();
        $event = Event::create([
            'user_id' => $user->id,
            'title' => 'Test Event with Location',
            'description' => 'Test description',
            'start_time' => now()->addDay(),
            'end_time' => now()->addDay()->addHour(),
            'location' => 'Meeting Room B',
            'reminder_minutes_before' => 15,
            'is_all_day' => false,
        ]);

        $service = new CalendarExportService;
        $ics = $service->generateIcs($event);

        // Validate ICS structure
        $this->assertStringContainsString('BEGIN:VCALENDAR', $ics);
        $this->assertStringContainsString('VERSION:2.0', $ics);
        $this->assertStringContainsString('PRODID:', $ics);
        $this->assertStringContainsString('BEGIN:VEVENT', $ics);
        $this->assertStringContainsString('UID:', $ics);
        $this->assertStringContainsString('DTSTAMP:', $ics);
        $this->assertStringContainsString('DTSTART:', $ics);
        $this->assertStringContainsString('DTEND:', $ics);
        $this->assertStringContainsString('SUMMARY:Test Event with Location', $ics);
        $this->assertStringContainsString('DESCRIPTION:Test description', $ics);
        $this->assertStringContainsString('LOCATION:Meeting Room B', $ics);
        $this->assertStringContainsString('BEGIN:VALARM', $ics);
        $this->assertStringContainsString('TRIGGER:-PT15M', $ics);
        $this->assertStringContainsString('END:VEVENT', $ics);
        $this->assertStringContainsString('END:VCALENDAR', $ics);
    }

    public function test_team_event_invite_sends_email_with_ics(): void
    {
        Mail::fake();

        $owner = User::factory()->create();
        $member = User::factory()->create();

        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->members()->attach($owner->id, ['role' => 'owner']);
        $team->members()->attach($member->id, ['role' => 'member']);

        $event = TeamEvent::create([
            'team_id' => $team->id,
            'user_id' => $owner->id,
            'title' => 'Team Meeting',
            'start_time' => now()->addDay(),
            'end_time' => now()->addDay()->addHour(),
            'color' => '#3B82F6',
            'is_all_day' => false,
        ]);

        $event->participants()->attach($member->id);

        $this->actingAs($owner)
            ->postJson("/api/teams/{$team->public_id}/events/{$event->public_id}/invite")
            ->assertStatus(200)
            ->assertJson(['message' => 'Invites sent to 1 participant(s).']);

        Mail::assertQueued(\App\Mail\TeamEventInvitation::class, function ($mail) use ($member) {
            return $mail->hasTo($member->email);
        });
    }

    public function test_team_member_can_download_team_event_ics(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();

        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->members()->attach($owner->id, ['role' => 'owner']);
        $team->members()->attach($member->id, ['role' => 'member']);

        $event = TeamEvent::create([
            'team_id' => $team->id,
            'user_id' => $owner->id,
            'title' => 'Team Event for Download',
            'start_time' => now()->addDay(),
            'end_time' => now()->addDay()->addHour(),
            'color' => '#10B981',
            'is_all_day' => false,
        ]);

        $response = $this->actingAs($member)
            ->get("/api/teams/{$team->public_id}/events/{$event->public_id}/ics");

        $response->assertStatus(200)
            ->assertHeader('Content-Type', 'text/calendar; charset=UTF-8');

        $content = $response->getContent();
        $this->assertStringContainsString('SUMMARY:Team Event for Download', $content);
    }

    public function test_unauthorized_user_cannot_send_team_event_invites(): void
    {
        $owner = User::factory()->create();
        $member = User::factory()->create();
        $outsider = User::factory()->create();

        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $team->members()->attach($owner->id, ['role' => 'owner']);
        $team->members()->attach($member->id, ['role' => 'member']);

        $event = TeamEvent::create([
            'team_id' => $team->id,
            'user_id' => $owner->id,
            'title' => 'Private Team Event',
            'start_time' => now()->addDay(),
            'end_time' => now()->addDay()->addHour(),
            'color' => '#EF4444',
            'is_all_day' => false,
        ]);

        $event->participants()->attach($member->id);

        // Outsider (not member) should be forbidden
        $this->actingAs($outsider)
            ->postJson("/api/teams/{$team->public_id}/events/{$event->public_id}/invite")
            ->assertStatus(403);
    }

    public function test_all_day_event_ics_format(): void
    {
        $user = User::factory()->create();
        $event = Event::create([
            'user_id' => $user->id,
            'title' => 'All Day Event',
            'start_time' => now()->addDay()->startOfDay(),
            'end_time' => now()->addDay()->endOfDay(),
            'is_all_day' => true,
        ]);

        $service = new CalendarExportService;
        $ics = $service->generateIcs($event);

        // All-day events should use VALUE=DATE format
        $this->assertStringContainsString('DTSTART;VALUE=DATE:', $ics);
        $this->assertStringContainsString('DTEND;VALUE=DATE:', $ics);
    }
}
