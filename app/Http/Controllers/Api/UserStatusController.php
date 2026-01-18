<?php

namespace App\Http\Controllers\Api;

use App\Events\UserPermissionsUpdated;
use App\Events\UserRoleChanged;
use App\Events\UserStatusChanged;
use App\Http\Controllers\Controller;
use App\Http\Requests\UpdateUserRoleRequest;
use App\Http\Requests\UpdateUserStatusRequest;
use App\Models\User;
use App\Models\UserRoleChange;
use App\Models\UserStatusChange;
use Illuminate\Http\JsonResponse;
use Illuminate\Support\Facades\DB;

class UserStatusController extends Controller
{
    /**
     * Update user account status.
     */
    public function updateStatus(UpdateUserStatusRequest $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        // Prevent self-status change
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot change your own status.'], 403);
        }

        $validated = $request->validated();
        $oldStatus = $user->status;

        DB::transaction(function () use ($user, $validated, $request, $oldStatus) {
            // Create audit record
            UserStatusChange::create([
                'user_id' => $user->id,
                'from_status' => $oldStatus,
                'to_status' => $validated['status'],
                'reason' => $validated['reason'] ?? 'Status updated by administrator',
                'changed_by' => $request->user()->id,
                'password_verified_at' => now(),
                'metadata' => [
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'suspended_until' => $validated['suspended_until'] ?? null,
                ],
            ]);

            // Update user status
            $user->update([
                'status' => $validated['status'],
                'status_reason' => $validated['reason'] ?? null,
                'suspended_until' => $validated['status'] === 'suspended'
                    ? $validated['suspended_until']
                    : null,
            ]);

            // Revoke all sessions if blocking/suspending
            if (in_array($validated['status'], ['blocked', 'suspended'])) {
                DB::table('sessions')->where('user_id', $user->id)->delete();
                $user->tokens()->delete(); // Revoke API tokens
            }
        });

        // Broadcast real-time event
        UserStatusChanged::dispatch(
            $user,
            $validated['status'],
            $validated['reason'] ?? null,
            $request->user(),
            $validated['suspended_until'] ?? null
        );

        return response()->json([
            'message' => 'User status updated successfully.',
            'status' => $validated['status'],
        ]);
    }

    /**
     * Update user role with password confirmation.
     */
    public function updateRole(UpdateUserRoleRequest $request, User $user): JsonResponse
    {
        $this->authorize('update', $user);

        // Prevent self-role change
        if ($user->id === $request->user()->id) {
            return response()->json(['message' => 'You cannot change your own role.'], 403);
        }

        $validated = $request->validated();
        $oldRole = $user->roles->first()?->name;
        $newRole = $validated['role'];

        // Determine action type based on role levels
        $oldLevel = config("roles.roles.{$oldRole}.level", 0);
        $newLevel = config("roles.roles.{$newRole}.level", 0);
        $action = $newLevel > $oldLevel ? 'promoted' : ($newLevel < $oldLevel ? 'demoted' : 'changed');

        DB::transaction(function () use ($user, $validated, $request, $oldRole, $newRole, $oldLevel, $newLevel) {
            // Create audit record
            UserRoleChange::create([
                'user_id' => $user->id,
                'from_role' => $oldRole,
                'to_role' => $newRole,
                'reason' => $validated['reason'],
                'changed_by' => $request->user()->id,
                'password_verified_at' => now(),
                'metadata' => [
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'old_level' => $oldLevel,
                    'new_level' => $newLevel,
                ],
            ]);

            // Sync role (removes old, assigns new)
            $user->syncRoles([$newRole]);

            // If demoted, clear permission cache
            if ($newLevel < $oldLevel) {
                app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
            }
        });

        // Broadcast role change event
        UserRoleChanged::dispatch($user, $oldRole, $newRole, $action);

        // Also broadcast permission update for existing listener
        UserPermissionsUpdated::dispatch($user, 'role_changed');

        return response()->json([
            'message' => 'User role updated successfully.',
            'from_role' => $oldRole,
            'to_role' => $newRole,
            'action' => $action,
        ]);
    }

    /**
     * Get user status change history.
     */
    public function statusHistory(User $user): JsonResponse
    {
        $this->authorize('view', $user);

        $history = $user->statusChanges()
            ->with('changedByUser:id,public_id,name')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($history);
    }

    /**
     * Get user role change history.
     */
    public function roleHistory(User $user): JsonResponse
    {
        $this->authorize('view', $user);

        $history = $user->roleChanges()
            ->with('changedByUser:id,public_id,name')
            ->orderBy('created_at', 'desc')
            ->paginate(20);

        return response()->json($history);
    }
}
