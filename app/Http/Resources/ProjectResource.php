<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class ProjectResource extends JsonResource
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
            'team_id' => $this->team->public_id, // Expose usage of public_id for frontend matching
            'team' => $this->whenLoaded('team', function () {
                return [
                    'id' => $this->team->public_id,
                    'name' => $this->team->name,
                    'owner_id' => $this->team->owner_id, // Internal ID for ownership check
                ];
            }),
            'name' => $this->name,
            'slug' => $this->slug,
            'description' => $this->description,
            'status' => [
                'value' => $this->status?->value,
                'label' => $this->status?->label(),
                'color' => $this->status?->color(),
            ],
            'priority' => [
                'value' => $this->priority?->value,
                'label' => $this->priority?->label(),
                'color' => $this->priority?->color(),
            ],
            'start_date' => $this->start_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'budget' => $this->budget,
            'currency' => $this->currency,
            'progress_percentage' => $this->progress_percentage,
            'is_overdue' => $this->is_overdue,
            'days_until_due' => $this->days_until_due,
            'settings' => $this->settings,
            'client' => $this->whenLoaded('client', function () {
                return [
                    'id' => $this->client->public_id,
                    'name' => $this->client->name,
                    'email' => $this->client->email,
                ];
            }),
            'creator' => $this->whenLoaded('creator', function () {
                return [
                    'id' => $this->creator->public_id,
                    'name' => $this->creator->name,
                    'email' => $this->creator->email,
                    'avatar_url' => $this->creator->avatar_url,
                ];
            }),
            'archiver' => $this->whenLoaded('archiver', function () {
                if (! $this->archiver) {
                    return null;
                }

                return [
                    'id' => $this->archiver->public_id,
                    'name' => $this->archiver->name,
                ];
            }),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'members' => $this->whenLoaded('members', function () {
                $teamRoles = \Illuminate\Support\Facades\DB::table('team_user')
                    ->where('team_id', $this->team_id)
                    ->whereIn('user_id', $this->members->pluck('id'))
                    ->pluck('role', 'user_id');

                return $this->members->map(function ($member) use ($teamRoles) {
                    return [
                        'id' => $member->public_id,
                        'public_id' => $member->public_id,
                        'name' => $member->name,
                        'email' => $member->email,
                        'avatar_url' => $member->avatar_url,
                        'role' => $member->pivot->role,
                        'team_role' => $teamRoles[$member->id] ?? null,
                        'joined_at' => $member->pivot->joined_at,
                    ];
                });
            }),
            'member_count' => $this->whenCounted('members', $this->members_count),
            'tasks_count' => $this->whenCounted('tasks', $this->tasks_count),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
