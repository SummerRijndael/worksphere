<?php

namespace App\Notifications\Chat;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class MissedCallNotification extends Notification implements ShouldBroadcast
{
    use Queueable;

    public function __construct(
        public string $callerName,
        public string $callType,
        public string $chatId,
        public string $chatName
    ) {}

    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    public function toArray(object $notifiable): array
    {
        $displayChatName = $this->chatName ?: 'Chat';
        return [
            'type' => 'missed_call',
            'title' => 'Missed ' . ucfirst($this->callType) . ' Call',
            'message' => 'You missed a ' . $this->callType . ' call from ' . $this->callerName . ' in ' . $displayChatName,
            'action_url' => "/chat/{$this->chatId}",
            'action_label' => 'View Chat',
            'metadata' => [
                'chat_id' => $this->chatId,
                'caller_name' => $this->callerName,
                'call_type' => $this->callType,
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
