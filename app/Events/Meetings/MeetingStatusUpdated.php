<?php

namespace App\Events\Meetings;

use App\Models\Meeting;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MeetingStatusUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(public Meeting $meeting)
    {
        $this->meeting->loadMissing(['host', 'event.attendees']);
    }

    public function broadcastOn(): array
    {
        $channels = [];

        // Broadcast to host
        if ($this->meeting->user_id) {
            $channels[] = new PrivateChannel('user.'.$this->meeting->user_id);
        }

        // Broadcast to invited attendees
        if ($this->meeting->event) {
            foreach ($this->meeting->event->attendees as $attendee) {
                if ($attendee->id !== $this->meeting->user_id) {
                    $channels[] = new PrivateChannel('user.'.$attendee->id);
                }
            }
        }

        return $channels;
    }

    public function broadcastWith(): array
    {
        return [
            'id' => $this->meeting->public_id,
            'status' => $this->meeting->status,
        ];
    }
}
