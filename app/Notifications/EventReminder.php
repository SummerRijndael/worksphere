<?php

namespace App\Notifications;

use App\Models\Event;
use App\Models\TeamEvent;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class EventReminder extends Notification implements ShouldBroadcastNow, ShouldQueue
{
    use Queueable;

    public function __construct(public Event|TeamEvent $event)
    {
        //
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'event_id' => $this->event->id,
            'title' => 'Reminder: '.$this->event->title,
            'start_time' => $this->event->start_time,
            'message' => 'Your event "'.$this->event->title.'" starts in '.$this->event->reminder_minutes_before.' minutes.',
            'type' => 'event_reminder', // Helpful for frontend handling
        ];
    }

    /**
     * Get the broadcastable representation of the notification.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'id' => $this->id,
            'data' => $this->toArray($notifiable),
            'read_at' => null,
            'created_at' => now()->toIso8601String(),
        ]);
    }
}
