<?php

namespace App\Events\Chat;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PresenceChannel;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class UserPresenceChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public string $publicId;

    public string $status;

    public ?int $lastSeen;

    /**
     * Create a new event instance.
     */
    public function __construct(
        public User $user,
        string $status
    ) {
        $this->publicId = $user->public_id;
        $this->status = $status;
        $this->lastSeen = now()->timestamp;
    }

    /**
     * Get the channels the event should broadcast on.
     *
     * @return array<int, \Illuminate\Broadcasting\Channel>
     */
    public function broadcastOn(): array
    {
        return [
            // User's own private channel for their presence updates
            new PrivateChannel('presence.'.$this->publicId),
            // Global online-users channel for team/org visibility
            // Uses PresenceChannel to match the echo.join() on frontend
            new PresenceChannel('online-users'),
        ];
    }

    /**
     * The event's broadcast name.
     */
    public function broadcastAs(): string
    {
        return 'presence.changed';
    }

    /**
     * Get the data to broadcast.
     *
     * SECURITY: Only broadcast public_id, never internal id
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'public_id' => $this->publicId,
            'status' => $this->status,
            'last_seen' => $this->lastSeen,
            'name' => $this->user->name,
            'avatar_thumb_url' => $this->user->avatar_thumb_url,
        ];
    }
}
