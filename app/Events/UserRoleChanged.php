<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event broadcasted immediately when a user's role is changed.
 * Used to notify the user in real-time that they need to re-authenticate.
 */
class UserRoleChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public User $user,
        public ?string $fromRole,
        public string $toRole,
        public string $action // 'promoted', 'demoted', 'changed'
    ) {}

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            new PrivateChannel('App.Models.User.'.$this->user->public_id),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'role.changed';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'from_role' => $this->fromRole,
            'to_role' => $this->toRole,
            'action' => $this->action,
            'message' => "Your role has been changed from {$this->fromRole} to {$this->toRole}. Please log out and log back in for changes to take effect.",
            'requires_relogin' => true,
            'timestamp' => now()->toISOString(),
        ];
    }
}
