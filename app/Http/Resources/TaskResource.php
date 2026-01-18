<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TaskResource extends JsonResource
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
            'title' => $this->title,
            'description' => $this->description,
            'status' => [
                'value' => $this->status?->value,
                'label' => $this->status?->label(),
                'color' => $this->status?->color(),
            ],
            'priority' => $this->priority,
            'sort_order' => $this->sort_order,
            'due_date' => $this->due_date?->toDateString(),
            'estimated_hours' => $this->estimated_hours,
            'actual_hours' => $this->actual_hours,
            'checklist' => $this->checklist,
            'is_overdue' => $this->is_overdue,
            'days_until_due' => $this->days_until_due,
            'available_transitions' => $this->when($request->has('with_transitions'), function () {
                return $this->status->allowedTransitions();
            }),
            'project' => $this->whenLoaded('project', function () {
                return [
                    'id' => $this->project->public_id,
                    'team_id' => $this->project->team->public_id ?? null,
                    'name' => $this->project->name,
                    'slug' => $this->project->slug,
                ];
            }),
            'parent' => $this->whenLoaded('parent', function () {
                if (! $this->parent) {
                    return null;
                }

                return [
                    'id' => $this->parent->public_id,
                    'title' => $this->parent->title,
                ];
            }),
            'template' => $this->whenLoaded('template', function () {
                if (! $this->template) {
                    return null;
                }

                return [
                    'id' => $this->template->public_id,
                    'name' => $this->template->name,
                ];
            }),
            'assignee' => $this->whenLoaded('assignee', function () {
                if (! $this->assignee) {
                    return null;
                }

                return [
                    'id' => $this->assignee->public_id,
                    'name' => $this->assignee->name,
                    'email' => $this->assignee->email,
                    'avatar_url' => $this->assignee->avatar_url,
                ];
            }),
            'assigner' => $this->whenLoaded('assigner', function () {
                if (! $this->assigner) {
                    return null;
                }

                return [
                    'id' => $this->assigner->public_id,
                    'name' => $this->assigner->name,
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
            'subtasks' => TaskResource::collection($this->whenLoaded('subtasks')),
            'subtasks_count' => $this->whenCounted('subtasks', $this->subtasks_count),
            'comments_count' => $this->whenCounted('comments', $this->comments_count),
            'latest_qa_review' => $this->whenLoaded('qaReviews', function () {
                $latestReview = $this->qaReviews->sortByDesc('created_at')->first();
                if (! $latestReview) {
                    return null;
                }

                return [
                    'id' => $latestReview->id,
                    'status' => $latestReview->status,
                    'reviewer' => [
                        'id' => $latestReview->reviewer->public_id,
                        'name' => $latestReview->reviewer->name,
                    ],
                    'reviewed_at' => $latestReview->reviewed_at?->toIso8601String(),
                    'notes' => $latestReview->notes,
                ];
            }),
            'attachments' => $this->when($this->relationLoaded('media'), function () {
                return $this->getMedia('attachments')->map(function ($media) {
                    return [
                        'id' => $media->id,
                        'name' => $media->name,
                        'file_name' => $media->file_name,
                        'mime_type' => $media->mime_type,
                        'size' => $media->size,
                        // USE SIGNED URL
                        'url' => \Illuminate\Support\Facades\URL::temporarySignedRoute(
                            'media.show',
                            now()->addMinutes(60),
                            ['media' => $media->id]
                        ),
                        'created_at' => $media->created_at->toIso8601String(),
                    ];
                });
            }),
            'assigned_at' => $this->assigned_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'submitted_at' => $this->submitted_at?->toIso8601String(),
            'approved_at' => $this->approved_at?->toIso8601String(),
            'sent_to_client_at' => $this->sent_to_client_at?->toIso8601String(),
            'client_approved_at' => $this->client_approved_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'archived_at' => $this->archived_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
