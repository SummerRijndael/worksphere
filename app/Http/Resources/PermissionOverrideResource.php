<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

/**
 * @mixin \App\Models\PermissionOverride
 */
class PermissionOverrideResource extends JsonResource
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
            'permission' => $this->permission,
            'type' => $this->type,
            'scope' => $this->scope,
            'team' => $this->whenLoaded('team', fn () => [
                'id' => $this->team->public_id ?? $this->team->id,
                'name' => $this->team->name,
            ]),
            'is_temporary' => $this->is_temporary,
            'expires_at' => $this->expires_at?->toIso8601String(),
            'expiry_behavior' => $this->expiry_behavior,
            'grace_period_days' => $this->grace_period_days,
            'reason' => $this->reason,
            'granted_by' => $this->whenLoaded('grantedByUser', fn () => [
                'id' => $this->grantedByUser->public_id,
                'name' => $this->grantedByUser->name,
            ]),
            'revoked_at' => $this->revoked_at?->toIso8601String(),
            'revoked_by' => $this->whenLoaded('revokedByUser', fn () => [
                'id' => $this->revokedByUser?->public_id,
                'name' => $this->revokedByUser?->name,
            ]),
            'revoke_reason' => $this->revoke_reason,
            'status' => $this->getStatus(),
            'days_until_expiry' => $this->daysUntilExpiry(),
            'is_active' => $this->isActive(),
            'is_expired' => $this->isExpired(),
            'in_grace_period' => $this->inGracePeriod(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),
        ];
    }

    /**
     * Get the status label for the override.
     */
    protected function getStatus(): string
    {
        if ($this->revoked_at) {
            return 'revoked';
        }

        if ($this->inGracePeriod()) {
            return 'grace_period';
        }

        if ($this->isExpired()) {
            return 'expired';
        }

        return 'active';
    }
}
