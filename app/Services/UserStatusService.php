<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\AuditCategory;
use App\Models\User;
use App\Models\UserStatusChange;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Hash;

class UserStatusService
{
    public function __construct(
        protected CacheService $cache,
        protected ?AuditService $audit = null
    ) {}

    /**
     * Change a user's status with reason and password verification.
     */
    public function changeStatus(
        User $user,
        string $newStatus,
        string $reason,
        User $changedBy,
        string $password
    ): UserStatusChange {
        // Verify password
        if (! $this->verifyAdminPassword($changedBy, $password)) {
            throw new \InvalidArgumentException('Invalid password');
        }

        // Validate status
        $allowedStatuses = array_keys(config('roles.statuses', []));
        if (! in_array($newStatus, $allowedStatuses)) {
            throw new \InvalidArgumentException("Invalid status: {$newStatus}");
        }

        // Don't allow changing to same status
        if ($user->status === $newStatus) {
            throw new \InvalidArgumentException('User already has this status');
        }

        $oldStatus = $user->status;

        return DB::transaction(function () use ($user, $newStatus, $oldStatus, $reason, $changedBy) {
            // Record the status change
            $statusChange = UserStatusChange::create([
                'user_id' => $user->id,
                'from_status' => $oldStatus,
                'to_status' => $newStatus,
                'reason' => $reason,
                'changed_by' => $changedBy->id,
                'password_verified_at' => now(),
                'metadata' => $this->buildChangeMetadata($user, $oldStatus, $newStatus),
                'created_at' => now(),
            ]);

            // Update user status
            $user->update([
                'status' => $newStatus,
                'status_reason' => $reason,
            ]);

            // Perform side effects based on status change
            $this->handleStatusSideEffects($user, $oldStatus, $newStatus);

            // Log the change
            $this->logStatusChange($statusChange, $changedBy);

            return $statusChange;
        });
    }

    /**
     * Handle side effects of status changes.
     */
    protected function handleStatusSideEffects(User $user, string $oldStatus, string $newStatus): void
    {
        // Revoke sessions if user is being blocked/suspended/disabled
        if (in_array($newStatus, ['blocked', 'suspended', 'disabled'])) {
            $this->revokeUserSessions($user);
        }

        // Invalidate any permission caches
        $this->cache->flushTags(["user:{$user->id}:permissions"]);
    }

    /**
     * Revoke all user sessions.
     */
    protected function revokeUserSessions(User $user): void
    {
        // Delete personal access tokens
        if (method_exists($user, 'tokens')) {
            $user->tokens()->delete();
        }

        // Delete remember tokens
        $user->setRememberToken(null);
        $user->save();

        // Delete sessions from session table if using database sessions
        if (config('session.driver') === 'database') {
            DB::table('sessions')
                ->where('user_id', $user->id)
                ->delete();
        }
    }

    /**
     * Build metadata for the status change.
     *
     * @return array<string, mixed>
     */
    protected function buildChangeMetadata(User $user, string $oldStatus, string $newStatus): array
    {
        return [
            'user_email' => $user->email,
            'user_name' => $user->name,
            'old_status_label' => $this->getStatusLabel($oldStatus),
            'new_status_label' => $this->getStatusLabel($newStatus),
            'sessions_revoked' => in_array($newStatus, ['blocked', 'suspended', 'disabled']),
            'ip_address' => request()?->ip(),
            'user_agent' => request()?->userAgent(),
        ];
    }

    /**
     * Get the human-readable label for a status.
     */
    public function getStatusLabel(string $status): string
    {
        $statuses = config('roles.statuses', []);

        return $statuses[$status]['label'] ?? ucfirst($status);
    }

    /**
     * Get the color for a status.
     */
    public function getStatusColor(string $status): string
    {
        $statuses = config('roles.statuses', []);

        return $statuses[$status]['color'] ?? 'gray';
    }

    /**
     * Get status history for a user.
     *
     * @return Collection<int, UserStatusChange>
     */
    public function getStatusHistory(User $user, int $limit = 50): Collection
    {
        return UserStatusChange::forUser($user)
            ->with('changedByUser')
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get recent status changes across all users.
     *
     * @return Collection<int, UserStatusChange>
     */
    public function getRecentStatusChanges(int $days = 30, int $limit = 100): Collection
    {
        return UserStatusChange::recent($days)
            ->with(['user', 'changedByUser'])
            ->orderBy('created_at', 'desc')
            ->limit($limit)
            ->get();
    }

    /**
     * Get statistics about status changes.
     *
     * @return array<string, mixed>
     */
    public function getStatistics(int $days = 30): array
    {
        $changes = UserStatusChange::recent($days)->get();

        $byStatus = $changes->groupBy('to_status')
            ->map(fn ($group) => $group->count());

        $byAdmin = $changes->groupBy('changed_by')
            ->map(fn ($group) => [
                'count' => $group->count(),
                'admin' => $group->first()->changedByUser?->name ?? 'Unknown',
            ])
            ->sortByDesc('count')
            ->take(10);

        return [
            'total_changes' => $changes->count(),
            'by_status' => $byStatus->toArray(),
            'by_admin' => $byAdmin->values()->toArray(),
            'activations' => $changes->where('to_status', 'active')->count(),
            'deactivations' => $changes->whereIn('to_status', ['blocked', 'suspended', 'disabled'])->count(),
        ];
    }

    /**
     * Verify admin password.
     */
    public function verifyAdminPassword(User $admin, string $password): bool
    {
        return Hash::check($password, $admin->password);
    }

    /**
     * Check if a status transition is allowed.
     */
    public function isTransitionAllowed(string $fromStatus, string $toStatus): bool
    {
        // Define allowed transitions (or allow all by default)
        $restricted = [
            // Example: 'deleted' => [], // Can't transition from deleted
        ];

        if (isset($restricted[$fromStatus])) {
            return in_array($toStatus, $restricted[$fromStatus]);
        }

        return true;
    }

    /**
     * Get available status options for a user.
     *
     * @return array<string, array{label: string, color: string, description: string}>
     */
    public function getAvailableStatuses(User $user): array
    {
        $statuses = config('roles.statuses', []);
        $currentStatus = $user->status;

        $available = [];
        foreach ($statuses as $key => $config) {
            if ($key !== $currentStatus && $this->isTransitionAllowed($currentStatus, $key)) {
                $available[$key] = $config;
            }
        }

        return $available;
    }

    /**
     * Log a status change.
     */
    protected function logStatusChange(UserStatusChange $statusChange, User $changedBy): void
    {
        if (! $this->audit) {
            return;
        }

        $this->audit->log(
            AuditAction::Updated,
            AuditCategory::UserManagement,
            $statusChange->user,
            $changedBy,
            ['status' => $statusChange->from_status],
            ['status' => $statusChange->to_status],
            [
                'action' => 'status_change',
                'reason' => $statusChange->reason,
                'from_status' => $statusChange->from_status,
                'to_status' => $statusChange->to_status,
            ]
        );
    }
}
