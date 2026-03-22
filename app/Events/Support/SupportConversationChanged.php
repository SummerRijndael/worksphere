<?php

namespace App\Events\Support;

use App\Models\SupportConversation;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupportConversationChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public SupportConversation $conversation,
        public bool $broadcastToCustomer = true
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel("support.agent.{$this->conversation->public_id}"),
            new PrivateChannel('support.agent.inbox'),
        ];

        if ($this->broadcastToCustomer) {
            $channels[] = new PrivateChannel("support.customer.{$this->conversation->public_id}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'SupportConversationChanged';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'conversation_id' => $this->conversation->public_id,
            'status' => $this->conversation->status,
            'ai_handoff_required' => (bool) $this->conversation->ai_handoff_required,
            'assigned_to' => $this->conversation->assignee?->public_id,
            'assignment_state' => $this->conversation->assignment_state,
            'updated_at' => $this->conversation->updated_at?->toISOString(),
            'last_message_at' => $this->conversation->last_message_at?->toISOString(),
        ];
    }
}
