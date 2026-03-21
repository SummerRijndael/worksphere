<?php

namespace App\Policies;

use App\Models\SupportConversation;
use App\Models\User;
use App\Services\Support\Access\SupportAccessAdapterResolver;

class SupportConversationPolicy
{
    public function viewAny(User $user): bool
    {
        return $this->adapter()->canViewAny($user);
    }

    public function view(User $user, SupportConversation $conversation): bool
    {
        return $this->adapter()->canAccessConversation($user, $conversation);
    }

    public function respondAsAgent(User $user, SupportConversation $conversation): bool
    {
        return $this->adapter()->canReply($user, $conversation);
    }

    public function assign(User $user, SupportConversation $conversation): bool
    {
        return $this->adapter()->canAssign($user, $conversation);
    }

    public function resolve(User $user, SupportConversation $conversation): bool
    {
        return $this->adapter()->canResolve($user, $conversation);
    }

    protected function adapter(): \App\Contracts\SupportAccessAdapterContract
    {
        return app(SupportAccessAdapterResolver::class)->resolve();
    }
}
