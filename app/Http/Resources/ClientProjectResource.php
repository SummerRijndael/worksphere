<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Stripped-down project resource for client portal.
 * Only exposes public information, no internal details.
 */
class ClientProjectResource extends JsonResource
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
            'description' => $this->description,
            'status' => $this->status,
            'status_label' => $this->getStatusLabel(),
            'priority' => $this->priority,

            // Progress
            'progress' => $this->progress ?? 0,
            'tasks_count' => $this->whenCounted('tasks'),
            'completed_tasks_count' => $this->completed_tasks_count ?? 0,

            // Dates
            'start_date' => $this->start_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'completed_at' => $this->completed_at?->toIso8601String(),

            // Team info (minimal)
            'team' => $this->whenLoaded('team', function () {
                return [
                    'name' => $this->team->name,
                ];
            }),

            // Timestamps
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Get a human-readable status label.
     */
    protected function getStatusLabel(): string
    {
        return match ($this->status) {
            'planning' => 'Planning',
            'in_progress' => 'In Progress',
            'on_hold' => 'On Hold',
            'completed' => 'Completed',
            'cancelled' => 'Cancelled',
            'archived' => 'Archived',
            default => ucfirst($this->status ?? 'Unknown'),
        };
    }
}
