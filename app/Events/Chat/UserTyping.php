<?php

namespace App\Events\Chat;

use App\Models\User;
use Illuminate\Broadcasting\PrivateChannel;
use Illuminate\Contracts\Broadcasting\ShouldBroadcastNow;

/**
 * Event broadcasted when a user is typing in a chat.
 */
class UserTyping implements ShouldBroadcastNow
{
    public function __construct(
        public string $chatPublicId,
        public User $user,
        public string $chatType = 'dm'
    ) {}

    public function broadcastOn(): PrivateChannel
    {
        $prefix = $this->chatType === 'dm' ? 'dm' : 'group';

        return new PrivateChannel("{$prefix}.{$this->chatPublicId}");
    }

    public function broadcastAs(): string
    {
        return 'TypingStarted';
    }

    public function broadcastWith(): array
    {
        return [
            'user_public_id' => $this->user->public_id,
        ];
    }
}
