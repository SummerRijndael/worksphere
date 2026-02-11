<?php

namespace App\Events\Chat;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CallParticipantJoined implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $chatPublicId;

    public string $chatType;

    public string $callId;

    public string $participantPublicId;

    public string $participantName;

    public ?string $participantAvatar;

    public function __construct(string $chatPublicId, string $chatType, string $callId, string $participantPublicId, string $participantName, ?string $participantAvatar)
    {
        $this->chatPublicId = $chatPublicId;
        $this->chatType = $chatType;
        $this->callId = $callId;
        $this->participantPublicId = $participantPublicId;
        $this->participantName = $participantName;
        $this->participantAvatar = $participantAvatar;
    }

    public function broadcastWith(): array
    {
        return [
            'call_id' => $this->callId,
            'participant_public_id' => $this->participantPublicId,
            'participant_name' => $this->participantName,
            'participant_avatar' => $this->participantAvatar,
        ];
    }

    public function broadcastOn(): PrivateChannel
    {
        $prefix = $this->chatType === 'dm' ? 'dm' : 'group';

        return new PrivateChannel("{$prefix}.{$this->chatPublicId}");
    }

    public function broadcastAs(): string
    {
        return 'CallParticipantJoined';
    }
}
