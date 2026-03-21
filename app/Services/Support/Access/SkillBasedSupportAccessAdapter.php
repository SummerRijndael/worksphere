<?php

namespace App\Services\Support\Access;

use App\Contracts\SupportAccessAdapterContract;
use App\Models\SupportConversation;
use App\Models\SupportSkillMembership;
use App\Models\User;
use Illuminate\Database\Eloquent\Builder;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class SkillBasedSupportAccessAdapter implements SupportAccessAdapterContract
{
    public function __construct(
        protected LegacySupportAccessAdapter $legacyAdapter
    ) {}

    public function canViewAny(User $user): bool
    {
        if ($this->isGlobalAdmin($user)) {
            return true;
        }

        if ($this->hasActiveSkillMembership($user)) {
            return true;
        }

        return $this->allowLegacyFallback() && $this->legacyAdapter->canViewAny($user);
    }

    public function canAccessConversation(User $user, SupportConversation $conversation): bool
    {
        if ((int) $conversation->requester_user_id === (int) $user->id) {
            return true;
        }

        if (! $this->canOperateAsAgent($user)) {
            return false;
        }

        if ($this->isGlobalAdmin($user)) {
            return true;
        }

        if (! $conversation->support_skill_id) {
            return $this->allowUnroutedConversationFallback() && $this->legacyAdapter->canAccessConversation($user, $conversation);
        }

        return $this->hasActiveSkillMembership($user, (int) $conversation->support_skill_id);
    }

    public function canReply(User $user, SupportConversation $conversation): bool
    {
        if (! $this->canAccessConversation($user, $conversation)) {
            return false;
        }

        if ($this->isGlobalAdmin($user)) {
            return true;
        }

        if (! $conversation->support_skill_id) {
            return $this->allowUnroutedConversationFallback() && $this->legacyAdapter->canReply($user, $conversation);
        }

        return $this->roleHasCapability($this->membershipRoleForSkill($user, (int) $conversation->support_skill_id), 'reply');
    }

    public function canAssign(User $user, SupportConversation $conversation): bool
    {
        if (! $this->canAccessConversation($user, $conversation)) {
            return false;
        }

        if ($this->isGlobalAdmin($user)) {
            return true;
        }

        if (! $conversation->support_skill_id) {
            return $this->allowUnroutedConversationFallback() && $this->legacyAdapter->canAssign($user, $conversation);
        }

        return $this->roleHasCapability($this->membershipRoleForSkill($user, (int) $conversation->support_skill_id), 'assign');
    }

    public function canResolve(User $user, SupportConversation $conversation): bool
    {
        if (! $this->canAccessConversation($user, $conversation)) {
            return false;
        }

        if ($this->isGlobalAdmin($user)) {
            return true;
        }

        if (! $conversation->support_skill_id) {
            return $this->allowUnroutedConversationFallback() && $this->legacyAdapter->canResolve($user, $conversation);
        }

        return $this->roleHasCapability($this->membershipRoleForSkill($user, (int) $conversation->support_skill_id), 'resolve');
    }

    public function canOperateAsAgent(User $user): bool
    {
        if ($this->isGlobalAdmin($user)) {
            return true;
        }

        if ($this->hasActiveSkillMembership($user)) {
            return true;
        }

        return $this->allowLegacyFallback() && $this->legacyAdapter->canOperateAsAgent($user);
    }

    /**
     * @param  Builder<SupportConversation>  $query
     * @return Builder<SupportConversation>
     */
    public function applyInboxAccessScope(User $user, Builder $query): Builder
    {
        if ($this->isGlobalAdmin($user)) {
            return $query;
        }

        $memberships = SupportSkillMembership::query()
            ->where('user_id', $user->id)
            ->where('is_active', true)
            ->get(['support_skill_id', 'membership_role']);

        if ($memberships->isEmpty()) {
            if ($this->allowUnroutedConversationFallback() && $this->legacyAdapter->canViewAny($user)) {
                return $query->whereNull('support_skill_id');
            }

            return $query->whereRaw('1 = 0');
        }

        $rolesBySkill = $memberships
            ->groupBy('support_skill_id')
            ->map(fn ($rows) => (string) $rows->pluck('membership_role')->last());

        $queueSkillIds = [];
        $assignedSkillIds = [];
        foreach ($rolesBySkill as $skillId => $membershipRole) {
            if ($this->roleHasCapability($membershipRole, 'view_queue')) {
                $queueSkillIds[] = (int) $skillId;
            } else {
                $assignedSkillIds[] = (int) $skillId;
            }
        }

        return $query->where(function (Builder $scoped) use ($queueSkillIds, $assignedSkillIds, $user): void {
            $hasClause = false;

            if ($queueSkillIds !== []) {
                $scoped->whereIn('support_skill_id', $queueSkillIds);
                $hasClause = true;
            }

            if ($assignedSkillIds !== []) {
                $callback = function (Builder $assigned) use ($assignedSkillIds, $user): void {
                    $assigned->whereIn('support_skill_id', $assignedSkillIds)
                        ->where('assigned_to', $user->id);
                };

                if ($hasClause) {
                    $scoped->orWhere($callback);
                } else {
                    $scoped->where($callback);
                    $hasClause = true;
                }
            }

            if ($this->allowUnroutedConversationFallback() && $this->legacyAdapter->canViewAny($user)) {
                if ($hasClause) {
                    $scoped->orWhereNull('support_skill_id');
                } else {
                    $scoped->whereNull('support_skill_id');
                }
            }
        });
    }

    /**
     * @param  Builder<User>  $query
     * @return Builder<User>
     */
    public function applyEligibleAgentsScope(Builder $query, ?SupportConversation $conversation = null): Builder
    {
        if ($conversation && $conversation->support_skill_id) {
            $skillId = (int) $conversation->support_skill_id;

            return $query->whereHas('supportSkillMemberships', function (Builder $membership) use ($skillId): void {
                $membership
                    ->where('support_skill_id', $skillId)
                    ->where('is_active', true);
            });
        }

        return $query->whereHas('supportSkillMemberships', fn (Builder $membership) => $membership->where('is_active', true));
    }

    public function canBeAssignedToConversation(User $user, SupportConversation $conversation): bool
    {
        if ($this->isGlobalAdmin($user)) {
            return true;
        }

        if (! $conversation->support_skill_id) {
            return $this->allowUnroutedConversationFallback() && $this->legacyAdapter->canOperateAsAgent($user);
        }

        $role = $this->membershipRoleForSkill($user, (int) $conversation->support_skill_id);

        return $this->roleHasCapability($role, 'reply');
    }

    protected function allowLegacyFallback(): bool
    {
        return (bool) config('support_chat.skills.allow_legacy_fallback', true);
    }

    protected function allowUnroutedConversationFallback(): bool
    {
        return (bool) config('support_chat.skills.allow_unrouted_conversation_fallback', true);
    }

    protected function isGlobalAdmin(User $user): bool
    {
        if ($user->hasRole(config('roles.super_admin_role', 'administrator'))) {
            return true;
        }

        $globalRoles = array_values(array_filter((array) config('support_chat.skills.global_admin_roles', [])));
        if ($globalRoles !== [] && $user->hasAnyRole($globalRoles)) {
            return true;
        }

        $globalPermissions = array_values(array_filter((array) config('support_chat.skills.global_admin_permissions', ['tickets.manage'])));
        foreach ($globalPermissions as $permission) {
            try {
                if ($user->hasPermissionTo($permission)) {
                    return true;
                }
            } catch (PermissionDoesNotExist) {
                continue;
            } catch (\Throwable) {
                continue;
            }
        }

        return false;
    }

    protected function hasActiveSkillMembership(User $user, ?int $skillId = null): bool
    {
        return SupportSkillMembership::query()
            ->where('user_id', $user->id)
            ->when($skillId !== null, fn (Builder $query) => $query->where('support_skill_id', $skillId))
            ->where('is_active', true)
            ->exists();
    }

    protected function membershipRoleForSkill(User $user, int $skillId): ?string
    {
        $role = SupportSkillMembership::query()
            ->where('user_id', $user->id)
            ->where('support_skill_id', $skillId)
            ->where('is_active', true)
            ->value('membership_role');

        return is_string($role) ? $role : null;
    }

    protected function roleHasCapability(?string $role, string $capability): bool
    {
        if (! $role) {
            return false;
        }

        $capabilities = (array) config('support_chat.skills.role_capabilities', []);
        $granted = (array) ($capabilities[$role] ?? []);

        return in_array($capability, $granted, true);
    }
}
