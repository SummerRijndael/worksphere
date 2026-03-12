<?php

namespace App\Services\Chat\Adapters;

use App\Contracts\ChatChannelAdapterContract;
use RuntimeException;

class AiSupportChatAdapter implements ChatChannelAdapterContract
{
    public function key(): string
    {
        return 'ai_support';
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    public function fetchMessages(array $context, int $limit = 50, ?string $before = null): array
    {
        throw new RuntimeException('AI support chat adapter is not implemented yet.');
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sendMessage(array $context, array $payload): array
    {
        throw new RuntimeException('AI support chat adapter is not implemented yet.');
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function pinMessage(array $context, string|int $messageId): array
    {
        throw new RuntimeException('AI support chat adapter is not implemented yet.');
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function unpinMessage(array $context, string|int $messageId): array
    {
        throw new RuntimeException('AI support chat adapter is not implemented yet.');
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function editMessage(array $context, string|int $messageId, array $payload): array
    {
        throw new RuntimeException('AI support chat adapter is not implemented yet.');
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function deleteMessage(array $context, string|int $messageId): array
    {
        throw new RuntimeException('AI support chat adapter is not implemented yet.');
    }
}
