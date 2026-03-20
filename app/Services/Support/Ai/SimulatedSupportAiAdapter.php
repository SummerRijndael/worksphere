<?php

namespace App\Services\Support\Ai;

use App\Contracts\SupportAiAdapterContract;
use App\Models\SupportConversation;
use Illuminate\Support\Str;

class SimulatedSupportAiAdapter implements SupportAiAdapterContract
{
    /**
     * @return array{
     *     reply: string,
     *     escalate: bool,
     *     reason: string|null,
     *     confidence: float
     * }
     */
    public function respond(SupportConversation $conversation, string $incomingMessage): array
    {
        $message = trim($incomingMessage);
        $normalized = Str::lower($message);
        $keywords = array_map('strtolower', (array) config('support_chat.complexity_keywords', []));
        $minLength = (int) config('support_chat.complexity_min_length', 420);

        $matchedKeyword = null;
        foreach ($keywords as $keyword) {
            if ($keyword !== '' && str_contains($normalized, $keyword)) {
                $matchedKeyword = $keyword;
                break;
            }
        }

        $isLongComplexCase = mb_strlen($message) >= $minLength;
        $shouldEscalate = $matchedKeyword !== null || $isLongComplexCase;

        if ($shouldEscalate) {
            $reason = $matchedKeyword !== null
                ? "Detected complex topic: {$matchedKeyword}"
                : "Detected long/complex case ({$minLength}+ chars)";

            return [
                'reply' => 'Thanks for the details. I am routing this to a human support specialist for deeper review.',
                'escalate' => true,
                'reason' => $reason,
                'confidence' => 0.92,
            ];
        }

        return [
            'reply' => 'Thanks for reaching out. I can help with that. Could you share one more detail so I can narrow this down quickly?',
            'escalate' => false,
            'reason' => null,
            'confidence' => 0.81,
        ];
    }
}

