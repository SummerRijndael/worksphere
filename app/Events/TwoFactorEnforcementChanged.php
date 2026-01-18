<?php

namespace App\Events;

use App\Models\User;
use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

/**
 * Event broadcasted immediately when 2FA enforcement changes for a user.
 * Used to notify the user in real-time that they need to set up 2FA.
 */
class TwoFactorEnforcementChanged implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    /**
     * @param  array<string>  $allowedMethods
     */
    public function __construct(
        public User $user,
        public bool $enforced,
        public array $allowedMethods = []
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
        return '2fa.enforcement.changed';
    }

    /**
     * Get the data to broadcast.
     *
     * @return array<string, mixed>
     */
    public function broadcastWith(): array
    {
        return [
            'enforced' => $this->enforced,
            'allowed_methods' => $this->allowedMethods,
            'requires_setup' => $this->enforced && ! $this->user->has2FAConfigured($this->allowedMethods),
            'timestamp' => now()->toISOString(),
        ];
    }
}
