<?php

namespace App\Http\Resources;

use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;

class MeetingMessageResource extends JsonResource
{
    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $editedAt = isset($metadata['edited_at']) && is_string($metadata['edited_at'])
            ? $metadata['edited_at']
            : null;
        $deletedAt = isset($metadata['deleted_at']) && is_string($metadata['deleted_at'])
            ? $metadata['deleted_at']
            : null;

        return [
            'id' => $this->id,
            'public_id' => $this->public_id,
            'participant_public_id' => $this->participant_public_id,
            'participant_name' => $this->whenLoaded('participant', function () {
                return $this->participant?->display_name;
            }),
            'body' => $this->body,
            'temp_id' => $this->temp_id,
            'metadata' => $this->metadata,
            'attachments' => $this->toAttachmentPayload(),
            'reply_to_id' => $this->reply_to_message_id,
            'thread_root_id' => $this->thread_root_message_id,
            'reply_to' => $this->whenLoaded('replyTo', function () {
                if (! $this->replyTo) {
                    return null;
                }

                return [
                    'id' => $this->replyTo->id,
                    'participant_public_id' => $this->replyTo->participant_public_id,
                    'body' => $this->replyTo->body,
                    'created_at' => $this->replyTo->created_at?->toIso8601String(),
                ];
            }),
            'thread_root' => $this->whenLoaded('threadRoot', function () {
                if (! $this->threadRoot) {
                    return null;
                }

                return [
                    'id' => $this->threadRoot->id,
                    'participant_public_id' => $this->threadRoot->participant_public_id,
                    'body' => $this->threadRoot->body,
                    'created_at' => $this->threadRoot->created_at?->toIso8601String(),
                ];
            }),
            'replies_count' => isset($this->replies_count) ? (int) $this->replies_count : null,
            'is_pinned' => (bool) $this->is_pinned,
            'pinned_at' => $this->pinned_at?->toIso8601String(),
            'pinned_by_participant_public_id' => $this->pinned_by_participant_public_id,
            'pinned_by_name' => $this->whenLoaded('pinnedByParticipant', function () {
                return $this->pinnedByParticipant?->display_name;
            }),
            'is_edited' => ($metadata['is_edited'] ?? false) === true,
            'edited_at' => $editedAt,
            'is_deleted' => ($metadata['is_deleted'] ?? false) === true,
            'deleted_at' => $deletedAt,
            'created_at' => $this->created_at?->toIso8601String(),
        ];
    }
}
