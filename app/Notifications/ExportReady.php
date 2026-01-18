<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Notifications\Notification;

class ExportReady extends Notification
{
    use Queueable;

    public function __construct(
        public string $url,
        public string $type = 'Data Export'
    ) {}

    public function via($notifiable): array
    {
        // Broadcast via database and generic broadcast channel if available
        return ['database', 'broadcast'];
    }

    public function toArray($notifiable): array
    {
        return [
            'title' => "{$this->type} Ready",
            'message' => 'Your export is ready for download.',
            'action_url' => $this->url,
            'type' => 'info', // generic type for frontend icon
        ];
    }

    // For broadcast
    public function toBroadcast($notifiable)
    {
        return new \Illuminate\Notifications\Messages\BroadcastMessage([
            'title' => "{$this->type} Ready",
            'message' => 'Your export is ready for download.',
            'action_url' => $this->url,
            'type' => 'info',
        ]);
    }
}
