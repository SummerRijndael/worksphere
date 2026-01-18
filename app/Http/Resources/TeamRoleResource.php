<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TeamRoleResource extends JsonResource
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
            'slug' => $this->slug,
            'description' => $this->description,
            'color' => $this->color,
            'level' => $this->level,
            'is_default' => $this->is_default,
            'is_system' => $this->is_system,
            'can_be_deleted' => $this->canBeDeleted(),
            'permissions' => $this->whenLoaded('permissions', function () {
                return $this->permissions->pluck('permission')->toArray();
            }),
            'member_count' => $this->when(
                $this->users_count !== null,
                fn () => $this->users_count,
                fn () => $this->whenLoaded('users', fn () => $this->users->count(), 0)
            ),
            'creator' => $this->whenLoaded('creator', function () {
                return [
                    'id' => $this->creator->public_id,
                    'name' => $this->creator->name,
                    'avatar_url' => $this->creator->avatar_url,
                ];
            }),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
