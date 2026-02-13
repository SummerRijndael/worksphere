<?php

namespace App\Jobs;

use App\Models\Event;
use App\Models\TeamEvent;
use App\Notifications\EventReminder;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Carbon;

class SendEventRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $now = Carbon::now();

        // 1. Process Personal Events
        $events = Event::whereNotNull('reminder_minutes_before')
            ->whereNull('notification_sent_at')
            ->where('start_time', '>', $now)
            ->where('start_time', '<=', $now->clone()->addHours(24))
            ->get();

        foreach ($events as $event) {
            $reminderTime = $event->start_time->copy()->subMinutes($event->reminder_minutes_before);

            if ($now->greaterThanOrEqualTo($reminderTime)) {
                // Notify Organizer
                $event->organizer->notify(new EventReminder($event));

                // Notify Attendees (accepted only)
                foreach ($event->attendees as $attendee) {
                    if ($attendee->pivot->status === 'accepted') {
                        $attendee->notify(new EventReminder($event));
                    }
                }

                $event->update(['notification_sent_at' => $now]);
            }
        }

        // 2. Process Team Events
        $teamEvents = TeamEvent::whereNotNull('reminder_minutes_before')
            ->whereNull('notification_sent_at')
            ->where('start_time', '>', $now)
            ->where('start_time', '<=', $now->clone()->addHours(24))
            ->get();

        foreach ($teamEvents as $event) {
            $reminderTime = $event->start_time->copy()->subMinutes($event->reminder_minutes_before);

            if ($now->greaterThanOrEqualTo($reminderTime)) {
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
            }
        }
    }
}
