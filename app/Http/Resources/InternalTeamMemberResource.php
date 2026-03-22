<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InternalTeamMemberResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'name' => $this->name,
            'email' => $this->email,
            'avatar_url' => $this->avatar_url,
            // Access the pivot data specifically for internal_team_user
            'team_role' => $this->whenPivotLoaded('internal_team_user', function () {
                return $this->pivot->role;
            }),
            'joined_at' => $this->whenPivotLoaded('internal_team_user', function () {
                return $this->pivot->created_at;
            }),
        ];
    }
}
