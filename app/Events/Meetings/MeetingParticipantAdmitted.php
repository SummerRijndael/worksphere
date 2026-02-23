<?php

namespace App\Events\Meetings;

use App\Models\Meeting;
use App\Models\MeetingParticipant;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MeetingParticipantAdmitted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $meetingPublicId;
    public string $participantPublicId;

    /**
     * Create a new event instance.
     */
    public function __construct(Meeting $meeting, MeetingParticipant $participant)
    {
        $this->meetingPublicId = $meeting->public_id;
        $this->participantPublicId = $participant->public_id;
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
            'participant_public_id' => $this->participantPublicId,
            'status' => 'admitted',
        ];
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): PresenceChannel
    {
        return new PresenceChannel("meeting.{$this->meetingPublicId}");
    }

    /**
     * The event name to broadcast as.
     */
    public function broadcastAs(): string
    {
        return 'MeetingParticipantAdmitted';
    }
}
