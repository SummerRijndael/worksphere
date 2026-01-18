<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditAction;
use App\Enums\AuditCategory;
use App\Http\Controllers\Controller;
use App\Http\Requests\TeamRole\StoreTeamRoleRequest;
use App\Http\Requests\TeamRole\UpdateTeamRoleRequest;
use App\Http\Resources\TeamRoleResource;
use App\Models\Team;
use App\Models\TeamRole;
use App\Models\User;
use App\Services\AuditService;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TeamRoleController extends Controller
{
    public function __construct(
        protected AuditService $auditService,
        protected PermissionService $permissionService
    ) {}

    /**
     * Display a listing of team roles.
     */
    public function index(Request $request, Team $team): AnonymousResourceCollection
    {
        $this->authorizeTeamPermission($team, 'team_roles.view');

        $query = $team->roles()
            ->with(['permissions', 'creator'])
            ->withCount('users')
            ->when($request->search, function ($query, $search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            })
            ->when($request->boolean('custom_only'), function ($query) {
                $query->where('is_system', false);
            })
            ->orderBy('level', 'desc');

        if ($request->has('per_page')) {
            $roles = $query->paginate($request->integer('per_page', 15));
        } else {
            $roles = $query->get();
        }

        return TeamRoleResource::collection($roles);
    }

    /**
     * Store a newly created team role.
     */
    public function store(StoreTeamRoleRequest $request, Team $team): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'team_roles.create');

        $validated = $request->validated();

        $role = $team->roles()->create([
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'color' => $validated['color'] ?? 'primary',
            'level' => $validated['level'] ?? 50,
            'is_default' => $validated['is_default'] ?? false,
            'created_by' => $request->user()->id,
        ]);

        if (! empty($validated['permissions'])) {
            $role->syncPermissions($validated['permissions']);
        }

        if ($role->is_default) {
            $team->roles()
                ->where('id', '!=', $role->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        $this->auditService->log(
            action: AuditAction::Created,
            category: AuditCategory::TeamManagement,
            auditable: $role,
            context: [
                'team_id' => $team->id,
                'role_name' => $role->name,
                'permissions_count' => count($validated['permissions'] ?? []),
            ]
        );

        $role->load(['permissions', 'creator']);
        $role->loadCount('users');

        return response()->json(new TeamRoleResource($role), 201);
    }

    /**
     * Display the specified team role.
     */
    public function show(Team $team, TeamRole $role): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'team_roles.view');
        $this->ensureRoleBelongsToTeam($team, $role);

        $role->load(['permissions', 'creator']);
        $role->loadCount('users');

        return response()->json(new TeamRoleResource($role));
    }

    /**
     * Update the specified team role.
     */
    public function update(UpdateTeamRoleRequest $request, Team $team, TeamRole $role): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'team_roles.update');
        $this->ensureRoleBelongsToTeam($team, $role);

        if ($role->is_system) {
            return response()->json([
                'message' => 'System roles cannot be modified.',
            ], 403);
        }

        $validated = $request->validated();
        $oldValues = $role->only(['name', 'description', 'color', 'level', 'is_default']);

        $role->update([
            'name' => $validated['name'] ?? $role->name,
            'description' => $validated['description'] ?? $role->description,
            'color' => $validated['color'] ?? $role->color,
            'level' => $validated['level'] ?? $role->level,
            'is_default' => $validated['is_default'] ?? $role->is_default,
        ]);

        if (isset($validated['permissions'])) {
            $role->syncPermissions($validated['permissions']);
        }

        if ($role->is_default) {
            $team->roles()
                ->where('id', '!=', $role->id)
                ->where('is_default', true)
                ->update(['is_default' => false]);
        }

        $this->auditService->log(
            action: AuditAction::Updated,
            category: AuditCategory::TeamManagement,
            auditable: $role,
            context: [
                'team_id' => $team->id,
                'old_values' => $oldValues,
                'new_values' => $role->only(['name', 'description', 'color', 'level', 'is_default']),
            ]
        );

        $role->load(['permissions', 'creator']);
        $role->loadCount('users');

        return response()->json(new TeamRoleResource($role));
    }

    /**
     * Remove the specified team role.
     */
    public function destroy(Team $team, TeamRole $role): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'team_roles.delete');
        $this->ensureRoleBelongsToTeam($team, $role);

        if ($role->is_system) {
            return response()->json([
                'message' => 'System roles cannot be deleted.',
            ], 403);
        }

        if (! $role->canBeDeleted()) {
            return response()->json([
                'message' => 'Cannot delete role that has members assigned. Please reassign members first.',
            ], 409);
        }

        $roleName = $role->name;
        $role->delete();

        $this->auditService->log(
            action: AuditAction::Deleted,
            category: AuditCategory::TeamManagement,
            auditable: $role,
            context: [
                'team_id' => $team->id,
                'role_name' => $roleName,
            ]
        );

        return response()->json([
            'message' => 'Role deleted successfully.',
        ]);
    }

    /**
     * Assign a role to a team member.
     */
    public function assignToMember(Request $request, Team $team, TeamRole $role, User $member): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'team_roles.assign');
        $this->ensureRoleBelongsToTeam($team, $role);

        if (! $team->hasMember($member)) {
            return response()->json([
                'message' => 'User is not a member of this team.',
            ], 404);
        }

        $currentUser = $request->user();
        $currentUserRole = $this->getUserTeamRole($team, $currentUser);
        $targetUserRole = $this->getUserTeamRole($team, $member);

        if ($currentUserRole && $targetUserRole && ! $currentUserRole->hasHigherLevelThan($targetUserRole)) {
            if ($currentUserRole->id !== $targetUserRole->id) {
                return response()->json([
                    'message' => 'You cannot modify roles for members with equal or higher role level.',
                ], 403);
            }
        }

        if ($role->level > ($currentUserRole?->level ?? 0) && ! $team->isOwner($currentUser)) {
            return response()->json([
                'message' => 'You cannot assign a role with higher level than your own.',
            ], 403);
        }

        $oldRoleId = $team->members()
            ->where('user_id', $member->id)
            ->first()
            ?->pivot
            ?->team_role_id;

        $team->members()->updateExistingPivot($member->id, [
            'team_role_id' => $role->id,
            'role' => $role->slug,
        ]);

        $this->auditService->log(
            action: AuditAction::TeamRoleChanged,
            category: AuditCategory::TeamManagement,
            auditable: $team,
            context: [
                'member_id' => $member->id,
                'member_name' => $member->name,
                'old_role_id' => $oldRoleId,
                'new_role_id' => $role->id,
                'new_role_name' => $role->name,
            ]
        );

        return response()->json([
            'message' => 'Role assigned successfully.',
            'role' => new TeamRoleResource($role),
        ]);
    }

    /**
     * Get members with a specific role.
     */
    public function members(Request $request, Team $team, TeamRole $role): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'team_roles.view');
        $this->ensureRoleBelongsToTeam($team, $role);

        $members = $role->users()
            ->when($request->search, function ($query, $search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            })
            ->paginate($request->integer('per_page', 15));

        return response()->json($members);
    }

    /**
     * Get available permissions for team roles.
     */
    public function availablePermissions(Team $team): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'team_roles.view');

        $permissions = config('roles.permissions', []);

        $teamPermissions = [];
        foreach ($permissions as $module => $modulePermissions) {
            $teamPermissions[$module] = [];
            foreach ($modulePermissions as $key => $label) {
                $teamPermissions[$module][] = [
                    'key' => $key,
                    'label' => $label,
                ];
            }
        }

        return response()->json($teamPermissions);
    }

    /**
     * Authorize team permission.
     */
    protected function authorizeTeamPermission(Team $team, string $permission): void
    {
        $user = request()->user();

        if (! $this->permissionService->hasTeamPermission($user, $team, $permission)) {
            abort(403, 'You do not have permission to perform this action.');
        }
    }

    /**
     * Ensure role belongs to the team.
     */
    protected function ensureRoleBelongsToTeam(Team $team, TeamRole $role): void
    {
        if ($role->team_id !== $team->id) {
            abort(404, 'Role not found in this team.');
        }
    }

    /**
     * Get user's team role.
     */
    protected function getUserTeamRole(Team $team, User $user): ?TeamRole
    {
        $pivot = $team->members()
            ->where('user_id', $user->id)
            ->first()
            ?->pivot;

        if ($pivot?->team_role_id) {
            return TeamRole::find($pivot->team_role_id);
        }

        return null;
    }
}
