<?php

namespace App\Http\Resources\Support;

use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;

class SupportConversationResource extends JsonResource
{
    public function __construct($resource, protected bool $includePrivateNotes = false, protected bool $exposeGuestToken = false)
    {
        parent::__construct($resource);
    }

    /**
     * Transform the resource into an array.
     *
     * @return array<string, mixed>
     */
    public function toArray(Request $request): array
    {
        $latestMessage = null;
        if ($this->resource->relationLoaded('latestPublicMessage')) {
            $latestMessage = $this->resource->getRelation('latestPublicMessage');
        } elseif ($this->resource->relationLoaded('latestMessage')) {
            $latestMessage = $this->resource->getRelation('latestMessage');
        }

        if ($latestMessage && $latestMessage->is_private_note) {
            $latestMessage = null;
        }

        return [
            'id' => $this->public_id,
            'chat_reference' => $this->chat_reference,
            'status' => $this->status,
            'chat_state' => $this->chat_state,
            'assignment_state' => $this->assignment_state,
            'resolution_marker' => $this->resolution_marker,
            'conversation_type' => $this->conversation_type,
            'end_reason' => $this->end_reason,
            'priority' => $this->priority,
            'channel' => $this->channel,
            'subject' => $this->subject,
            'source_url' => $this->source_url,
            'routing_scope' => $this->routing_scope,
            'support_skill_id' => $this->whenLoaded('skill', fn () => $this->skill?->public_id),
            'support_skill' => $this->whenLoaded('skill', fn () => $this->skill ? [
                'id' => $this->skill->public_id,
                'name' => $this->skill->name,
                'slug' => $this->skill->slug,
                'department' => $this->skill->department,
            ] : null),
            'ai_enabled' => (bool) $this->ai_enabled,
            'survey_opt_out' => (bool) $this->survey_opt_out,
            'survey_opt_out_at' => $this->survey_opt_out_at?->toISOString(),
            'ai_handoff_required' => (bool) $this->ai_handoff_required,
            'ai_handoff_reason' => $this->ai_handoff_reason,
            'guest_name' => $this->guest_name,
            'guest_email' => $this->guest_email,
            'guest_token' => $this->when($this->exposeGuestToken, $this->guest_token),
            'requester' => $this->whenLoaded('requester', fn () => $this->serializeUser($this->requester)),
            'assignee' => $this->whenLoaded('assignee', fn () => $this->serializeUser($this->assignee)),
            'ended_by' => $this->when(
                $this->ended_by_type || $this->ended_by_user_id || $this->ended_by_name,
                fn () => [
                    'type' => $this->ended_by_type,
                    'name' => $this->ended_by_name ?: $this->whenLoaded('endedBy', fn () => $this->endedBy?->name),
                    'user' => $this->whenLoaded('endedBy', fn () => $this->serializeUser($this->endedBy)),
                ]
            ),
            'latest_message' => $latestMessage ? new SupportMessageResource($latestMessage) : null,
            'messages' => $this->whenLoaded('messages', function () {
                $messages = $this->messages;
                if (! $messages instanceof Collection) {
                    $messages = collect($messages);
                }

                if (! $this->includePrivateNotes) {
                    $messages = $messages->where('is_private_note', false)->values();
                }

                return SupportMessageResource::collection($messages);
            }),
            'metadata' => $this->metadata ?? (object) [],
            'assigned_at' => $this->assigned_at?->toISOString(),
            'last_message_at' => $this->last_message_at?->toISOString(),
            'first_response_at' => $this->first_response_at?->toISOString(),
            'resolved_at' => $this->resolved_at?->toISOString(),
            'closed_at' => $this->closed_at?->toISOString(),
            'ended_at' => $this->ended_at?->toISOString(),
            'created_at' => $this->created_at?->toISOString(),
            'updated_at' => $this->updated_at?->toISOString(),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function serializeUser(?User $user): ?array
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
