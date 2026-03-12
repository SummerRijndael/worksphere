<?php

namespace App\Contracts;

interface ChatChannelAdapterContract
{
    /**
     * Adapter identifier used by the factory/registry.
     */
    public function key(): string;

    /**
     * Fetch a message list for a channel context.
     *
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    public function fetchMessages(array $context, int $limit = 50, ?string $before = null): array;

    /**
     * Send a message to a channel context.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sendMessage(array $context, array $payload): array;

    /**
     * Pin a message in a channel context.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function pinMessage(array $context, string|int $messageId): array;

    /**
     * Unpin a message in a channel context.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function unpinMessage(array $context, string|int $messageId): array;

    /**
     * Edit a message in a channel context.
     *
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function editMessage(array $context, string|int $messageId, array $payload): array;

    /**
     * Delete a message in a channel context.
     *
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function deleteMessage(array $context, string|int $messageId): array;
}
