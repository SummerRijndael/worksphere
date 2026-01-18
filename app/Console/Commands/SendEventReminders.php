<?php

namespace App\Console\Commands;

use App\Models\Event;
use App\Notifications\EventReminder;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;

class SendEventReminders extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'events:send-reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Send reminders for upcoming events';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $now = Carbon::now();

        // Find events that:
        // 1. Have reminders enabled (reminder_minutes_before > 0)
        // 2. Are not all day (handling all day is trickier, skipping for MVP/assuming 9am)
        // 3. Haven't sent notification yet (notification_sent_at is null)
        // 4. Start time is within (reminder_minutes + buffer) range
        // Logic: if start_time <= now + reminder_minutes AND start_time > now

        // Check for events needing reminder NOW
        // Optimization: query events starting in the next 24 hours to limit set, then filter.
        // Or better: whereRaw based on calculation.

        $events = Event::whereNotNull('reminder_minutes_before')
            ->whereNull('notification_sent_at')
            ->where('start_time', '>', $now) // Only future events (or strictly current)
            ->where('start_time', '<=', $now->clone()->addHours(24)) // Optimization
            ->get();

        $count = 0;
        foreach ($events as $event) {
            $reminderTime = $event->start_time->copy()->subMinutes($event->reminder_minutes_before);

            // If we have passed the reminder time (or are within 1 minute of it)
            if ($now->greaterThanOrEqualTo($reminderTime)) {
                $this->info("Sending reminder for event: {$event->title}");

                // Notify Organizer
                $event->organizer->notify(new EventReminder($event));

                // Notify Attendees (accepted only?)
                foreach ($event->attendees as $attendee) {
                    if ($attendee->pivot->status === 'accepted') {
                        $attendee->notify(new EventReminder($event));
                    }
                }

                $event->update(['notification_sent_at' => $now]);
                $count++;
            }
        }

        $this->info("Sent {$count} reminders for Personal Events.");

        // --- Process Team Events ---
        $teamEvents = \App\Models\TeamEvent::whereNotNull('reminder_minutes_before')
            ->whereNull('notification_sent_at')
            ->where('start_time', '>', $now)
            ->where('start_time', '<=', $now->clone()->addHours(24))
            ->get();

        $teamCount = 0;
        foreach ($teamEvents as $event) {
            $reminderTime = $event->start_time->copy()->subMinutes($event->reminder_minutes_before);

            if ($now->greaterThanOrEqualTo($reminderTime)) {
                $this->info("Sending reminder for Team Event: {$event->title}");

                // Notify Creator
                if ($event->creator) {
                    $event->creator->notify(new EventReminder($event));
                }

                // Notify Participants (accepted only)
                foreach ($event->participants as $participant) {
                    if ($participant->pivot->status === 'accepted') {
                        $participant->notify(new EventReminder($event));
                    }
                }

                $event->update(['notification_sent_at' => $now]);
                $teamCount++;
            }
        }

        $this->info("Sent {$teamCount} reminders for Team Events.");
    }
}
