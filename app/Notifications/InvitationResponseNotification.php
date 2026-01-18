<?php

namespace App\Notifications;

use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Broadcasting\ShouldBroadcast;
use Illuminate\Notifications\Messages\BroadcastMessage;
use Illuminate\Notifications\Notification;

class InvitationResponseNotification extends Notification implements ShouldBroadcast
{
    use Queueable;

    public $responder;

    public $team;

    public $action; // 'accepted' or 'declined'

    /**
     * Create a new notification instance.
     */
    public function __construct($responder, $team, $action)
    {
        $this->responder = $responder;
        $this->team = $team;
        $this->action = $action;
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
            'type' => 'invitation_response',
            'responder_id' => $this->responder->id,
            'responder_name' => $this->responder->name,
            'team_id' => $this->team->id,
            'team_name' => $this->team->name,
            'action' => $this->action,
            'message' => "{$this->responder->name} has {$this->action} your invitation to join {$this->team->name}.",
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
            'type' => 'App\\Notifications\\InvitationResponseNotification',
        ]);
    }
}
