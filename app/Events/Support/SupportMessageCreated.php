<?php

namespace App\Events\Support;

use App\Models\SupportConversation;
use App\Models\SupportMessage;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class SupportMessageCreated implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public SupportConversation $conversation,
        public SupportMessage $message,
        public bool $broadcastToCustomer = true
    ) {}

    /**
     * @return array<int, PrivateChannel>
     */
    public function broadcastOn(): array
    {
        $channels = [
            new PrivateChannel("support.agent.{$this->conversation->public_id}"),
        ];

        if ($this->broadcastToCustomer) {
            $channels[] = new PrivateChannel("support.customer.{$this->conversation->public_id}");
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'SupportMessageCreated';
    }

    /**
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        $message = $this->message->loadMissing(['sender:id,public_id,name,email', 'media']);
        $attachments = method_exists($message, 'toAttachmentPayload')
            ? $message->toAttachmentPayload()
            : (is_array($message->attachments) ? $message->attachments : []);
        $avatar = $message->sender?->getAvatarData();
        $thumb = $message->sender?->getAvatarData('thumb');

        return [
            'conversation_id' => $this->conversation->public_id,
            'message' => [
                'id' => $message->public_id,
                'sender_type' => $message->sender_type,
                'is_private_note' => (bool) $message->is_private_note,
                'body' => $message->body,
                'attachments' => $attachments,
                'metadata' => $message->metadata ?? (object) [],
                'sender' => $message->sender ? [
                    'id' => $message->sender->public_id,
                    'name' => $message->sender->name,
                    'email' => $message->sender->email,
                    'avatar_url' => $avatar?->getUrl(),
                    'avatar_thumb_url' => $thumb?->getUrl(),
                    'avatar_color' => $avatar?->color,
                ] : null,
                'created_at' => $message->created_at?->toISOString(),
                'updated_at' => $message->updated_at?->toISOString(),
            ],
            'updated_at' => now()->toISOString(),
        ];
    }
}
