<?php

namespace App\Events\Chat;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InviteSent implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public array $invite
    ) {}

    public function broadcastOn(): array
    {
        // Broadcast to the invitee using Public ID
        return [
            new PrivateChannel('user.'.$this->invite['invitee_public_id']),
        ];
    }

    public function broadcastAs(): string
    {
        return 'invite.sent';
    }
}
