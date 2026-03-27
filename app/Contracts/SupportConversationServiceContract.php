<?php

namespace App\Contracts;

use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\User;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface SupportConversationServiceContract
{
    /**
     * @param  array<string, mixed>  $payload
     */
    public function openConversation(array $payload, ?User $actor = null): SupportConversation;

    public function getConversationForActor(SupportConversation $conversation, ?User $actor = null, ?string $guestToken = null): SupportConversation;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function addCustomerMessage(SupportConversation $conversation, array $payload, ?User $actor = null, ?string $guestToken = null): SupportMessage;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function addAgentMessage(SupportConversation $conversation, User $agent, array $payload): SupportMessage;

    public function assignConversation(SupportConversation $conversation, User $agent, User $actor): SupportConversation;

    /**
     * @param  array<string, mixed>  $options
     */
    public function resolveConversation(SupportConversation $conversation, User $actor, array $options = []): SupportConversation;

    /**
     * @param  array<string, mixed>  $options
     */
    public function closeConversation(
        SupportConversation $conversation,
        ?User $actor = null,
        ?string $guestToken = null,
        array $options = []
    ): SupportConversation;

    public function updateSurveyPreference(
        SupportConversation $conversation,
        bool $optOut,
        ?User $actor = null,
        ?string $guestToken = null
    ): SupportConversation;

    public function claimConversationToUser(SupportConversation $conversation, User $user): SupportConversation;

    public function findActiveConversationByReference(string $chatReference, ?User $actor = null, ?string $guestEmail = null): ?SupportConversation;

    /**
     * @return Collection<int, SupportConversation>
     */
    public function customerHistory(User $user, int $limit = 20): Collection;

    public function canOperateAsAgent(User $user): bool;

    /**
     * @param  array<string, mixed>  $filters
     */
    public function inbox(User $agent, string $scope = 'mine', array $filters = []): LengthAwarePaginator;

    /**
     * @return Collection<int, User>
     */
    public function eligibleAgents(): Collection;

    /**
     * @return array{available: bool, available_agents: int, message: string}
     */
    public function availability(): array;

    /**
     * @return array{
     *     max_panels:int,
     *     hard_cap:int,
     *     agent_capacity:int,
     *     active_chats:int,
     *     available_slots:int,
     *     effective_panel_limit:int
     * }
     */
    public function workbenchCapacity(User $agent): array;

    public function acceptAssignment(SupportConversation $conversation, User $agent): SupportConversation;

    public function rejectAssignment(SupportConversation $conversation, User $agent, ?string $reason = null): SupportConversation;

    /**
     * @param  array<string, mixed>  $payload
     */
    public function transfer(SupportConversation $conversation, User $actor, array $payload): SupportConversation;

    /**
     * @param  array<string, mixed>  $options
     */
    public function completeWrapUp(SupportConversation $conversation, User $actor, array $options = []): SupportConversation;
}
