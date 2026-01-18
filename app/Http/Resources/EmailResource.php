<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class EmailResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'message_id' => $this->message_id,
            'subject' => $this->subject,
            'preview' => $this->preview,
            'body_html' => $this->body_html ?? $this->body_plain ?? '', // Map to 'body_html' to match model
            'body_plain' => $this->body_plain,
            'date' => $this->received_at ? $this->received_at->toIso8601String() : ($this->created_at ? $this->created_at->toIso8601String() : now()->toIso8601String()),

            // Revert to flat structure matching Model
            'from_name' => $this->from_name,
            'from_email' => $this->from_email,

            // Recipients
            'to' => $this->to ?? [],
            'cc' => $this->cc ?? [],
            'bcc' => $this->bcc ?? [],

            // Flags
            'is_read' => (bool) $this->is_read,
            'is_starred' => (bool) $this->is_starred,
            'is_draft' => (bool) $this->is_draft,

            // Attachments
            'has_attachments' => (bool) $this->has_attachments,
            'attachments' => $this->attachments, // Uses the model accessor we created

            // Metadata
            'folder' => $this->folder,
            'labels' => $this->whenLoaded('labels', fn () => $this->labels->map(fn ($l) => $l->name)),
            'headers' => $this->headers ?? [],

            // Helpful timestamps
            'created_at' => $this->created_at,
            'updated_at' => $this->updated_at,
        ];
    }
}
