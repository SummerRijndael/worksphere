<?php

namespace App\Events\Meetings;

use App\Models\Meeting;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\Channel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MeetingSignal implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $meetingPublicId;

    public string $senderParticipantPublicId;

    public string $signalType; // 'offer', 'answer', 'ice-candidate', 'participant-joined', etc.

    public array $signalData;

    public ?string $targetParticipantPublicId;

    /**
     * Create a new event instance.
     */
    public function __construct(Meeting $meeting, string $senderParticipantPublicId, string $signalType, array $signalData, ?string $targetParticipantPublicId = null)
    {
        $this->meetingPublicId = $meeting->public_id;
        $this->senderParticipantPublicId = $senderParticipantPublicId;
        $this->signalType = $signalType;
        $this->signalData = $signalData;
        $this->targetParticipantPublicId = $targetParticipantPublicId;
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
            'sender_participant_public_id' => $this->senderParticipantPublicId,
            'signal_type' => $this->signalType,
            'signal_data' => $this->signalData,
            'target_participant_public_id' => $this->targetParticipantPublicId,
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
        return 'MeetingSignal';
    }
}
