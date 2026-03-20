<?php

namespace App\Contracts;

use App\Models\SupportConversation;

interface SupportAiAdapterContract
{
    /**
     * Generate an AI decision for an incoming customer message.
     *
     * @return array{
     *     reply: string,
     *     escalate: bool,
     *     reason: string|null,
     *     confidence: float
     * }
     */
    public function respond(SupportConversation $conversation, string $incomingMessage): array;
}

