<?php

namespace App\Events\Chat;

use App\Models\Chat\ChatMessage;
use App\Services\Chat\ChatEngine;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class MessageCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public ChatMessage $message;

    public string $chatPublicId;

    public string $chatType;

    public function __construct(ChatMessage $message)
    {
        // Load required relations for proper display
        $this->message = $message->load('user:id,public_id,name', 'media', 'chat');
        $this->chatPublicId = $message->chat->public_id;
        $this->chatType = $message->chat->type ?? 'dm';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'message' => ChatEngine::normalizeOne($this->message),
        ];
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): PrivateChannel
    {
        $prefix = $this->chatType === 'dm' ? 'dm' : 'group';

        return new PrivateChannel("{$prefix}.{$this->chatPublicId}");
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'MessageCreated';
    }
}
