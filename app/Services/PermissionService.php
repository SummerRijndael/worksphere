<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\AuditCategory;
use App\Enums\TeamRole;
use App\Models\PermissionOverride;
use App\Models\Team;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\PermissionRegistrar;

class PermissionService
{
    public function __construct(
        protected CacheService $cache,
        protected ?AuditService $audit = null
    ) {}

    /**
     * Check if user has a global permission (Spatie wrapper).
     */
    public function hasPermission(User $user, string $permission): bool
    {
        return $user->hasPermissionTo($permission);
    }

    /**
     * Check if user has any of the given global permissions.
     *
     * @param  array<string>  $permissions
     */
    public function hasAnyPermission(User $user, array $permissions): bool
    {
        return $user->hasAnyPermission($permissions);
    }

    /**
     * Check if user has all of the given global permissions.
     *
     * @param  array<string>  $permissions
     */
    public function hasAllPermissions(User $user, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (! $user->hasPermissionTo($permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Check if user has a permission within a specific team.
     */
    public function hasTeamPermission(User $user, Team $team, string $permission): bool
    {
        $cacheKey = $this->getTeamPermissionCacheKey($user->id, $team->id, $permission);

        return $this->cache->remember(
            $cacheKey,
            $this->cache->getTtl('team_permissions'),
            fn () => $this->checkTeamPermission($user, $team, $permission),
            'team_permissions'
        );
    }

    /**
     * Check multiple team permissions at once.
     *
     * @param  array<string>  $permissions
     * @return array<string, bool>
     */
    public function hasTeamPermissions(User $user, Team $team, array $permissions): array
    {
        $results = [];
        foreach ($permissions as $permission) {
            $results[$permission] = $this->hasTeamPermission($user, $team, $permission);
        }

        return $results;
    }

    /**
     * Check if user has any of the given team permissions.
     *
     * @param  array<string>  $permissions
     */
    public function hasAnyTeamPermission(User $user, Team $team, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if ($this->hasTeamPermission($user, $team, $permission)) {
                return true;
            }
        }

        return false;
    }

    /**
     * Check if user has all of the given team permissions.
     *
     * @param  array<string>  $permissions
     */
    public function hasAllTeamPermissions(User $user, Team $team, array $permissions): bool
    {
        foreach ($permissions as $permission) {
            if (! $this->hasTeamPermission($user, $team, $permission)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Get all permissions for a user in a specific team.
     *
     * @return Collection<int, string>
     */
    public function getTeamPermissions(User $user, Team $team): Collection
    {
        $cacheKey = "team_permissions:{$user->id}:{$team->id}:all";

        return $this->cache->remember(
            $cacheKey,
            $this->cache->getTtl('team_permissions'),
            function () use ($user, $team): Collection {
                // Get team-specific permissions
                $teamPermissions = DB::table('team_permissions')
                    ->join('permissions', 'team_permissions.permission_id', '=', 'permissions.id')
                    ->where('team_permissions.team_id', $team->id)
                    ->where('team_permissions.user_id', $user->id)
                    ->pluck('permissions.name');

                // Get role-inherited permissions (from team_user pivot)
                $teamRole = $this->getUserTeamRole($user, $team);
                $rolePermissions = $this->getPermissionsForTeamRole($teamRole);

                return $teamPermissions->merge($rolePermissions)->unique()->values();
            },
            'team_permissions'
        );
    }

    /**
     * Grant a permission to a user within a team.
     */
    public function grantTeamPermission(User $user, Team $team, string $permissionName): bool
    {
        $permission = Permission::findByName($permissionName);

        DB::table('team_permissions')->insertOrIgnore([
            'team_id' => $team->id,
            'user_id' => $user->id,
            'permission_id' => $permission->id,
            'created_at' => now(),
            'updated_at' => now(),
        ]);

        $this->invalidateTeamPermissionCache($user, $team);

        return true;
    }

    /**
     * Revoke a permission from a user within a team.
     */
    public function revokeTeamPermission(User $user, Team $team, string $permissionName): bool
    {
        $permission = Permission::findByName($permissionName);

        DB::table('team_permissions')
            ->where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->where('permission_id', $permission->id)
            ->delete();

        $this->invalidateTeamPermissionCache($user, $team);

        return true;
    }

    /**
     * Sync team permissions for a user.
     *
     * @param  array<string>  $permissions
     */
    public function syncTeamPermissions(User $user, Team $team, array $permissions): void
    {
        DB::transaction(function () use ($user, $team, $permissions): void {
            // Remove existing permissions
            DB::table('team_permissions')
                ->where('team_id', $team->id)
                ->where('user_id', $user->id)
                ->delete();

            if (empty($permissions)) {
                return;
            }

            // Add new permissions
            $permissionIds = Permission::whereIn('name', $permissions)->pluck('id');

            $inserts = $permissionIds->map(fn ($id) => [
                'team_id' => $team->id,
                'user_id' => $user->id,
                'permission_id' => $id,
                'created_at' => now(),
                'updated_at' => now(),
            ])->toArray();

            DB::table('team_permissions')->insert($inserts);
        });

        $this->invalidateTeamPermissionCache($user, $team);
    }

    /**
     * Get user's role within a team.
     */
    public function getUserTeamRole(User $user, Team $team): ?TeamRole
    {
        $pivot = DB::table('team_user')
            ->where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->first();

        if (! $pivot) {
            return null;
        }

        return TeamRole::tryFrom($pivot->role);
    }

    /**
     * Check if user is team owner.
     */
    public function isTeamOwner(User $user, Team $team): bool
    {
        return $team->owner_id === $user->id;
    }

    /**
     * Check if user is team admin (owner or admin role).
     */
    public function isTeamAdmin(User $user, Team $team): bool
    {
        if ($this->isTeamOwner($user, $team)) {
            return true;
        }

        $role = $this->getUserTeamRole($user, $team);

        return $role && in_array($role, [TeamRole::TeamLead, TeamRole::SubjectMatterExpert]);
    }

    /**
     * Check if user is a team member (any role).
     */
    public function isTeamMember(User $user, Team $team): bool
    {
        return DB::table('team_user')
            ->where('team_id', $team->id)
            ->where('user_id', $user->id)
            ->exists();
    }

    /**
     * Invalidate all permission caches for a user.
     */
    public function invalidateUserPermissionCache(User $user): void
    {
        $this->cache->flushTags(["user:{$user->id}:permissions"]);

        // Also clear Spatie's cache
        app(PermissionRegistrar::class)->forgetCachedPermissions();
    }

    /**
     * Invalidate team permission cache for a user.
     */
    public function invalidateTeamPermissionCache(User $user, Team $team): void
    {
        $this->cache->flushTags([
            "team_permissions:{$team->id}",
            "user:{$user->id}:team_permissions",
        ]);

        // Forget specific keys as fallback
        $this->cache->forget("team_permissions:{$user->id}:{$team->id}:all");
    }

    /**
     * Invalidate all team permission caches for a team.
     */
    public function invalidateTeamCache(Team $team): void
    {
        $this->cache->flushTags(["team_permissions:{$team->id}"]);
    }

    /**
     * Warm permission cache for a user.
     */
    public function warmUserPermissionCache(User $user): void
    {
        // Warm global permissions
        $user->getAllPermissions();

        // Warm team permissions for user's teams
        if (method_exists($user, 'teams')) {
            $teams = $user->teams ?? collect();
            foreach ($teams as $team) {
                $this->getTeamPermissions($user, $team);
            }
        }
    }

    /**
     * Check team permission without cache.
     */
    protected function checkTeamPermission(User $user, Team $team, string $permission): bool
    {
        // 1. Check Scope Logic
        // Team Owners should ONLY inherit permissions that are scoped to 'team'.
        // They should NOT inherit 'global' permissions (like users.manage) just because they own a team.
        $scope = $this->getPermissionScope($permission);
        if ($scope === 'global') {
            return false;
        }

        // Super admin check
        $superAdminRole = config('roles.super_admin_role', 'administrator');
        if ($user->hasRole($superAdminRole)) {
            return true;
        }

        // Team owner has all permissions (within team scope)
        if ($this->isTeamOwner($user, $team)) {
            return true;
        }

        // Check explicit team permission
        $hasExplicit = DB::table('team_permissions')
            ->join('permissions', 'team_permissions.permission_id', '=', 'permissions.id')
            ->where('team_permissions.team_id', $team->id)
            ->where('team_permissions.user_id', $user->id)
            ->where('permissions.name', $permission)
            ->exists();

        if ($hasExplicit) {
            return true;
        }

        // Check role-based team permission
        $teamRole = $this->getUserTeamRole($user, $team);
        if ($teamRole) {
            $rolePermissions = $this->getPermissionsForTeamRole($teamRole);

            return $rolePermissions->contains($permission);
        }

        return false;
    }

    /**
     * Get default permissions for a team role.
     *
     * @return Collection<int, string>
     */
    protected function getPermissionsForTeamRole(?TeamRole $role): Collection
    {
        if (! $role) {
            return collect();
        }

        $rolePermissions = config('roles.team_role_permissions', []);

        return collect($rolePermissions[$role->value] ?? []);
    }

    /**
     * Generate cache key for team permission check.
     */
    protected function getTeamPermissionCacheKey(int $userId, int $teamId, string $permission): string
    {
        return "team_permission:{$userId}:{$teamId}:".md5($permission);
    }

    // =========================================================================
    // PERMISSION OVERRIDE METHODS
    // =========================================================================

    /**
     * Check if user has a permission considering overrides.
     * Check order: Super Admin → Explicit Block → Explicit Grant → Team Permission → Role Permission
     */
    public function hasPermissionWithOverrides(User $user, string $permission, ?Team $team = null): bool
    {
        // Super admin bypasses all
        $superAdminRole = config('roles.super_admin_role', 'administrator');
        if ($user->hasRole($superAdminRole)) {
            return true;
        }

        // Check for explicit block (highest priority after super admin)
        if ($this->hasActiveBlock($user, $permission, $team)) {
            return false;
        }

        // Check for explicit grant
        if ($this->hasActiveGrant($user, $permission, $team)) {
            return true;
        }

        // Fall back to regular permission check
        if ($team) {
            return $this->hasTeamPermission($user, $team, $permission);
        }

        return $this->hasPermission($user, $permission);
    }

    /**
     * Check if user has an active block for a permission.
     */
    public function hasActiveBlock(User $user, string $permission, ?Team $team = null): bool
    {
        $query = PermissionOverride::active()
            ->forUser($user)
            ->forPermission($permission)
            ->blocks();

        if ($team) {
            $query->where(function ($q) use ($team) {
                $q->where('scope', 'global')
                    ->orWhere(function ($q2) use ($team) {
                        $q2->where('scope', 'team')
                            ->where('team_id', $team->id);
                    });
            });
        } else {
            $query->global();
        }

        return $query->exists();
    }

    /**
     * Check if user has an active grant for a permission.
     */
    public function hasActiveGrant(User $user, string $permission, ?Team $team = null): bool
    {
        $query = PermissionOverride::active()
            ->forUser($user)
            ->forPermission($permission)
            ->grants();

        if ($team) {
            $query->where(function ($q) use ($team) {
                $q->where('scope', 'global')
                    ->orWhere(function ($q2) use ($team) {
                        $q2->where('scope', 'team')
                            ->where('team_id', $team->id);
                    });
            });
        } else {
            $query->global();
        }

        return $query->exists();
    }

    /**
     * Grant a permission override to a user.
     *
     * @param  array{
     *     scope?: string,
     *     team_id?: int|null,
     *     is_temporary?: bool,
     *     expires_at?: Carbon|null,
     *     expiry_behavior?: string|null,
     *     grace_period_days?: int|null
     * }  $options
     */
    public function grantOverride(
        User $user,
        string $permission,
        string $reason,
        User $grantedBy,
        array $options = []
    ): PermissionOverride {
        $override = PermissionOverride::create([
            'user_id' => $user->id,
            'permission' => $permission,
            'type' => 'grant',
            'scope' => $options['scope'] ?? 'global',
            'team_id' => $options['team_id'] ?? null,
            'is_temporary' => $options['is_temporary'] ?? false,
            'expires_at' => $options['expires_at'] ?? null,
            'expiry_behavior' => $options['expiry_behavior'] ?? null,
            'grace_period_days' => $options['grace_period_days'] ?? null,
            'reason' => $reason,
            'granted_by' => $grantedBy->id,
            'approved_at' => now(),
        ]);

        $this->invalidateUserPermissionCache($user);
        $this->logOverrideChange('grant', $override, $user, $grantedBy);

        return $override;
    }

    /**
     * Block a permission for a user.
     *
     * @param  array{
     *     scope?: string,
     *     team_id?: int|null,
     *     is_temporary?: bool,
     *     expires_at?: Carbon|null,
     *     expiry_behavior?: string|null,
     *     grace_period_days?: int|null
     * }  $options
     */
    public function blockOverride(
        User $user,
        string $permission,
        string $reason,
        User $grantedBy,
        array $options = []
    ): PermissionOverride {
        $override = PermissionOverride::create([
            'user_id' => $user->id,
            'permission' => $permission,
            'type' => 'block',
            'scope' => $options['scope'] ?? 'global',
            'team_id' => $options['team_id'] ?? null,
            'is_temporary' => $options['is_temporary'] ?? false,
            'expires_at' => $options['expires_at'] ?? null,
            'expiry_behavior' => $options['expiry_behavior'] ?? null,
            'grace_period_days' => $options['grace_period_days'] ?? null,
            'reason' => $reason,
            'granted_by' => $grantedBy->id,
            'approved_at' => now(),
        ]);

        $this->invalidateUserPermissionCache($user);
        $this->logOverrideChange('block', $override, $user, $grantedBy);

        return $override;
    }

    /**
     * Revoke an existing permission override.
     */
    public function revokeOverride(
        PermissionOverride $override,
        string $reason,
        User $revokedBy
    ): void {
        $override->update([
            'revoked_at' => now(),
            'revoked_by' => $revokedBy->id,
            'revoke_reason' => $reason,
        ]);

        $this->invalidateUserPermissionCache($override->user);
        $this->logOverrideChange('revoke', $override, $override->user, $revokedBy);
    }

    /**
     * Renew a temporary permission override.
     */
    public function renewTemporaryPermission(
        PermissionOverride $override,
        Carbon $newExpiry,
        User $renewedBy
    ): void {
        $oldExpiry = $override->expires_at;

        $override->update([
            'expires_at' => $newExpiry,
        ]);

        $this->invalidateUserPermissionCache($override->user);

        if ($this->audit) {
            $this->audit->log(
                AuditAction::Updated,
                AuditCategory::Authorization,
                $override,
                $renewedBy,
                ['expires_at' => $oldExpiry?->toIso8601String()],
                ['expires_at' => $newExpiry->toIso8601String()],
                ['action' => 'renew', 'permission' => $override->permission]
            );
        }
    }

    /**
     * Get all active permission overrides for a user.
     *
     * @return Collection<int, PermissionOverride>
     */
    public function getUserOverrides(User $user): Collection
    {
        return PermissionOverride::active()
            ->forUser($user)
            ->with(['team', 'grantedByUser'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get permissions expiring within a given number of days.
     *
     * @return Collection<int, PermissionOverride>
     */
    public function getExpiringPermissions(int $daysAhead = 7): Collection
    {
        return PermissionOverride::expiringSoon($daysAhead)
            ->with(['user', 'team', 'grantedByUser'])
            ->orderBy('expires_at')
            ->get();
    }

    /**
     * Process and revoke expired permissions.
     * Called by scheduler.
     */
    public function processExpiredPermissions(): int
    {
        $expired = PermissionOverride::expired()->get();
        $count = 0;

        foreach ($expired as $override) {
            $override->update([
                'revoked_at' => now(),
                'revoke_reason' => 'Automatically revoked due to expiration',
            ]);

            $this->invalidateUserPermissionCache($override->user);
            $count++;

            if ($this->audit) {
                $this->audit->log(
                    AuditAction::PermissionRevoked,
                    AuditCategory::Authorization,
                    $override,
                    null,
                    null,
                    null,
                    ['action' => 'auto_expire', 'permission' => $override->permission]
                );
            }
        }

        return $count;
    }

    /**
     * Copy permission overrides from one user to another.
     */
    public function copyOverridesFromUser(User $source, User $target, User $copiedBy): void
    {
        $overrides = PermissionOverride::active()
            ->forUser($source)
            ->get();

        foreach ($overrides as $override) {
            PermissionOverride::create([
                'user_id' => $target->id,
                'permission' => $override->permission,
                'type' => $override->type,
                'scope' => $override->scope,
                'team_id' => $override->team_id,
                'is_temporary' => $override->is_temporary,
                'expires_at' => $override->expires_at,
                'expiry_behavior' => $override->expiry_behavior,
                'grace_period_days' => $override->grace_period_days,
                'reason' => "Copied from user: {$source->name}",
                'granted_by' => $copiedBy->id,
                'approved_at' => now(),
            ]);
        }

        $this->invalidateUserPermissionCache($target);
    }

    /**
     * Get effective permissions for a user including overrides.
     *
     * @return array{granted: array<string>, blocked: array<string>, base: array<string>}
     */
    public function getEffectivePermissions(User $user, ?Team $team = null): array
    {
        $basePermissions = $user->getAllPermissions()->pluck('name')->toArray();

        $overrides = PermissionOverride::active()
            ->forUser($user)
            ->when($team, function ($q) use ($team) {
                $q->where(function ($q2) use ($team) {
                    $q2->where('scope', 'global')
                        ->orWhere(function ($q3) use ($team) {
                            $q3->where('scope', 'team')
                                ->where('team_id', $team->id);
                        });
                });
            }, function ($q) {
                $q->global();
            })
            ->get();

        $granted = $overrides->where('type', 'grant')->pluck('permission')->toArray();
        $blocked = $overrides->where('type', 'block')->pluck('permission')->toArray();

        return [
            'granted' => $granted,
            'blocked' => $blocked,
            'base' => $basePermissions,
        ];
    }

    /**
     * Log an override change to audit.
     */
    protected function logOverrideChange(
        string $action,
        PermissionOverride $override,
        User $targetUser,
        User $performedBy
    ): void {
        if (! $this->audit) {
            return;
        }

        $auditAction = match ($action) {
            'grant' => AuditAction::PermissionGranted,
            'block' => AuditAction::PermissionRevoked,
            'revoke' => AuditAction::PermissionRevoked,
            default => AuditAction::Updated,
        };

        $this->audit->log(
            $auditAction,
            AuditCategory::Authorization,
            $override,
            $performedBy,
            null,
            [
                'permission' => $override->permission,
                'type' => $override->type,
                'scope' => $override->scope,
                'is_temporary' => $override->is_temporary,
            ],
            [
                'action' => $action,
                'target_user_id' => $targetUser->id,
                'target_user_name' => $targetUser->name,
            ]
        );
    }
    /**
     * Get the scope of a permission from config.
     */
    public function getPermissionScope(string $permissionName): string
    {
        $permissions = config('roles.permissions', []);

        foreach ($permissions as $group) {
            if (isset($group[$permissionName])) {
                $def = $group[$permissionName];
                // Handle both new array format and legacy string format (fallback)
                if (is_array($def)) {
                    return $def['scope'] ?? 'global';
                }
            }
        }

        // If not found or legacy format, err on side of caution?
        // Actually, if it's not defined, it might be a dynamic permission.
        // Default to 'global' to be safe (deny team owner inheritance).
        return 'global';
    }
}
