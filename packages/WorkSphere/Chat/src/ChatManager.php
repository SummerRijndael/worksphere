<?php

namespace WorkSphere\Chat;

use WorkSphere\Chat\Models\Chat;
use WorkSphere\Chat\Models\ChatMessage;
use Illuminate\Support\Str;

class ChatManager
{
    /**
     * Create a new chat.
     */
    public function createChat(string $type, ?string $name = null): Chat
    {
        return Chat::create([
            'public_id' => (string) Str::ulid(),
            'name' => $name,
            'type' => $type,
        ]);
    }

    /**
     * Get messages for a chat.
     */
    public function getMessages(Chat $chat, int $limit = 25, ?string $beforePublicId = null)
    {
        $query = $chat->messages()->with('sender')->latest();

        if ($beforePublicId) {
            $beforeMessage = ChatMessage::where('public_id', $beforePublicId)->first();
            if ($beforeMessage) {
                $query->where([['id', '<', $beforeMessage->id]]);
            }
        }

        return $query->limit($limit)->get();
    }

    /**
     * Send a message to a chat.
     */
    public function sendMessage(Chat $chat, int $userId, string $content, array $metadata = []): ChatMessage
    {
        $message = $chat->messages()->create([
            'public_id' => (string) Str::ulid(),
            'user_id' => $userId,
            'content' => $content,
            'metadata' => $metadata,
            'type' => 'user',
        ]);

        broadcast(new \WorkSphere\Chat\Events\MessageCreated($message))->toOthers();

        return $message;
    }

    /**
     * Add a participant to a chat.
     */
    public function addParticipant(Chat $chat, int $userId, string $role = 'member'): void
    {
        $chat->participants()->syncWithoutDetaching([
            $userId => ['role' => $role]
        ]);
    }
}
