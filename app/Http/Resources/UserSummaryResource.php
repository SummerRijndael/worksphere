<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class UserSummaryResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'public_id' => $this->public_id,
            'name' => $this->name,
            'username' => $this->username,
            'display_name' => $this->display_name,
            'initials' => $this->initials,
            'avatar_url' => $this->avatar_url,
            'avatar_thumb_url' => $this->avatar_thumb_url,
            'presence' => $this->last_login_at && $this->last_login_at->diffInMinutes(now()) < 5 ? 'online' : 'offline',
            'status' => $this->pivot ? $this->pivot->status : null,
        ];
    }
}
