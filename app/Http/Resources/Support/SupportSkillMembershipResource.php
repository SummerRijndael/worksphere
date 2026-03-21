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
        $user = $this->whenLoaded('user');

        return [
            'id' => (int) $this->id,
            'membership_role' => $this->membership_role,
            'is_primary' => (bool) $this->is_primary,
            'is_active' => (bool) $this->is_active,
            'capacity' => $this->capacity,
            'settings' => $this->settings ?? (object) [],
            'user' => $user ? [
                'id' => $user->public_id,
                'name' => $user->name,
                'email' => $user->email,
                'status' => $user->status,
            ] : null,
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
