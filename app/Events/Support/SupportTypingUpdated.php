<?php

namespace App\Events\Support;

use App\Models\SupportConversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupportTypingUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public SupportConversation $conversation,
        public string $actorType = 'customer',
        public string $actorName = 'Customer',
        public bool $isTyping = true
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel("support.customer.{$this->conversation->public_id}"),
            new PrivateChannel("support.agent.{$this->conversation->public_id}"),
        ];
    }

    public function broadcastAs(): string
    {
        return 'SupportTypingUpdated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversation->public_id,
            'actor_type' => $this->actorType,
            'actor_name' => $this->actorName,
            'is_typing' => $this->isTyping,
            'updated_at' => now()->toISOString(),
        ];
    }
}
