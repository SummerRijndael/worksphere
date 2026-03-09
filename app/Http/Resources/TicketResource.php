<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketResource extends JsonResource
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
            'number' => $this->ticket_number,
            'display_id' => $this->ticket_number ?? 'TIC-'.str_pad($this->id, 4, '0', STR_PAD_LEFT), // Fallback for old tickets
            'title' => $this->title,
            'description' => $this->description,
            'status' => [
                'value' => $this->status->value,
                'label' => $this->status->label(),
                'color' => $this->status->color(),
            ],
            'priority' => [
                'value' => $this->priority->value,
                'label' => $this->priority->label(),
                'color' => $this->priority->color(),
            ],
            'type' => [
                'value' => $this->type->value,
                'label' => $this->type->label(),
                'icon' => $this->type->icon(),
                'color' => $this->type->color(),
            ],
            'tags' => $this->tags ?? [],
            'is_overdue' => $this->is_overdue,
            'is_sla_breached' => $this->sla_breached,
            'is_locked' => $this->is_locked,
            'is_archived' => $this->is_archived,
            'archived_at' => $this->archived_at,
            'archive_reason' => $this->archive_reason,
            'parent_id' => $this->parent?->public_id,
            'parent' => $this->whenLoaded('parent', fn () => [
                'id' => $this->parent->public_id,
                'title' => $this->parent->title,
                'status' => [
                    'label' => $this->parent->status->label(),
                    'color' => $this->parent->status->color(),
                ],
            ]),
            'children_count' => $this->children()->count(), // Optimized?
            'children' => $this->whenLoaded('children', fn () => TicketResource::collection($this->children)),

            // Relationships
            'reporter' => $this->whenLoaded('reporter', fn () => $this->reporter ? [
                'id' => $this->reporter->id,
                'public_id' => $this->reporter->public_id,
                'name' => $this->reporter->name,
                'avatar_url' => $this->reporter->getAvatarData()->getUrl(),
                'color' => $this->reporter->getAvatarData()->color,
            ] : null),
            'assignee' => $this->whenLoaded('assignee', fn () => $this->assignee ? [
                'id' => $this->assignee->id,
                'public_id' => $this->assignee->public_id,
                'name' => $this->assignee->name,
                'avatar_url' => $this->assignee->getAvatarData()->getUrl(),
                'color' => $this->assignee->getAvatarData()->color,
            ] : null),
            'team' => $this->whenLoaded('team', fn () => $this->team ? [
                'id' => $this->team->public_id,
                'name' => $this->team->name,
                'initials' => $this->team->initials,
            ] : null),

            // SLA Fields
            'sla_response_hours' => $this->sla_response_hours,
            'sla_resolution_hours' => $this->sla_resolution_hours,
            'first_response_at' => $this->first_response_at?->toISOString(),
            'sla_breached' => $this->sla_breached,
            'sla_breach_type' => $this->sla_breach_type,
            'sla_response_warning_at' => $this->sla_response_warning_at?->toISOString(),
            'sla_resolution_warning_at' => $this->sla_resolution_warning_at?->toISOString(),
            'sla_response_progress' => $this->getSlaProgress('response'),
            'sla_resolution_progress' => $this->getSlaProgress('resolution'),
            'sla_response_deadline' => $this->getResponseSlaDeadline()?->toISOString(),
            'sla_resolution_deadline' => $this->getResolutionSlaDeadline()?->toISOString(),

            // Deadline
            'due_date' => $this->due_date?->toISOString(),
            'is_overdue' => $this->is_overdue,

            // Timestamps
            'resolved_at' => $this->resolved_at?->toISOString(),
            'closed_at' => $this->closed_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),

            // Counts
            'comment_count' => $this->comment_count,

            // Conditional data
            'comments' => TicketCommentResource::collection($this->whenLoaded('comments')),
            'internal_notes' => $this->when(
                $request->user()?->can('viewInternalNotes', $this->resource),
                fn () => TicketInternalNoteResource::collection($this->whenLoaded('internalNotes'))
            ),
            'followers' => $this->whenLoaded('followers', fn () => $this->followers->map(fn ($participant) => [
                'id' => $participant->id,
                'public_id' => $participant->public_id,
                'name' => $participant->name,
                'avatar_url' => $participant->getAvatarData()->getUrl(),
                'color' => $participant->getAvatarData()->color,
            ])),
            'is_following' => $this->when(
                $request->user(),
                fn () => $this->isFollowedBy($request->user())
            ),
            'can_view_internal_notes' => $request->user()?->can('viewInternalNotes', $this->resource) ?? false,
            'can_add_internal_notes' => $request->user()?->can('addInternalNote', $this->resource) ?? false,
        ];
    }
}
