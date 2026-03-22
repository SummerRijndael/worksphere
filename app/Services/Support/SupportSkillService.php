<?php

namespace App\Services\Support;

use App\Models\SupportConversation;
use App\Models\SupportSkill;
use App\Models\SupportSkillMembership;
use App\Models\User;
use Illuminate\Support\Arr;
use Illuminate\Support\Facades\DB;

class SupportSkillService
{
    public function __construct(
        protected SupportRoutingService $supportRoutingService
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function upsertSkill(array $payload, ?SupportSkill $skill = null, ?User $actor = null): SupportSkill
    {
        $attributes = [
            'name' => (string) ($payload['name'] ?? $skill?->name ?? 'New Skill'),
            'description' => isset($payload['description']) ? (string) $payload['description'] : $skill?->description,
            'department' => isset($payload['department']) ? (string) $payload['department'] : $skill?->department,
            'is_active' => (bool) ($payload['is_active'] ?? $skill?->is_active ?? true),
            'priority' => max(1, (int) ($payload['priority'] ?? $skill?->priority ?? 100)),
            'settings' => is_array($payload['settings'] ?? null) ? $payload['settings'] : ($skill?->settings ?? null),
        ];

        if (! $skill) {
            $attributes['created_by'] = $actor?->id;
            if (! empty($payload['slug'])) {
                $attributes['slug'] = (string) $payload['slug'];
            }

            return SupportSkill::query()->create($attributes);
        }

        if (! empty($payload['slug'])) {
            $attributes['slug'] = (string) $payload['slug'];
        }

        $skill->fill($attributes)->save();

        return $skill->fresh();
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function setAgentMembership(SupportSkill $skill, User $user, array $payload = []): SupportSkillMembership
    {
        return DB::transaction(function () use ($skill, $user, $payload): SupportSkillMembership {
            $membership = SupportSkillMembership::query()->updateOrCreate(
                [
                    'support_skill_id' => $skill->id,
                    'user_id' => $user->id,
                ],
                [
                    'membership_role' => (string) ($payload['membership_role'] ?? 'agent'),
                    'is_primary' => (bool) ($payload['is_primary'] ?? false),
                    'is_active' => (bool) ($payload['is_active'] ?? true),
                    'capacity' => Arr::has($payload, 'capacity') ? (int) $payload['capacity'] : null,
                    'settings' => is_array($payload['settings'] ?? null) ? $payload['settings'] : null,
                ]
            );

            if ($membership->is_primary) {
                SupportSkillMembership::query()
                    ->where('user_id', $user->id)
                    ->where('support_skill_id', '!=', $skill->id)
                    ->update(['is_primary' => false]);
            }

            return $membership;
        });
    }

    public function removeAgentMembership(SupportSkill $skill, User $user): void
    {
        SupportSkillMembership::query()
            ->where('support_skill_id', $skill->id)
            ->where('user_id', $user->id)
            ->delete();
    }

    public function assignConversationSkill(SupportConversation $conversation, ?SupportSkill $skill, ?User $actor = null): SupportConversation
    {
        $conversation->forceFill([
            'support_skill_id' => $skill?->id,
            'routing_scope' => $skill ? 'skill' : 'global',
            'ai_handoff_required' => $skill ? false : $conversation->ai_handoff_required,
        ])->save();

        $this->supportRoutingService->enqueueConversation(
            $conversation->fresh(),
            reason: $skill ? 'skill_routed' : 'routing_reset'
        );

        return $conversation->fresh([
            'requester',
            'assignee',
            'endedBy',
            'skill',
            'latestMessage.sender',
            'latestMessage.media',
            'messages.sender',
            'messages.media',
        ]);
    }
}
