<?php

namespace App\Http\Resources\Support;

use App\Models\SupportSurveyInvite;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupportSurveyInviteResource extends JsonResource
{
    public function __construct(
        $resource,
        protected bool $includeDefinition = false,
        protected bool $includeToken = false
    ) {
        parent::__construct($resource);
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $surveyType = (string) $this->survey_type;
        $bounds = $surveyType === SupportSurveyInvite::TYPE_NPS
            ? [0, 10]
            : [1, 5];

        return [
            'id' => $this->public_id,
            'conversation_id' => $this->whenLoaded('conversation', fn () => $this->conversation?->public_id),
            'survey_type' => $surveyType,
            'status' => $this->status,
            'token' => $this->when($this->includeToken, fn () => $this->getAttribute('plain_token')),
            'issued_at' => $this->issued_at?->toISOString(),
            'expires_at' => $this->expires_at?->toISOString(),
            'responded_at' => $this->responded_at?->toISOString(),
            'is_expired' => $this->expires_at ? $this->expires_at->isPast() : false,
            'definition' => $this->when($this->includeDefinition, function () use ($surveyType, $bounds) {
                return [
                    'question' => $surveyType === SupportSurveyInvite::TYPE_NPS
                        ? 'How likely are you to recommend our support team to a colleague or friend?'
                        : 'How satisfied are you with this support conversation?',
                    'scale_min' => $bounds[0],
                    'scale_max' => $bounds[1],
                    'comment_optional' => true,
                ];
            }),
            'metadata' => $this->metadata ?? (object) [],
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}

