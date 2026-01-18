<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\AuditCategory;
use App\Models\RoleChangeApproval;
use App\Models\RoleChangeRequest;
use App\Models\User;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Role;

class RoleChangeService
{
    public function __construct(
        protected CacheService $cache,
        protected ?AuditService $audit = null
    ) {}

    /**
     * Get the required approval count from config.
     */
    public function getRequiredApprovalCount(): int
    {
        return (int) config('roles.role_change_approval_count', 2);
    }

    /**
     * Get the request expiry days from config.
     */
    public function getRequestExpiryDays(): int
    {
        return (int) config('roles.role_change_request_expiry_days', 7);
    }

    /**
     * Request a role title change.
     */
    public function requestRoleTitleChange(
        Role $role,
        string $newTitle,
        string $reason,
        User $requester
    ): RoleChangeRequest {
        return $this->createRequest(
            'role_title_change',
            $role,
            ['old_name' => $role->name, 'new_name' => $newTitle],
            $reason,
            $requester
        );
    }

    /**
     * Request a role permission change.
     *
     * @param  array<string>  $permissions
     */
    public function requestRolePermissionChange(
        Role $role,
        array $permissions,
        string $reason,
        User $requester
    ): RoleChangeRequest {
        $currentPermissions = $role->permissions->pluck('name')->toArray();

        return $this->createRequest(
            'role_permission_change',
            $role,
            [
                'old_permissions' => $currentPermissions,
                'new_permissions' => $permissions,
            ],
            $reason,
            $requester
        );
    }

    /**
     * Request creation of a new role.
     *
     * @param  array{name: string, permissions?: array<string>, guard_name?: string}  $roleData
     */
    public function requestRoleCreate(
        array $roleData,
        string $reason,
        User $requester
    ): RoleChangeRequest {
        return $this->createRequest(
            'role_create',
            null,
            $roleData,
            $reason,
            $requester
        );
    }

    /**
     * Request deletion of a role.
     */
    public function requestRoleDelete(
        Role $role,
        string $reason,
        User $requester
    ): RoleChangeRequest {
        return $this->createRequest(
            'role_delete',
            $role,
            ['role_name' => $role->name, 'role_id' => $role->id],
            $reason,
            $requester
        );
    }

    /**
     * Create a role change request.
     *
     * @param  array<string, mixed>  $changes
     */
    protected function createRequest(
        string $type,
        ?Role $role,
        array $changes,
        string $reason,
        User $requester
    ): RoleChangeRequest {
        $request = RoleChangeRequest::create([
            'type' => $type,
            'target_role_id' => $role?->id,
            'requested_changes' => $changes,
            'reason' => $reason,
            'requested_by' => $requester->id,
            'required_approvals' => $this->getRequiredApprovalCount(),
            'expires_at' => now()->addDays($this->getRequestExpiryDays()),
        ]);

        $this->logRequestCreated($request, $requester);

        return $request;
    }

    /**
     * Approve a role change request.
     */
    public function approveRequest(
        RoleChangeRequest $request,
        User $admin,
        string $password,
        ?string $comment = null
    ): RoleChangeApproval {
        // Verify password
        if (! $this->verifyAdminPassword($admin, $password)) {
            throw new \InvalidArgumentException('Invalid password');
        }

        // Verify admin can approve
        if (! $request->canBeApprovedBy($admin)) {
            throw new \InvalidArgumentException('You cannot approve this request');
        }

        $approval = DB::transaction(function () use ($request, $admin, $comment) {
            $approval = RoleChangeApproval::create([
                'request_id' => $request->id,
                'admin_id' => $admin->id,
                'action' => 'approve',
                'password_verified_at' => now(),
                'comment' => $comment,
                'created_at' => now(),
            ]);

            // Check if fully approved
            if ($request->fresh()->isFullyApproved()) {
                $this->processApprovedRequest($request);
            }

            return $approval;
        });

        $this->logApproval($request, $admin, 'approve', $comment);

        return $approval;
    }

    /**
     * Reject a role change request.
     */
    public function rejectRequest(
        RoleChangeRequest $request,
        User $admin,
        string $password,
        string $reason
    ): RoleChangeApproval {
        // Verify password
        if (! $this->verifyAdminPassword($admin, $password)) {
            throw new \InvalidArgumentException('Invalid password');
        }

        // Verify admin can approve/reject
        if (! $request->canBeApprovedBy($admin)) {
            throw new \InvalidArgumentException('You cannot reject this request');
        }

        $approval = DB::transaction(function () use ($request, $admin, $reason) {
            $approval = RoleChangeApproval::create([
                'request_id' => $request->id,
                'admin_id' => $admin->id,
                'action' => 'reject',
                'password_verified_at' => now(),
                'comment' => $reason,
                'created_at' => now(),
            ]);

            $request->update([
                'status' => 'rejected',
                'completed_at' => now(),
            ]);

            return $approval;
        });

        $this->logApproval($request, $admin, 'reject', $reason);

        return $approval;
    }

    /**
     * Process an approved role change request.
     */
    public function processApprovedRequest(RoleChangeRequest $request): void
    {
        DB::transaction(function () use ($request) {
            $changes = $request->requested_changes;

            match ($request->type) {
                'role_title_change' => $this->applyRoleTitleChange($request, $changes),
                'role_permission_change' => $this->applyRolePermissionChange($request, $changes),
                'role_create' => $this->applyRoleCreate($request, $changes),
                'role_delete' => $this->applyRoleDelete($request, $changes),
                default => throw new \InvalidArgumentException("Unknown request type: {$request->type}"),
            };

            $request->update([
                'status' => 'approved',
                'completed_at' => now(),
            ]);
        });

        $this->logRequestCompleted($request);
    }

    /**
     * Apply role title change.
     *
     * @param  array<string, mixed>  $changes
     */
    protected function applyRoleTitleChange(RoleChangeRequest $request, array $changes): void
    {
        $role = $request->targetRole;
        if ($role) {
            $role->update(['name' => $changes['new_name']]);
        }
    }

    /**
     * Apply role permission change.
     *
     * @param  array<string, mixed>  $changes
     */
    protected function applyRolePermissionChange(RoleChangeRequest $request, array $changes): void
    {
        $role = $request->targetRole;
        if ($role) {
            $role->syncPermissions($changes['new_permissions']);
        }
    }

    /**
     * Apply role creation.
     *
     * @param  array<string, mixed>  $changes
     */
    protected function applyRoleCreate(RoleChangeRequest $request, array $changes): void
    {
        $role = Role::create([
            'name' => $changes['name'],
            'guard_name' => $changes['guard_name'] ?? 'web',
        ]);

        if (! empty($changes['permissions'])) {
            $role->syncPermissions($changes['permissions']);
        }
    }

    /**
     * Apply role deletion.
     *
     * @param  array<string, mixed>  $changes
     */
    protected function applyRoleDelete(RoleChangeRequest $request, array $changes): void
    {
        $role = $request->targetRole;
        $role?->delete();
    }

    /**
     * Verify admin password.
     */
    public function verifyAdminPassword(User $admin, string $password): bool
    {
        return Hash::check($password, $admin->password);
    }

    /**
     * Get pending requests for an admin.
     *
     * @return Collection<int, RoleChangeRequest>
     */
    public function getPendingRequests(): Collection
    {
        return RoleChangeRequest::pending()
            ->with(['targetRole', 'requestedByUser', 'approvals.admin'])
            ->orderBy('created_at', 'desc')
            ->get();
    }

    /**
     * Get pending requests that an admin can approve.
     *
     * @return Collection<int, RoleChangeRequest>
     */
    public function getPendingRequestsForAdmin(User $admin): Collection
    {
        return $this->getPendingRequests()
            ->filter(fn ($request) => $request->canBeApprovedBy($admin));
    }

    /**
     * Get request history.
     *
     * @return Collection<int, RoleChangeRequest>
     */
    public function getRequestHistory(int $limit = 50): Collection
    {
        return RoleChangeRequest::with(['targetRole', 'requestedByUser', 'approvals.admin'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Expire old pending requests.
     */
    public function expireOldRequests(): int
    {
        return RoleChangeRequest::where('status', 'pending')
            ->where('expires_at', '<=', now())
            ->update([
                'status' => 'expired',
                'completed_at' => now(),
            ]);
    }

    /**
     * Log request creation.
     */
    protected function logRequestCreated(RoleChangeRequest $request, User $requester): void
    {
        if (! $this->audit) {
            return;
        }

        $this->audit->log(
            AuditAction::Created,
            AuditCategory::Authorization,
            $request,
            $requester,
            null,
            [
                'type' => $request->type,
                'requested_changes' => $request->requested_changes,
                'required_approvals' => $request->required_approvals,
            ],
            ['action' => 'role_change_request_created']
        );
    }

    /**
     * Log approval/rejection.
     */
    protected function logApproval(
        RoleChangeRequest $request,
        User $admin,
        string $action,
        ?string $comment
    ): void {
        if (! $this->audit) {
            return;
        }

        $auditAction = $action === 'approve' ? AuditAction::Updated : AuditAction::Deleted;

        $this->audit->log(
            $auditAction,
            AuditCategory::Authorization,
            $request,
            $admin,
            null,
            [
                'action' => $action,
                'comment' => $comment,
                'current_approvals' => $request->currentApprovalCount(),
                'required_approvals' => $request->required_approvals,
            ],
            ['action' => "role_change_request_{$action}"]
        );
    }

    /**
     * Log request completion.
     */
    protected function logRequestCompleted(RoleChangeRequest $request): void
    {
        if (! $this->audit) {
            return;
        }

        $this->audit->log(
            AuditAction::Updated,
            AuditCategory::Authorization,
            $request,
            null,
            null,
            [
                'type' => $request->type,
                'status' => 'approved',
                'changes_applied' => $request->requested_changes,
            ],
            ['action' => 'role_change_request_completed']
        );
    }
}
