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

    public function resolveConversation(SupportConversation $conversation, User $actor): SupportConversation;

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
}
