<?php

namespace App\Http\Resources\Support;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupportSkillResource extends JsonResource
{
    public function __construct($resource, protected bool $includeMembers = false)
    {
        parent::__construct($resource);
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $creator = $this->whenLoaded('creator');
        $memberships = $this->whenLoaded('memberships');

        return [
            'id' => $this->public_id,
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'department' => $this->department,
            'is_active' => (bool) $this->is_active,
            'priority' => (int) $this->priority,
            'settings' => $this->settings ?? (object) [],
            'members_count' => (int) ($this->members_count ?? 0),
            'active_members_count' => (int) ($this->active_members_count ?? 0),
            'creator' => $creator ? [
                'id' => $creator->public_id,
                'name' => $creator->name,
                'email' => $creator->email,
            ] : null,
            'memberships' => $this->when(
                $this->includeMembers && $memberships !== null,
                fn () => SupportSkillMembershipResource::collection($memberships)->resolve()
            ),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }
}
