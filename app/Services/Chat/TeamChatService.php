<?php

namespace App\Services\Chat;

use App\Models\Chat\Chat;
use App\Models\Team;
use App\Models\User;
use Illuminate\Support\Collection;

class TeamChatService
{
    /**
     * Ensure the primary chat exists for the team.
     */
    public function ensureTeamChat(Team $team): Chat
    {
        $chat = $team->chats()->where('is_primary', true)->first();

        if (! $chat) {
            $chat = Chat::create([
                'name' => $team->name,
                'type' => 'team',
                'created_by' => $team->owner_id,
                'team_id' => $team->id,
                'is_primary' => true,
            ]);
        } elseif (! $chat->is_primary) {
            $chat->forceFill(['is_primary' => true])->save();
        }

        return $chat;
    }

    /**
     * Initialize the primary chat membership using existing team roles.
     */
    public function initializeTeamChat(Team $team): void
    {
        $chat = $this->ensureTeamChat($team);
        $members = $team->members; // Using eager loaded relation if available

        if ($team->owner && ! $members->contains('id', $team->owner_id)) {
            $owner = $team->owner;
            // Add pivot-like structure for the formatter
            $owner->pivot = (object) ['role' => 'owner'];
            $members->push($owner);
        }

        $payload = $this->formatUserPayload($members, $team);
        if (! empty($payload)) {
            $chat->participants()->syncWithoutDetaching($payload);
            $this->flushChatCache(array_keys($payload));
        }
    }

    public function addMember(Team $team, User $user, string $teamRole = 'member'): void
    {
        $chat = $this->ensureTeamChat($team);
        $chat->participants()->syncWithoutDetaching([
            $user->id => ['role' => $this->mapTeamRoleToChatRole($teamRole)],
        ]);

        $this->flushChatCache([$user->id]);
    }

    public function removeMember(Team $team, int $userId): void
    {
        $team->chats()->each(function (Chat $chat) use ($userId) {
            $chat->participants()->detach($userId);
        });

        $this->flushChatCache([$userId]);
    }

    public function renameChat(Team $team): void
    {
        $chat = $this->ensureTeamChat($team);
        $chat->update(['name' => $team->name]);

        $participantIds = $chat->participants()->pluck('users.id')->all();
        $this->flushChatCache($participantIds);
    }

    public function syncMemberRole(Team $team, int $userId, string $teamRole): void
    {
        $role = $this->mapTeamRoleToChatRole($teamRole);

        $team->chats()->each(function (Chat $chat) use ($userId, $role) {
            if ($chat->participants()->where('users.id', $userId)->exists()) {
                $chat->participants()->updateExistingPivot($userId, ['role' => $role]);
                ChatCache::flushChatList($userId);
            }
        });
    }

    public function createTeamChat(Team $team, string $name, array $memberIds = []): Chat
    {
        $chat = Chat::create([
            'name' => $name,
            'type' => 'team',
            'created_by' => $team->owner_id,
            'team_id' => $team->id,
            'is_primary' => false,
        ]);

        if ($team->owner_id && ! in_array($team->owner_id, $memberIds, true)) {
            $memberIds[] = $team->owner_id;
        }

        if (empty($memberIds) && $team->owner_id) {
            $memberIds = [$team->owner_id];
        }

        $this->attachMembersToChat($team, $chat, $memberIds);

        return $chat;
    }

    public function deleteTeamChat(Team $team, Chat $chat): void
    {
        if ($chat->team_id !== $team->id || $chat->is_primary) {
            return;
        }

        $participantIds = $chat->participants()->pluck('users.id')->all();
        $chat->participants()->detach();
        $chat->delete();

        $this->flushChatCache($participantIds);
    }

    public function attachMembersToChat(Team $team, Chat $chat, array $memberIds): void
    {
        if ($chat->team_id !== $team->id) {
            return;
        }

        $members = $team->members()
            ->whereIn('users.id', $memberIds)
            ->get();

        $payload = $this->formatUserPayload($members, $team);

        if (! empty($payload)) {
            $chat->participants()->syncWithoutDetaching($payload);
            $this->flushChatCache(array_keys($payload));
        }
    }

    public function removeChatMember(Chat $chat, int $userId): void
    {
        $chat->participants()->detach($userId);
        $this->flushChatCache([$userId]);
    }

    public function updateChatMemberRole(Chat $chat, int $userId, string $role): void
    {
        $chat->participants()->updateExistingPivot($userId, ['role' => $role]);
        $this->flushChatCache([$userId]);
    }

    protected function formatUserPayload(Collection $members, Team $team): array
    {
        $payload = [];

        foreach ($members as $member) {
            $role = $member->pivot->role ?? ($member->id === $team->owner_id ? 'owner' : 'member');
            $payload[$member->id] = ['role' => $this->mapTeamRoleToChatRole($role)];
        }

        return $payload;
    }

    protected function mapTeamRoleToChatRole(?string $role): string
    {
        $role = strtolower($role ?? 'member');

        return match ($role) {
            'owner' => 'owner',
            'admin' => 'admin',
            'viewer' => 'viewer',
            default => 'member',
        };
    }

    protected function flushChatCache(array $userIds): void
    {
        foreach (array_filter($userIds) as $userId) {
            ChatCache::flushChatList($userId);
        }
    }
}
