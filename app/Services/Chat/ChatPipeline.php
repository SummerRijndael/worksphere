<?php

namespace App\Services\Chat;

use App\Services\Chat\Adapters\AdapterFactory;

class ChatPipeline
{
    public function __construct(
        protected AdapterFactory $factory
    ) {}

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    public function fetchMessages(string $adapterKey, array $context, int $limit = 50, ?string $before = null): array
    {
        return $this->factory->make($adapterKey)->fetchMessages($context, $limit, $before);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sendMessage(string $adapterKey, array $context, array $payload): array
    {
        return $this->factory->make($adapterKey)->sendMessage($context, $payload);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function pinMessage(string $adapterKey, array $context, string|int $messageId): array
    {
        return $this->factory->make($adapterKey)->pinMessage($context, $messageId);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function unpinMessage(string $adapterKey, array $context, string|int $messageId): array
    {
        return $this->factory->make($adapterKey)->unpinMessage($context, $messageId);
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function editMessage(string $adapterKey, array $context, string|int $messageId, array $payload): array
    {
        return $this->factory->make($adapterKey)->editMessage($context, $messageId, $payload);
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function deleteMessage(string $adapterKey, array $context, string|int $messageId): array
    {
        return $this->factory->make($adapterKey)->deleteMessage($context, $messageId);
    }
}
