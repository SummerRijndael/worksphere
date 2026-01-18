<?php

namespace App\Events\Chat;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InviteAccepted implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public array $invite,
        public array $chat
    ) {}

    public function broadcastOn(): array
    {
        // Broadcast to the inviter using Public ID
        return [
            new PrivateChannel('user.'.$this->invite['inviter_public_id']),
        ];
    }

    public function broadcastAs(): string
    {
        return 'invite.accepted';
    }
}
