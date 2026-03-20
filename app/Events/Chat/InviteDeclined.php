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
        $inviterPublicId = $this->invite['inviter_public_id'] ?? $this->invite['inviter_id'] ?? null;
        $inviteePublicId = $this->invite['invitee_public_id'] ?? $this->invite['invitee_id'] ?? null;

        $channels = [];
        if ($inviterPublicId) {
            $channels[] = new PrivateChannel('user.'.$inviterPublicId);
        }
        if ($inviteePublicId) {
            $channels[] = new PrivateChannel('user.'.$inviteePublicId);
        }

        return $channels;
    }

    public function broadcastAs(): string
    {
        return 'invite.declined';
    }
}
