<?php

namespace App\Http\Resources\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupportSurveyResponseResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'invite_id' => $this->whenLoaded('invite', fn () => $this->invite?->public_id),
            'conversation_id' => $this->whenLoaded('invite', fn () => $this->invite?->conversation?->public_id),
            'survey_type' => $this->survey_type,
            'score' => (int) $this->score,
            'label' => $this->label,
            'comment' => $this->comment,
            'requester' => $this->whenLoaded('requester', fn () => $this->serializeUser($this->requester)),
            'rated_agent' => $this->whenLoaded('ratedAgent', fn () => $this->serializeUser($this->ratedAgent)),
            'metadata' => $this->metadata ?? (object) [],
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function serializeUser(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        return [
            'id' => $user->public_id,
            'name' => $user->name,
            'email' => $user->email,
        ];
    }
}

