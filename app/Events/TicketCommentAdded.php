<?php

namespace App\Events;

use App\Models\Ticket;
use App\Models\TicketComment;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class TicketCommentAdded implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $ticketPublicId;

    public string $commentPublicId;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public Ticket $ticket,
        public TicketComment $comment
    ) {
        $this->ticketPublicId = $ticket->public_id;
        $this->commentPublicId = $comment->public_id;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('tickets.'.$this->ticketPublicId),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'comment.added';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $author = $this->comment->author;

        return [
            'ticket_id' => $this->ticketPublicId,
            'comment' => [
                'id' => $this->commentPublicId,
                'content' => $this->comment->content,
                'author' => [
                    'id' => $author->public_id,
                    'name' => $author->name,
                    'initials' => $author->initials,
                    'avatar_thumb_url' => $author->avatar_thumb_url,
                ],
                'created_at' => $this->comment->created_at->toISOString(),
            ],
        ];
    }
}
