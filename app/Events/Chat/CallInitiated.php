<?php

namespace App\Events\Chat;

use App\Models\Chat\Chat;
use App\Models\User;
use App\Services\Chat\PresenceService;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class CallInitiated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $chatPublicId;

    public string $chatType;

    public string $callerPublicId;

    public string $callerName;

    public ?string $callerAvatar;

    public string $callId;

    public string $callType; // 'video' or 'audio'

    /** @var string[] Public IDs of users who were skipped due to busy/offline status */
    public array $filteredParticipants = [];

    public function __construct(Chat $chat, User $caller, string $callId, string $callType = 'video')
    {
        $this->chatPublicId = $chat->public_id;
        $this->chatType = $chat->type ?? 'dm';
        $this->callerPublicId = $caller->public_id;
        $this->callerName = $caller->name;
        $this->callerAvatar = $caller->avatar_thumb_url;
        $this->callId = $callId;
        $this->callType = $callType;
    }

    public function broadcastWith(): array
    {
        return [
            'call_id' => $this->callId,
            'call_type' => $this->callType,
            'caller_public_id' => $this->callerPublicId,
            'caller_name' => $this->callerName,
            'caller_avatar' => $this->callerAvatar,
            'chat_id' => $this->chatPublicId,
            'chat_type' => $this->chatType,
        ];
    }

    public function broadcastOn(): array
    {
        $prefix = $this->chatType === 'dm' ? 'dm' : 'group';
        $channels = [new PrivateChannel("{$prefix}.{$this->chatPublicId}")];

        // Get all participants in the chat to notify them on their user channel
        $chat = Chat::where('public_id', $this->chatPublicId)->first();
        if ($chat) {
            $presenceService = app(PresenceService::class);

            foreach ($chat->participants as $participant) {
                // Broadcast to all participants EXCEPT the caller
                if ($participant->public_id !== $this->callerPublicId) {
                    $status = $presenceService->presenceStatus($participant->id);

                    // Skip busy and offline users — they should not be disturbed
                    // Away and invisible users still ring normally
                    if (in_array($status, ['busy', 'offline'])) {
                        $this->filteredParticipants[] = $participant->public_id;
                        continue;
                    }

                    $channels[] = new PrivateChannel("user.{$participant->public_id}");
                }
            }
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'CallInitiated';
    }
}
