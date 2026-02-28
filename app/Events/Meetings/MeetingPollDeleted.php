<?php

namespace App\Events\Meetings;

use App\Models\Meeting;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MeetingPollDeleted implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public Meeting $meeting,
        public string $pollId
    ) {}

    public function broadcastOn(): array
    {
        return [
            new PresenceChannel("meeting.{$this->meeting->public_id}"),
        ];
    }
}
