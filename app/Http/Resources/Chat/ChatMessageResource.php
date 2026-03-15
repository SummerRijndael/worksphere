<?php

namespace App\Http\Resources\Chat;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Str;

class ChatMessageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $reactions = $this->normalizeReactions($metadata['reactions'] ?? []);
        
        // This mirrors ChatEngine::normalizeOne logic
        $isEdited = ($metadata['is_edited'] ?? false) === true;
        $isDeleted = ($metadata['is_deleted'] ?? false) === true;

        return [
            'id' => $this->public_id,
            'type' => $this->type,
            'metadata' => empty($metadata) ? null : $metadata,
            'user_public_id' => $this->user->public_id ?? null,
            'user_name' => $this->user->name ?? ($this->type === 'system' ? 'System' : 'Deactivated User'),
            'user_avatar' => $this->user->avatar_url ?? null,
            'content' => $this->sanitize($this->content ?? ''),
            'created_at' => $this->created_at->toIso8601String(),
            'is_seen' => $this->additional['is_seen'] ?? false,
            'seen' => $this->additional['is_seen'] ?? false,
            'seen_at' => ($this->additional['is_seen'] ?? false) ? ($this->updated_at?->toIso8601String() ?? null) : null,
            'is_pinned' => (bool) ($metadata['is_pinned'] ?? false),
            'pinned_at' => $metadata['pinned_at'] ?? null,
            'pinned_by_user_public_id' => $metadata['pinned_by_user_public_id'] ?? null,
            'pinned_by_user_name' => $metadata['pinned_by_user_name'] ?? null,
            'is_edited' => $isEdited,
            'edited_at' => $metadata['edited_at'] ?? null,
            'edited_by_user_public_id' => $metadata['edited_by_user_public_id'] ?? null,
            'edited_by_user_name' => $metadata['edited_by_user_name'] ?? null,
            'is_deleted' => $isDeleted,
            'deleted_at' => $metadata['deleted_at'] ?? null,
            'deleted_by_user_public_id' => $metadata['deleted_by_user_public_id'] ?? null,
            'deleted_by_user_name' => $metadata['deleted_by_user_name'] ?? null,
            'edit_history_count' => is_array($metadata['edit_history'] ?? null) ? count($metadata['edit_history']) : 0,
            'reactions' => empty($reactions) ? (object) [] : $reactions,
            'reactions' => empty($reactions) ? (object) [] : $reactions,
            'reply_to' => $this->relationLoaded('replyTo') && $this->replyTo ? [
                'id' => $this->replyTo->public_id,
                'user_public_id' => $this->replyTo->user?->public_id,
                'user_name' => $this->replyTo->user?->name,
                'content' => Str::limit($this->replyTo->content ?? '', 100),
                'has_media' => $this->relationLoaded('media') ? $this->replyTo->media->isNotEmpty() : false,
            ] : null,
            'attachments' => ChatMediaResource::collection($this->relationLoaded('media') ? $this->media : collect()),
            'has_media' => $this->relationLoaded('media') ? $this->media->isNotEmpty() : false,
            'preview' => $this->additional['preview'] ?? null,
        ];
    }

    protected function sanitize(string $content): string
    {
        return htmlspecialchars(trim($content), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    protected function normalizeReactions(mixed $reactions): array
    {
        if (! is_array($reactions)) {
            return [];
        }

        $normalized = [];
        foreach ($reactions as $key => $ids) {
            $reactionKey = strtolower((string)$key);
            if ($reactionKey === '100') {
                $reactionKey = 'hundred';
            }

            $userIds = array_values(array_unique(array_filter(
                array_map(static fn ($id) => strtolower((string) $id), is_array($ids) ? $ids : []),
                static fn (string $id) => $id !== ''
            )));

            if (! empty($userIds)) {
                $normalized[$reactionKey] = $userIds;
            }
        }

        return $normalized;
    }
}
