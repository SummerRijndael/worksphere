<?php

namespace Tests\Feature;

use App\Models\Event;
use App\Models\User;
use App\Notifications\EventReminder;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Notification;
use Tests\TestCase;

class CalendarTest extends TestCase
{
    use RefreshDatabase;

    public function test_user_can_create_event()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        $response = $this->postJson('/api/calendar/events', [
            'title' => 'Test Event',
            'start_time' => now()->addHour()->toDateTimeString(),
            'end_time' => now()->addHours(2)->toDateTimeString(),
            'reminder_minutes_before' => 15,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('events', [
            'title' => 'Test Event',
            'user_id' => $user->id,
        ]);
    }

    public function test_user_can_list_events()
    {
        $user = User::factory()->create();
        $this->actingAs($user);

        Event::create([
            'user_id' => $user->id,
            'title' => 'Existing Event',
            'start_time' => now()->startOfWeek(),
            'end_time' => now()->startOfWeek()->addHour(),
            'is_all_day' => false,
        ]);

        $response = $this->getJson('/api/calendar/events?start='.now()->startOfMonth()->toDateString().'&end='.now()->endOfMonth()->toDateString());

        $response->assertStatus(200)
            ->assertJsonCount(1);
    }

    public function test_user_can_invite_attendees()
    {
        $user = User::factory()->create();
        $otherUser = User::factory()->create();
        $this->actingAs($user);

        $response = $this->postJson('/api/calendar/events', [
            'title' => 'Meeting with Attendee',
            'start_time' => now()->addDay()->toDateTimeString(),
            'end_time' => now()->addDay()->addHour()->toDateTimeString(),
            'attendees' => [$otherUser->public_id],
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('event_attendees', [
            'user_id' => $otherUser->id,
            'status' => 'pending',
        ]);
    }

    public function test_reminder_notification_is_sent()
    {
        $now = Carbon::parse('2025-01-01 12:00:00');
        Carbon::setTestNow($now);

        Notification::fake();
        $user = User::factory()->create();

        $event = Event::create([
            'user_id' => $user->id,
            'title' => 'Upcoming Meeting',
            'start_time' => '2025-01-01 12:15:00', // Exactly 15 mins later
            'end_time' => '2025-01-01 13:00:00',
            'reminder_minutes_before' => 15,
            'is_all_day' => false,
        ]);

        // Invoke command directly to share state/mock time
        $command = new \App\Console\Commands\SendEventReminders;
        $command->setLaravel($this->app);

        // Setup input/output to avoid null pointer on $this->info()
        $input = new \Symfony\Component\Console\Input\ArrayInput([]);
        $bufferedOutput = new \Symfony\Component\Console\Output\BufferedOutput;
        $output = new \Illuminate\Console\OutputStyle($input, $bufferedOutput);

        $command->setInput($input);
        $command->setOutput($output);

        $command->handle();

        $content = $bufferedOutput->fetch();
        dump($content); // View output in case of failure

        // Assert logic worked
        $this->assertStringContainsString('Sending reminder for event: Upcoming Meeting', $content);

        Notification::assertSentTo(
            $user,
            EventReminder::class
        );

        $this->assertNotNull($event->fresh()->notification_sent_at);
    }

    public function test_reminder_not_sent_too_early()
    {
        Notification::fake();
        $user = User::factory()->create();

        Event::create([
            'user_id' => $user->id,
            'title' => 'Future Meeting',
            'start_time' => now()->addHour(), // 60 mins away
            'end_time' => now()->addHours(2),
            'reminder_minutes_before' => 15, // buffer is 15
            'is_all_day' => false,
        ]);

        // Run command
        $this->artisan('events:send-reminders')
            ->assertExitCode(0);

        Notification::assertNothingSent();
    }
}
