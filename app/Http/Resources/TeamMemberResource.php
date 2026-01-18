<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamMemberResource extends JsonResource
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
            'public_id' => $this->public_id,
            'name' => $this->name,
            'username' => $this->username,
            'display_name' => $this->display_name,
            'initials' => $this->initials,
            'avatar_url' => $this->avatar_url,
            'role' => $this->whenPivotLoaded('team_user', function () {
                return $this->pivot->role;
            }),
            'joined_at' => $this->whenPivotLoaded('team_user', function () {
                return $this->pivot->created_at;
            }),
        ];
    }
}
