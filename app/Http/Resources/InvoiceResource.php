<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class InvoiceResource extends JsonResource
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
            'invoice_number' => $this->invoice_number,
            'status' => $this->status->value,
            'status_label' => $this->status->label(),
            'status_color' => $this->status->color(),

            // Dates
            'issue_date' => $this->issue_date?->toDateString(),
            'due_date' => $this->due_date?->toDateString(),
            'sent_at' => $this->sent_at?->toIso8601String(),
            'viewed_at' => $this->viewed_at?->toIso8601String(),
            'paid_at' => $this->paid_at?->toIso8601String(),
            'created_at' => $this->created_at?->toIso8601String(),
            'updated_at' => $this->updated_at?->toIso8601String(),

            // Amounts
            'subtotal' => (float) $this->subtotal,
            'tax_rate' => (float) $this->tax_rate,
            'tax_amount' => (float) $this->tax_amount,
            'discount_amount' => (float) $this->discount_amount,
            'total' => (float) $this->total,
            'currency' => $this->currency,

            // Content
            'notes' => $this->notes,
            'terms' => $this->terms,
            'sent_to_email' => $this->sent_to_email,

            // Computed flags
            'is_overdue' => $this->is_overdue,
            'days_until_due' => $this->days_until_due,
            'can_edit' => $this->can_edit,
            'can_send' => $this->can_send,
            'can_record_payment' => $this->can_record_payment,

            // Relationships
            'client' => $this->whenLoaded('client', function () {
                return [
                    'public_id' => $this->client->public_id,
                    'name' => $this->client->name,
                    'email' => $this->client->email,
                    'initials' => $this->client->initials,
                ];
            }),
            'project' => $this->whenLoaded('project', function () {
                if (! $this->project) {
                    return null;
                }

                return [
                    'public_id' => $this->project->public_id,
                    'name' => $this->project->name,
                ];
            }),
            'team' => $this->whenLoaded('team', function () {
                return [
                    'public_id' => $this->team->public_id,
                    'name' => $this->team->name,
                ];
            }),
            'creator' => $this->whenLoaded('creator', function () {
                return [
                    'public_id' => $this->creator->public_id,
                    'name' => $this->creator->name,
                    'avatar_url' => $this->creator->avatar_url,
                ];
            }),
            'items' => InvoiceItemResource::collection($this->whenLoaded('items')),
            'items_count' => $this->whenCounted('items'),
        ];
    }
}
