<?php

namespace App\Services;

use App\Models\User;
use Illuminate\Support\Collection;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;

class LockoutProtectionService
{
    /**
     * The permission required to administer the system (manage roles).
     */
    protected const ADMIN_PERMISSION = 'roles.manage';
    
    // Fallback if roles.manage isn't the key, we saw roles.update in code. 
    // Wait, I fixed the code to use roles.update, but does the permission 'roles.manage' actually exist in DB?
    // I should probably check 'roles.update' as the critical one, or the super admin capability.
    // The 'administrator' role has '*' permission.
    // Let's rely on checking if they have the 'administrator' role OR '*' permission.
    
    /**
     * Simulate if a role permission change would cause a lockout.
     * Returns true if safe, throws exception if unsafe.
     * 
     * @param Role $role The role being modified
     * @param array $permissionsToRemove List of permission names being removed
     * @throws \Exception
     */
    public function validateRolePermissionChange(Role $role, array $permissionsToRemove): bool
    {
        // If the role being modified isn't an admin role having critical permissions, it's safe.
        // But 'administrator' role usually has all permissions via '*'. 
        // If we are removing '*' or 'roles.update' or 'users.view' etc from 'administrator', we must check.
        
        // Simpler approach: 
        // 1. Find all users who CURRENTLY have full admin access.
        // 2. Filter out those who would lose it after this change.
        // 3. Count remaining.
        
        // Critical permissions to maintain access
        $criticalPermissions = ['roles.update', 'users.update', 'users.view'];
        
        // Check if we are removing any critical permission
        $removingCritical = false;
        foreach ($criticalPermissions as $perm) {
            if (in_array($perm, $permissionsToRemove) || (in_array('*', $permissionsToRemove))) {
                $removingCritical = true;
                break;
            }
        }
        
        if (!$removingCritical) {
            return true;
        }
        
        // If we are modifying the 'administrator' role itself or any role that grants these permissions
        $affectedUsers = User::role($role->name)->get();
        
        $remainingAdmins = $this->countEffectiveAdmins(excludingUsers: $affectedUsers);
        
        if ($remainingAdmins < 1) {
            throw new \Exception("Operation prevented: This change would leave the system with no active administrators capable of managing roles. Please ensure another administrator exists before modifying this role.");
        }
        
        return true;
    }
    
    /**
     * Simulate if a user role removal would cause lockout.
     */
    public function validateUserRoleRemoval(User $user, string $roleName): bool
    {
        // If user doesn't have the role, safe
        if (!$user->hasRole($roleName)) {
            return true;
        }
        
        // Does this role grant admin access?
        $role = Role::findByName($roleName, $user->guard_name ?? 'web');
        if (!$this->isRoleAdmin($role)) {
            return true;
        }
        
        // If removing admin role, check if others exist
        $remainingAdmins = $this->countEffectiveAdmins(excludingUsers: collect([$user]));
        
        if ($remainingAdmins < 1) {
             throw new \Exception("Operation prevented: Revoking this role would leave the system with no active administrators.");
        }
        
        return true;
    }
    
    /**
     * Validate permission override block/revoke.
     */
    public function validatePermissionOverride(User $targetUser, string $permission, string $action): bool
    {
        // If we are blocking a critical permission or revoking a grant of a critical permission (if they lacked it otherwise)
        if (!in_array($permission, ['roles.update', 'users.update', '*', 'user_manage'])) {
            return true;
        }
        
        // If target user is not an admin, it doesn't matter
        if (!$this->isUserAdmin($targetUser)) {
            return true;
        }
        
        // If they are an admin, and we are blocking access, we count others.
        $remainingAdmins = $this->countEffectiveAdmins(excludingUsers: collect([$targetUser]));
        
        if ($remainingAdmins < 1) {
            throw new \Exception("Operation prevented: This action would revoke critical access from the only remaining administrator.");
        }
        
        return true;
    }
    
    /**
     * Count users who have effective admin access, optionally excluding a set of users.
     * "Effective Admin" = Has 'administrator' role OR has 'roles.update' permission globally.
     */
    protected function countEffectiveAdmins(Collection $excludingUsers): int
    {
        $excludeIds = $excludingUsers->pluck('id')->toArray();
        
        // Get all users with 'administrator' role or 'roles.update' permission
        // Note: usage of 'permission:roles.update' middleware implies 'roles.update' is the key.
        // But 'administrator' role has '*' usually.
        
        // We can use Spatie's scope permissions, calls are expensive if we iterate all users.
        // Better: Get users with 'administrator' role.
        $admins = User::role('administrator')->whereNotIn('id', $excludeIds)->get();
        
        // Also users with direct permission 'roles.update' (if any)
        $usersWithPerm = User::permission('roles.update')->whereNotIn('id', $excludeIds)->get();
        
        $allAdmins = $admins->merge($usersWithPerm)->unique('id');
        
        // Filter for ACTIVE users only
        return $allAdmins->filter(function($user) {
            return $user->status === 'active'; // Assuming 'status' column and 'active' value
        })->count();
    }
    
    protected function isRoleAdmin(Role $role): bool
    {
        return $role->hasPermissionTo('roles.update') || $role->hasPermissionTo('*');
    }
    
    protected function isUserAdmin(User $user): bool
    {
        return $user->hasPermissionTo('roles.update') || $user->hasRole('administrator');
    }
}
