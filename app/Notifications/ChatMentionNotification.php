<?php

namespace App\Notifications;

use App\Models\Chat\ChatMessage;
use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;
use Illuminate\Support\Str;

class ChatMentionNotification extends Notification implements ShouldBroadcast, ShouldQueue
{
    use Queueable;

    public function __construct(
        public ChatMessage $message,
        public User $sender
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        return [
            'type' => 'mention',
            'title' => "{$this->sender->name} mentioned you",
            'message' => Str::limit($this->message->content, 100),
            'action_url' => "/chat/{$this->message->chat->public_id}?messageId={$this->message->public_id}",
            'action_label' => 'View Message',
            'metadata' => [
                'chat_id' => $this->message->chat->public_id,
                'message_id' => $this->message->public_id,
                'sender_id' => $this->sender->public_id,
                'sender_avatar' => $this->sender->avatar_url,
            ],
        ];
    }

    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'id' => $this->id,
            'data' => $this->toArray($notifiable),
            'read_at' => null,
            'created_at' => now()->toIso8601String(),
            'type' => self::class,
        ]);
    }
}
