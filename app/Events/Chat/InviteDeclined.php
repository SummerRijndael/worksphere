<?php

namespace App\Events\Chat;

use Illuminate\Broadcasting\InteractsWithSockets;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;
use Illuminate\Foundation\Events\Dispatchable;
use Illuminate\Queue\SerializesModels;

class InviteDeclined implements ShouldBroadcastNow
{
    use Dispatchable, InteractsWithSockets, SerializesModels;

    public function __construct(
        public array $invite
    ) {}

    public function broadcastOn(): array
    {
        // Broadcast to the INVITER, because the invitee declined it.
        // Also broadcast to the invitee's other devices to remove it from their list.
        return [
            new PrivateChannel('user.'.$this->invite['inviter_id']),
            new PrivateChannel('user.'.$this->invite['invitee_id']),
        ];
    }

    public function broadcastAs(): string
    {
        return 'invite.declined';
    }
}
