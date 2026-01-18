<?php

namespace App\Events\Chat;

use App\Models\Chat\ChatMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Event broadcasted to confirm a message was saved.
 * Sent to the sender's private channel to replace their optimistic temp message.
 */
class MessageConfirmed implements ShouldBroadcastNow
{
    use Dispatchable;
    use InteractsWithSockets;
    use SerializesModels;

    public function __construct(
        public ChatMessage $message,
        public string $tempId,
        public string $userPublicId
    ) {
        // Eager load user and chat relations
        $this->message->loadMissing('user:id,public_id,name', 'chat:id,public_id');
    }

    public function broadcastOn(): PrivateChannel
    {
        $channel = "user.{$this->userPublicId}";
        Log::debug('[MessageConfirmed] Broadcasting', ['channel' => $channel, 'temp_id' => $this->tempId]);

        return new PrivateChannel($channel);
    }

    public function broadcastAs(): string
    {
        return 'MessageConfirmed';
    }

    public function broadcastWith(): array
    {
        return [
            'message' => [
                'id' => $this->message->public_id, // Map Public ID
                'chat_id' => $this->message->chat->public_id,
                'content' => $this->message->content,
                'user_public_id' => $this->message->user?->public_id,
                'user_name' => $this->message->user?->name,
                'user_avatar' => $this->message->user?->avatar_url,
                'created_at' => $this->message->created_at?->toIso8601String(),
            ],
            'temp_id' => $this->tempId,
        ];
    }
}
