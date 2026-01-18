<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event broadcasted immediately when a user's account status changes.
 * Used to notify the user in real-time when they are blocked or suspended.
 */
class UserStatusChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public User $user,
        public string $newStatus,
        public ?string $reason = null,
        public ?User $changedBy = null,
        public ?string $suspendedUntil = null
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
        return 'status.changed';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'status' => $this->newStatus,
            'reason' => $this->reason,
            'changed_by' => $this->changedBy ? [
                'name' => $this->changedBy->name,
                'public_id' => $this->changedBy->public_id,
            ] : null,
            'suspended_until' => $this->suspendedUntil,
            'can_login' => $this->newStatus === 'active',
            'timestamp' => now()->toISOString(),
        ];
    }
}
