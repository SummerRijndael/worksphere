<?php

namespace App\Policies\Chat;

use App\Models\Chat\Chat;
use App\Models\Chat\ChatMessage;
use App\Models\User;
use Illuminate\Auth\Access\HandlesAuthorization;

class ChatMessagePolicy
{
    use HandlesAuthorization;

    /**
     * Determine if the user can view the message.
     */
    public function view(User $user, ChatMessage $message): bool
    {
        return $message->chat->participants()->where('user_id', $user->id)->exists();
    }

    /**
     * Determine if the user can update the message.
     */
    public function update(User $user, ChatMessage $message): bool
    {
        return $message->user_id === $user->id;
    }

    /**
     * Determine if the user can delete the message.
     */
    public function delete(User $user, ChatMessage $message, bool $forEveryone = false): bool
    {
        if (! $forEveryone) {
            return true;
        }

        $chat = $message->chat;
        if ($chat->isDm()) {
            return $message->user_id === $user->id;
        }

        // To delete for everyone in groups/teams, user must be the author OR a chat admin/owner
        if ($message->user_id === $user->id) {
            return true;
        }

        $participant = $chat->participants()->where('user_id', $user->id)->first();
        return $participant && in_array($participant->role, ['admin', 'owner']);
    }

    /**
     * Determine if the user can pin the message.
     */
    public function pin(User $user, ChatMessage $message): bool
    {
        $chat = $message->chat;
        if ($chat->isDm()) {
            return $chat->participants()->where('user_id', $user->id)->exists();
        }

        $participant = $chat->participants()->where('user_id', $user->id)->first();
        return $participant && in_array($participant->role, ['admin', 'owner']);
    }

    /**
     * Determine if the user can react to the message.
     */
    public function react(User $user, ChatMessage $message): bool
    {
        return $message->chat->participants()->where('user_id', $user->id)->exists();
    }
}
