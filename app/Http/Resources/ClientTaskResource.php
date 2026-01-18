<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * Task resource for client portal.
 * Excludes internal notes and comments marked as internal.
 */
class ClientTaskResource extends JsonResource
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
            'title' => $this->title,
            'description' => $this->description,
            'status' => $this->status,
            'status_label' => $this->getStatusLabel(),
            'priority' => $this->priority,
            'priority_label' => $this->getPriorityLabel(),

            // Dates
            'start_date' => $this->start_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'completed_at' => $this->completed_at?->toIso8601String(),

            // Progress indicator
            'is_completed' => in_array($this->status, ['completed', 'archived']),
            'is_overdue' => $this->due_date && $this->due_date->isPast() && ! in_array($this->status, ['completed', 'archived']),

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
            'pending' => 'Pending',
            'in_progress' => 'In Progress',
            'pending_qa' => 'Under Review',
            'qa_in_progress' => 'Under Review',
            'pending_client' => 'Awaiting Your Approval',
            'client_approved' => 'Approved',
            'client_rejected' => 'Needs Revision',
            'completed' => 'Completed',
            'archived' => 'Archived',
            'on_hold' => 'On Hold',
            default => ucfirst(str_replace('_', ' ', $this->status ?? 'Unknown')),
        };
    }

    /**
     * Get a human-readable priority label.
     */
    protected function getPriorityLabel(): string
    {
        return match ($this->priority) {
            'urgent' => 'Urgent',
            'high' => 'High',
            'medium' => 'Medium',
            'low' => 'Low',
            default => ucfirst($this->priority ?? 'Normal'),
        };
    }
}
