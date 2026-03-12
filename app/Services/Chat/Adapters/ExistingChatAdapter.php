<?php

namespace App\Services\Chat\Adapters;

use App\Contracts\ChatChannelAdapterContract;
use App\Models\Chat\Chat;
use App\Models\Chat\ChatMessage;
use App\Models\User;
use App\Services\Chat\ChatEngine;
use App\Services\Chat\ChatTransport;
use Illuminate\Support\Str;
use InvalidArgumentException;

class ExistingChatAdapter implements ChatChannelAdapterContract
{
    public function key(): string
    {
        return 'chat';
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    public function fetchMessages(array $context, int $limit = 50, ?string $before = null): array
    {
        $chat = $this->requireChat($context);
        $user = $this->requireUser($context);
        $engine = ChatEngine::for($chat, $user);

        if ($before) {
            $beforeId = ChatMessage::where('public_id', $before)
                ->where('chat_id', $chat->id)
                ->value('id');

            if (! $beforeId) {
                return [];
            }

            return $engine->loadMore((int) $beforeId, $limit);
        }

        return $engine->loadMessages($limit);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sendMessage(array $context, array $payload): array
    {
        $chat = $this->requireChat($context);
        $user = $this->requireUser($context);
        $content = (string) ($payload['content'] ?? '');
        $metadata = isset($payload['metadata']) && is_array($payload['metadata']) ? $payload['metadata'] : null;
        $tempId = (string) ($payload['temp_id'] ?? Str::uuid());
        $replyToPublicId = isset($payload['reply_to']) ? (string) $payload['reply_to'] : null;

        $replyToId = null;
        if ($replyToPublicId) {
            $reply = ChatMessage::where('public_id', $replyToPublicId)
                ->where('chat_id', $chat->id)
                ->first(['id']);

            if (! $reply) {
                throw new InvalidArgumentException('Reply target not found in this chat.');
            }
            $replyToId = $reply->id;
        }

        /** @var array<\Illuminate\Http\UploadedFile> $files */
        $files = isset($payload['files']) && is_array($payload['files']) ? $payload['files'] : [];

        $message = ChatEngine::for($chat, $user)->send($content, $files, $replyToId, $metadata);
        ChatTransport::queueBroadcast($message, $tempId);

        return ChatEngine::normalizeOne($message);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function pinMessage(array $context, string|int $messageId): array
    {
        throw new InvalidArgumentException('Pinning is not supported by the existing chat adapter.');
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function unpinMessage(array $context, string|int $messageId): array
    {
        throw new InvalidArgumentException('Unpinning is not supported by the existing chat adapter.');
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function editMessage(array $context, string|int $messageId, array $payload): array
    {
        throw new InvalidArgumentException('Editing is not supported by the existing chat adapter.');
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function deleteMessage(array $context, string|int $messageId): array
    {
        throw new InvalidArgumentException('Deleting is not supported by the existing chat adapter.');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function requireChat(array $context): Chat
    {
        if (($context['chat'] ?? null) instanceof Chat) {
            return $context['chat'];
        }

        throw new InvalidArgumentException('Chat adapter requires a Chat model in context[chat].');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function requireUser(array $context): User
    {
        if (($context['user'] ?? null) instanceof User) {
            return $context['user'];
        }

        throw new InvalidArgumentException('Chat adapter requires a User model in context[user].');
    }
}
