<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class TicketInternalNoteResource extends JsonResource
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
            'content' => $this->content,
            'author' => $this->whenLoaded('author', fn () => [
                'id' => $this->author->public_id,
                'name' => $this->author->name,
                'initials' => $this->author->initials,
                'avatar_thumb_url' => $this->author->avatar_thumb_url,
            ]),
            'attachments' => $this->getMedia('attachments')->map(fn ($media) => [
                'id' => $media->uuid,
                'name' => $media->file_name,
                // USE SIGNED URL
                'url' => \Illuminate\Support\Facades\URL::temporarySignedRoute(
                    'media.show',
                    now()->addMinutes(60),
                    ['media' => $media->id]
                ),
                'size' => $media->size,
                'mime_type' => $media->mime_type,
                'thumb_url' => $media->hasGeneratedConversion('thumb')
                    ? \Illuminate\Support\Facades\URL::temporarySignedRoute(
                        'media.show',
                        now()->addMinutes(60),
                        ['media' => $media->id, 'conversion' => 'thumb']
                    )
                    : null,
            ]),
            'created_at' => $this->created_at?->toISOString(),
            'created_at_human' => $this->created_at?->diffForHumans(),
        ];
    }
}
