<?php

namespace App\Events\Meetings;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MeetingLaserPointerMoved implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public readonly string $meetingPublicId,
        public readonly string $participantPublicId,
        public readonly float  $x,  // percentage 0–100 of viewport width
        public readonly float  $y,  // percentage 0–100 of viewport height
    ) {}

    public function broadcastWith(): array
    {
        return [
            'participant_id' => $this->participantPublicId,
            'x'  => $this->x,
            'y'  => $this->y,
        ];
    }

    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel("meeting.{$this->meetingPublicId}");
    }

    public function broadcastAs(): string
    {
        return 'MeetingLaserPointerMoved';
    }
}
