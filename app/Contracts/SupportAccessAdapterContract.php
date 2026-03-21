<?php

namespace App\Contracts;

use App\Models\SupportConversation;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;

interface SupportAccessAdapterContract
{
    public function canViewAny(User $user): bool;

    public function canAccessConversation(User $user, SupportConversation $conversation): bool;

    public function canReply(User $user, SupportConversation $conversation): bool;

    public function canAssign(User $user, SupportConversation $conversation): bool;

    public function canResolve(User $user, SupportConversation $conversation): bool;

    public function canOperateAsAgent(User $user): bool;

    /**
     * Restrict support inbox visibility for the current actor.
     *
     * @param  Builder<SupportConversation>  $query
     * @return Builder<SupportConversation>
     */
    public function applyInboxAccessScope(User $user, Builder $query): Builder;

    /**
     * Build agent candidate query for assignment/routing.
     *
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function applyEligibleAgentsScope(Builder $query, ?SupportConversation $conversation = null): Builder;

    public function canBeAssignedToConversation(User $user, SupportConversation $conversation): bool;
}

