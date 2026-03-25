<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class DialerCallResource extends JsonResource
{
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->public_id,
            'public_id' => $this->public_id,
            'provider' => $this->provider,
            'provider_call_id' => $this->provider_call_id,
            'direction' => $this->direction,
            'from_number' => $this->from_number,
            'to_number' => $this->to_number,
            'status' => $this->status?->value ?? (string) $this->status,
            'status_label' => $this->status?->label() ?? ucfirst(str_replace('_', ' ', (string) $this->status)),
            'status_tone' => $this->status?->tone() ?? 'secondary',
            'contact_name' => $this->contact_name,
            'notes' => $this->notes,
            'acd_context' => $this->acd_context ?? [],
            'provider_payload' => $this->provider_payload ?? [],
            'requested_at' => $this->requested_at?->toIso8601String(),
            'started_at' => $this->started_at?->toIso8601String(),
            'ended_at' => $this->ended_at?->toIso8601String(),
            'duration_seconds' => $this->duration_seconds,
            'can_hangup' => (bool) $this->status?->isActive(),
        ];
    }
}
