<?php

namespace WorkSphere\Chat\Events;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;
use WorkSphere\Chat\Models\ChatMessage;

class MessageCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public ChatMessage $message
    ) {
        $this->message->load(['sender', 'chat']);
    }

    /**
     * Get the channels the event should broadcast on.
     */
    public function broadcastOn(): PrivateChannel
    {
        $prefix = $this->message->chat->type === 'dm' ? 'pkg.dm' : 'pkg.group';

        return new PrivateChannel("{$prefix}.{$this->message->chat->public_id}");
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'MessageCreated';
    }

    /**
     * Get the data to broadcast.
     */
    public function broadcastWith(): array
    {
        return [
            'message' => [
                'id' => $this->message->public_id,
                'content' => $this->message->content,
                'type' => $this->message->type,
                'user_name' => $this->message->sender->name ?? 'User',
                'user_avatar' => $this->message->sender->avatar_url ?? null,
                'created_at' => $this->message->created_at->toIso8601String(),
            ]
        ];
    }
}
