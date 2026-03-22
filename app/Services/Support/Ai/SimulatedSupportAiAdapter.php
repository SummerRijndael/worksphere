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
        
        $explicitHandoff = str_contains($normalized, 'talk to human') || 
                           str_contains($normalized, 'human agent') || 
                           str_contains($normalized, 'chat with agent');

        $shouldEscalate = $matchedKeyword !== null || $isLongComplexCase || $explicitHandoff;

        if ($shouldEscalate) {
            $reason = $explicitHandoff ? "User requested human agent" : ($matchedKeyword !== null
                ? "Detected complex topic: {$matchedKeyword}"
                : "Detected long/complex case ({$minLength}+ chars)");

            return [
                'reply' => $explicitHandoff 
                    ? 'I understand. I am connecting you with a human support specialist right now. One moment please.'
                    : 'Thanks for the details. I am routing this to a human support specialist for deeper review.',
                'escalate' => true,
                'reason' => $reason,
                'confidence' => 0.99,
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

