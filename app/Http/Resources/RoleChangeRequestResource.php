<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\RoleChangeRequest
 */
class RoleChangeRequestResource extends JsonResource
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
            'type' => $this->type,
            'type_label' => $this->getTypeLabel(),
            'target_role' => $this->whenLoaded('targetRole', fn () => [
                'id' => $this->targetRole->id,
                'name' => $this->targetRole->name,
            ]),
            'requested_changes' => $this->requested_changes,
            'reason' => $this->reason,
            'requested_by' => $this->whenLoaded('requestedByUser', fn () => [
                'id' => $this->requestedByUser->public_id,
                'name' => $this->requestedByUser->name,
                'avatar' => $this->requestedByUser->avatar_url,
            ]),
            'status' => $this->status,
            'status_label' => $this->getStatusLabel(),
            'status_color' => $this->getStatusColor(),
            'required_approvals' => $this->required_approvals,
            'current_approvals' => $this->currentApprovalCount(),
            'remaining_approvals' => $this->getRemainingApprovalsNeeded(),
            'is_fully_approved' => $this->isFullyApproved(),
            'is_pending' => $this->isPending(),
            'is_expired' => $this->isExpired(),
            'approvals' => $this->whenLoaded('approvals', fn () => $this->approvals->map(fn ($approval) => [
                'id' => $approval->id,
                'action' => $approval->action,
                'admin' => $approval->admin ? [
                    'id' => $approval->admin->public_id,
                    'name' => $approval->admin->name,
                    'avatar' => $approval->admin->avatar_url,
                ] : null,
                'comment' => $approval->comment,
                'created_at' => $approval->created_at?->toIso8601String(),
            ])
            ),
            'expires_at' => $this->expires_at?->toIso8601String(),
            'completed_at' => $this->completed_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }
}
