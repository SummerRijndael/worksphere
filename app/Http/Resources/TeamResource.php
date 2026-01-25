<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamResource extends JsonResource
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
            'slug' => $this->slug,
            'description' => $this->description,
            'status' => $this->status,
            'member_count' => $this->member_count,
            'storage_used' => $this->storage_used,
            'storage_limit' => $this->storage_limit,
            'has_avatar' => $this->has_avatar,
            'avatar_url' => $this->avatar_url,
            'initials' => $this->initials,
            'owner_id' => $this->owner?->public_id, // Use Public ID
            'owner' => $this->whenLoaded('owner', fn () => [
                'id' => $this->owner->public_id,
                'name' => $this->owner->name,
                'email' => $this->owner->email,
                'avatar_url' => $this->owner->avatar_url,
                'initials' => $this->owner->initials,
                'has_avatar' => $this->owner->has_avatar,
            ]),
            'members' => TeamMemberResource::collection($this->whenLoaded('members')),
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
            'lifecycle_status' => $this->lifecycle_status,
            'last_activity_at' => $this->last_activity_at,
        ];
    }
}
