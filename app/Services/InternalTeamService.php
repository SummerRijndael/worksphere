<?php

namespace App\Services;

use App\Contracts\InternalTeamServiceContract;
use App\Models\InternalTeam;
use App\Models\User;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;
use App\Enums\AuditAction;
use App\Enums\AuditCategory;

class InternalTeamService implements InternalTeamServiceContract
{
    public function __construct(protected \App\Services\AuditService $auditService)
    {
    }

    public function list(array $filters, int $perPage = 15): LengthAwarePaginator
    {
        $query = InternalTeam::query();

        if (isset($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('name', 'LIKE', '%' . $filters['search'] . '%')
                  ->orWhere('department', 'LIKE', '%' . $filters['search'] . '%');
            });
        }
 
        if (isset($filters['status'])) {
            $query->where('status', '=', $filters['status']);
        }
 
        return $query->latest('created_at')->paginate($perPage);
    }

    public function create(array $data, User $actor): InternalTeam
    {
        if (empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        $team = InternalTeam::create([
            'name' => $data['name'],
            'slug' => $data['slug'],
            'department' => $data['department'] ?? null,
            'status' => $data['status'] ?? 'active',
        ]);

        $this->auditService->log(
            action: AuditAction::Created,
            category: AuditCategory::System,
            auditable: $team,
            user: $actor
        );

        return $team;
    }

    public function update(InternalTeam $team, array $data, User $actor): InternalTeam
    {
        if (isset($data['name']) && empty($data['slug'])) {
            $data['slug'] = Str::slug($data['name']);
        }

        $team->update($data);

        $this->auditService->log(
            action: AuditAction::Updated,
            category: AuditCategory::System,
            auditable: $team,
            user: $actor
        );

        return $team;
    }

    public function delete(InternalTeam $team, User $actor): void
    {
        $this->auditService->log(
            action: AuditAction::Deleted,
            category: AuditCategory::System,
            auditable: $team,
            user: $actor
        );

        $team->delete();
    }

    public function addMember(InternalTeam $team, User $user, string $role, User $actor): void
    {
        if (!$team->members()->where('user_id', $user->id)->exists()) {
            $team->members()->attach($user->id, ['role' => $role, 'joined_at' => now()]);

            $this->auditService->log(
                action: AuditAction::MemberAdded,
                category: AuditCategory::TeamManagement,
                auditable: $team,
                user: $actor,
                context: ['member_id' => $user->id, 'role' => $role]
            );
        }
    }

    public function removeMember(InternalTeam $team, User $user, User $actor): void
    {
        $team->members()->detach($user->id);

        $this->auditService->log(
            action: AuditAction::TeamMemberRemoved,
            category: AuditCategory::TeamManagement,
            auditable: $team,
            user: $actor,
            context: ['member_id' => $user->id]
        );
    }

    public function updateMemberRole(InternalTeam $team, User $user, string $role, User $actor): void
    {
        $team->members()->updateExistingPivot($user->id, ['role' => $role]);

        $this->auditService->log(
            action: AuditAction::TeamRoleChanged,
            category: AuditCategory::TeamManagement,
            auditable: $team,
            user: $actor,
            context: ['member_id' => $user->id, 'new_role' => $role]
        );
    }

    public function syncSupportSkills(InternalTeam $team, array $skillIds, User $actor): void
    {
        $team->supportSkills()->sync($skillIds);

        $this->auditService->log(
            action: AuditAction::Updated,
            category: AuditCategory::System,
            auditable: $team,
            user: $actor,
            context: ['synced_skills' => $skillIds]
        );
    }
}
