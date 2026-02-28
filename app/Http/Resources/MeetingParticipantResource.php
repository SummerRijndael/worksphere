<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeetingParticipantResource extends JsonResource
{
    public static $wrap = null;

    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'role' => $this->role,
            'status' => $this->status,
            'metadata' => $this->metadata,
            'user' => $this->whenLoaded('user', function () {
                return [
                    'public_id' => $this->user->public_id,
                    'name' => $this->user->name,
                    'avatar_url' => $this->user->avatar_url,
                ];
            }),
        ];
    }
}
