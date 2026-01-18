<?php

namespace App\Events\Chat;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event broadcast when a user reads messages in a chat.
 * Sent to the OTHER user(s) so they see "Seen" indicator.
 */
class MessageRead implements ShouldBroadcast
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $chatPublicId;

    public string $lastReadMessageId;

    public string $readerPublicId;

    public string $recipientPublicId; // The user to notify

    public function __construct(
        string $chatPublicId,
        string $lastReadMessageId,
        string $readerPublicId,
        string $recipientPublicId
    ) {
        $this->chatPublicId = $chatPublicId;
        $this->lastReadMessageId = $lastReadMessageId;
        $this->readerPublicId = $readerPublicId;
        $this->recipientPublicId = $recipientPublicId;
    }

    public function broadcastWith(): array
    {
        return [
            'chat_id' => $this->chatPublicId,
            'last_read_message_id' => $this->lastReadMessageId,
            'reader_public_id' => $this->readerPublicId,
        ];
    }

    /**
     * Broadcast to the RECIPIENT (the message sender), not the reader.
     */
    public function broadcastOn(): PrivateChannel
    {
        return new PrivateChannel("user.{$this->recipientPublicId}");
    }

    public function broadcastAs(): string
    {
        return 'MessageRead';
    }
}
