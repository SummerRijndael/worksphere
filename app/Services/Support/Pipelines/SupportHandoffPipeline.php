<?php

namespace App\Services\Support\Pipelines;

use App\Contracts\SupportAiAdapterContract;
use App\Models\SupportConversation;

class SupportHandoffPipeline
{
    public function __construct(
        protected SupportAiAdapterContract $aiAdapter
    ) {}

    /**
     * @return array{
     *     reply: string,
     *     escalate: bool,
     *     reason: string|null,
     *     confidence: float
     * }
     */
    public function handle(SupportConversation $conversation, string $incomingMessage): array
    {
        return $this->aiAdapter->respond($conversation, $incomingMessage);
    }
}

