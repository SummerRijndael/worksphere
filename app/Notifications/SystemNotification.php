<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class SystemNotification extends Notification implements ShouldBroadcast, ShouldQueue
{
    use Queueable;

    public $type;

    public $title;

    public $message;

    public $actionUrl;

    public $actionLabel;

    public $metadata;

    /**
     * Create a new notification instance.
     *
     * @param  string  $type  - system, team, project, download
     * @param  string  $title
     * @param  string  $message
     * @param  string|null  $actionUrl
     * @param  string|null  $actionLabel
     * @param  array  $metadata
     */
    public function __construct($type, $title, $message, $actionUrl = null, $actionLabel = null, $metadata = [])
    {
        $this->type = $type;
        $this->title = $title;
        $this->message = $message;
        $this->actionUrl = $actionUrl;
        $this->actionLabel = $actionLabel;
        $this->metadata = $metadata;
    }

    /**
     * Get the notification's delivery channels.
     *
     * @return array<int, string>
     */
    public function via(object $notifiable): array
    {
        return ['database', 'broadcast'];
    }

    /**
     * Get the array representation of the notification.
     *
     * @return array<string, mixed>
     */
    public function toArray(object $notifiable): array
    {
        return [
            'type' => $this->type,
            'title' => $this->title,
            'message' => $this->message,
            'action_url' => $this->actionUrl,
            'action_label' => $this->actionLabel,
            'metadata' => $this->metadata,
        ];
    }

    /**
     * Get the broadcastable representation of the notification.
     */
    public function toBroadcast(object $notifiable): BroadcastMessage
    {
        return new BroadcastMessage([
            'id' => $this->id,
            'data' => $this->toArray($notifiable),
            'read_at' => null,
            'created_at' => now()->toIso8601String(),
            'type' => 'App\\Notifications\\SystemNotification',
        ]);
    }
}
