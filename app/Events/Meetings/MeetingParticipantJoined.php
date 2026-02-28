<?php

namespace App\Events\Meetings;

use App\Http\Resources\MeetingParticipantResource;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MeetingParticipantJoined implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $meetingPublicId;

    public $participant;

    /**
     * Create a new event instance.
     */
    public function __construct(Meeting $meeting, MeetingParticipant $participant)
    {
        $this->meetingPublicId = $meeting->public_id;
        // We load the user relation to ensure the host sees the name/avatar
        $this->participant = new MeetingParticipantResource($participant->load('user'));
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'meeting_public_id' => $this->meetingPublicId,
            'participant' => $this->participant->resolve(),
        ];
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): \Illuminate\Broadcasting\PresenceChannel
    {
        return new \Illuminate\Broadcasting\PresenceChannel("meeting.{$this->meetingPublicId}");
    }

    /**
     * The event name to broadcast as.
     */
    public function broadcastAs(): string
    {
        return 'MeetingParticipantJoined';
    }
}
