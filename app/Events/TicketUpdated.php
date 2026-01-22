<?php

namespace App\Events;

use App\Models\Ticket;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TicketUpdated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $publicId;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Ticket $ticket
    ) {
        $this->publicId = $ticket->public_id;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('tickets.'.$this->publicId),
            new PrivateChannel('tickets.queue'), // Also notify support staff queue
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'ticket.updated';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'public_id' => $this->publicId,
            'title' => $this->ticket->title,
            'status' => $this->ticket->status->value,
            'priority' => $this->ticket->priority->value,
            'assigned_to' => $this->ticket->assignee?->public_id,
            'updated_at' => $this->ticket->updated_at->toISOString(),
        ];
    }
}
