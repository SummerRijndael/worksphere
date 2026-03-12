<?php

namespace App\Events\Chat;

use App\Models\Chat\ChatMessage;
use App\Services\Chat\ChatEngine;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ChatMessage $message;

    public string $chatPublicId;

    public string $chatType;

    public function __construct(ChatMessage $message)
    {
        $this->message = $message->loadMissing('user:id,public_id,name', 'media', 'replyTo.user:id,public_id,name', 'chat');
        $this->chatPublicId = $message->chat->public_id;
        $this->chatType = $message->chat->type ?? 'dm';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'message' => ChatEngine::normalizeOne($this->message),
        ];
    }

    public function broadcastOn(): PrivateChannel
    {
        $prefix = $this->chatType === 'dm' ? 'dm' : 'group';

        return new PrivateChannel("{$prefix}.{$this->chatPublicId}");
    }

    public function broadcastAs(): string
    {
        return 'MessageUpdated';
    }
}
