<?php

namespace App\Http\Resources\Support;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupportSkillMembershipResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => (int) $this->id,
            'membership_role' => $this->membership_role,
            'is_primary' => (bool) $this->is_primary,
            'is_active' => (bool) $this->is_active,
            'capacity' => $this->capacity,
            'settings' => $this->settings ?? (object) [],
            'user' => $this->whenLoaded('user', function () {
                return [
                    'id' => $this->user->public_id,
                    'name' => $this->user->name,
                    'email' => $this->user->email,
                    'status' => $this->user->status,
                ];
            }),
            'skill' => $this->whenLoaded('skill', function () {
                return [
                    'id' => $this->skill->public_id,
                    'name' => $this->skill->name,
                    'slug' => $this->skill->slug,
                    'description' => $this->skill->description,
                    'is_active' => (bool) $this->skill->is_active,
                ];
            }),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
