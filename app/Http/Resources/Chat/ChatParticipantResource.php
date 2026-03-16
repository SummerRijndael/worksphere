<?php

namespace App\Http\Resources\Chat;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ChatParticipantResource extends JsonResource
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
            'name' => $this->name,
            'public_id' => $this->public_id,
            'avatar' => $this->avatar_url,
            'role' => $this->pivot->role ?? 'member',
            'is_online' => app(\App\Services\Chat\PresenceService::class)->presenceStatus($this->id) === 'online',
            'presence_status' => app(\App\Services\Chat\PresenceService::class)->presenceStatus($this->id),
        ];
    }
}
