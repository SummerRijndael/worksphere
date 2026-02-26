<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class AppReviewResource extends JsonResource
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
            'rating' => $this->rating,
            'comment' => $this->comment,
            'is_published' => (bool) $this->is_published,
            'created_at' => $this->created_at?->toISOString(),
            'user' => [
                'id' => $this->user->public_id,
                'name' => $this->user->name,
                'avatar_thumb_url' => $this->user->avatar_thumb_url,
            ],
        ];
    }
}
