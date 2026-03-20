<?php

namespace App\Http\Resources\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class SupportMessageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $attachments = is_object($this->resource) && method_exists($this->resource, 'toAttachmentPayload')
            ? $this->resource->toAttachmentPayload()
            : (is_array($this->attachments) ? $this->attachments : []);

        return [
            'id' => $this->public_id,
            'sender_type' => $this->sender_type,
            'is_private_note' => (bool) $this->is_private_note,
            'body' => $this->body,
            'attachments' => $attachments,
            'metadata' => $this->metadata ?? (object) [],
            'sender' => $this->whenLoaded('sender', fn () => $this->serializeSender($this->sender)),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function serializeSender(?User $user): ?array
    {
        if (! $user) {
            return null;
        }

        $avatar = $user->getAvatarData();
        $thumb = $user->getAvatarData('thumb');

        return [
            'id' => $user->public_id,
            'name' => $user->name,
            'email' => $user->email,
            'avatar_url' => $avatar->getUrl(),
            'avatar_thumb_url' => $thumb->getUrl(),
            'avatar_color' => $avatar->color,
        ];
    }
}
