<?php

namespace App\Http\Resources\Support;

use App\Models\SupportConversation;
use App\Models\SupportRoutingQueueEntry;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\JsonResource;
use Illuminate\Support\Collection;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\Schema;

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

        $queuePosition = $this->resolveQueuePosition();
        $queueState = $this->resolveQueueState();
        $queueEnteredAt = $this->resolveQueueEnteredAt();
        $waitingSinceAt = $this->resolveWaitingSinceAt();
        $aiAssistingSinceAt = $this->resolveAiAssistingSinceAt();
        $assignedSinceAt = $this->resolveAssignedSinceAt();

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
            'queue_position' => $queuePosition,
            'queue' => [
                'position' => $queuePosition,
                'state' => $queueState,
                'entered_at' => $queueEnteredAt,
            ],
            'timers' => [
                'waiting_since_at' => $waitingSinceAt,
                'ai_assisting_since_at' => $aiAssistingSinceAt,
                'assigned_since_at' => $assignedSinceAt,
            ],
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

    protected function resolveQueueState(): ?string
    {
        $fromAttribute = $this->resource->getAttribute('queue_state');
        if (is_string($fromAttribute) && trim($fromAttribute) !== '') {
            return $fromAttribute;
        }

        if ($this->resource->relationLoaded('routingQueueEntry')) {
            return $this->resource->getRelation('routingQueueEntry')?->state;
        }

        return null;
    }

    protected function resolveQueueEnteredAt(): ?string
    {
        $fromAttribute = $this->resource->getAttribute('queue_entered_at');
        if ($fromAttribute instanceof Carbon) {
            return $fromAttribute->toISOString();
        }
        if (is_string($fromAttribute) && trim($fromAttribute) !== '') {
            return $this->parseIso($fromAttribute)?->toISOString();
        }

        if ($this->resource->relationLoaded('routingQueueEntry')) {
            return $this->resource->getRelation('routingQueueEntry')?->created_at?->toISOString();
        }

        return null;
    }

    protected function resolveQueuePosition(): ?int
    {
        $attributes = $this->resource->getAttributes();
        if (array_key_exists('queue_position', $attributes)) {
            $fromAttribute = $attributes['queue_position'];
            return is_numeric($fromAttribute) ? max(1, (int) $fromAttribute) : null;
        }

        $fromAttribute = $this->resource->getAttribute('queue_position');
        if (is_numeric($fromAttribute)) {
            return max(1, (int) $fromAttribute);
        }

        if (! Schema::hasTable('support_routing_queue_entries')) {
            return null;
        }

        $entry = $this->resolveRoutingQueueEntry();
        if (
            ! $entry
            || ! in_array($entry->state, [SupportRoutingQueueEntry::STATE_PENDING, SupportRoutingQueueEntry::STATE_ROUTING], true)
        ) {
            return null;
        }

        return SupportRoutingQueueEntry::query()
            ->whereIn('state', [
                SupportRoutingQueueEntry::STATE_PENDING,
                SupportRoutingQueueEntry::STATE_ROUTING,
            ])
            ->where(function ($query) use ($entry): void {
                $query->where('priority', '<', $entry->priority)
                    ->orWhere(function ($samePriority) use ($entry): void {
                        $samePriority->where('priority', $entry->priority)
                            ->where(function ($sameCreatedAt) use ($entry): void {
                                $sameCreatedAt->where('created_at', '<', $entry->created_at)
                                    ->orWhere(function ($sameMoment) use ($entry): void {
                                        $sameMoment->where('created_at', $entry->created_at)
                                            ->where('id', '<=', $entry->id);
                                    });
                            });
                    });
            })
            ->count();
    }

    protected function resolveWaitingSinceAt(): ?string
    {
        if (
            ! in_array((string) $this->status, [
                SupportConversation::STATUS_WAITING_HUMAN,
                SupportConversation::STATUS_PENDING_ACCEPTANCE,
            ], true)
        ) {
            return null;
        }

        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $metadataSince = $this->parseIso($metadata['waiting_for_agent_since'] ?? null);

        return ($metadataSince ?? $this->created_at)?->toISOString();
    }

    protected function resolveAiAssistingSinceAt(): ?string
    {
        $metadata = is_array($this->metadata) ? $this->metadata : [];
        $metadataSince = $this->parseIso($metadata['ai_assisting_since'] ?? null);
        if ($metadataSince) {
            return $metadataSince->toISOString();
        }

        if (
            in_array((string) $this->status, [SupportConversation::STATUS_BOT_ACTIVE, SupportConversation::STATUS_OPEN], true)
            && ! $this->assigned_to
            && (bool) $this->ai_enabled
        ) {
            return $this->created_at?->toISOString();
        }

        return null;
    }

    protected function resolveAssignedSinceAt(): ?string
    {
        if (! $this->assigned_to) {
            return null;
        }

        return ($this->assigned_at ?? $this->first_response_at ?? $this->created_at)?->toISOString();
    }

    protected function resolveRoutingQueueEntry(): ?SupportRoutingQueueEntry
    {
        if ($this->resource->relationLoaded('routingQueueEntry')) {
            return $this->resource->getRelation('routingQueueEntry');
        }

        return SupportRoutingQueueEntry::query()
            ->where('conversation_id', $this->id)
            ->first(['id', 'conversation_id', 'state', 'priority', 'created_at']);
    }

    protected function parseIso(mixed $value): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            return Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }
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
