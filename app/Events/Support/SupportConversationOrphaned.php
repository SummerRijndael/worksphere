<?php

namespace App\Events\Support;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupportConversationOrphaned implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public int $conversationId,
        public int $agentId
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('support.agent.inbox'),
        ];
    }

    public function broadcastAs(): string
    {
        return 'SupportConversationOrphaned';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        // For simplicity we just broadcast the internal IDs. 
        // Real-time clients can use this to flag the chat row as orphaned.
        return [
            'conversation_id' => $this->conversationId,
            'agent_id' => $this->agentId,
            'is_orphaned' => true,
        ];
    }
}
