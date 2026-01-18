<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Str;
use Spatie\Permission\Models\Role;

class RoleChangeRequest extends Model
{
    protected $fillable = [
        'type',
        'target_role_id',
        'requested_changes',
        'reason',
        'requested_by',
        'status',
        'required_approvals',
        'expires_at',
        'completed_at',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (RoleChangeRequest $request): void {
            if (empty($request->public_id)) {
                $request->public_id = (string) Str::uuid();
            }
            if (empty($request->status)) {
                $request->status = 'pending';
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    protected function casts(): array
    {
        return [
            'requested_changes' => 'array',
            'expires_at' => 'datetime',
            'completed_at' => 'datetime',
            'required_approvals' => 'integer',
        ];
    }

    // Relationships

    public function targetRole(): BelongsTo
    {
        return $this->belongsTo(Role::class, 'target_role_id');
    }

    public function requestedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'requested_by');
    }

    public function approvals(): HasMany
    {
        return $this->hasMany(RoleChangeApproval::class, 'request_id');
    }

    // Scopes

    /**
     * @param  Builder<RoleChangeRequest>  $query
     * @return Builder<RoleChangeRequest>
     */
    public function scopePending(Builder $query): Builder
    {
        return $query->where('status', 'pending')
            ->where('expires_at', '>', now());
    }

    /**
     * @param  Builder<RoleChangeRequest>  $query
     * @return Builder<RoleChangeRequest>
     */
    public function scopeApproved(Builder $query): Builder
    {
        return $query->where('status', 'approved');
    }

    /**
     * @param  Builder<RoleChangeRequest>  $query
     * @return Builder<RoleChangeRequest>
     */
    public function scopeRejected(Builder $query): Builder
    {
        return $query->where('status', 'rejected');
    }

    /**
     * @param  Builder<RoleChangeRequest>  $query
     * @return Builder<RoleChangeRequest>
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->where(function (Builder $q) {
            $q->where('status', 'expired')
                ->orWhere(function (Builder $q2) {
                    $q2->where('status', 'pending')
                        ->where('expires_at', '<=', now());
                });
        });
    }

    /**
     * @param  Builder<RoleChangeRequest>  $query
     * @return Builder<RoleChangeRequest>
     */
    public function scopeAwaitingApproval(Builder $query): Builder
    {
        return $query->pending()
            ->whereDoesntHave('approvals', function (Builder $q) {
                $q->where('action', 'reject');
            });
    }

    // Helper methods

    public function currentApprovalCount(): int
    {
        return $this->approvals()
            ->where('action', 'approve')
            ->count();
    }

    public function currentRejectionCount(): int
    {
        return $this->approvals()
            ->where('action', 'reject')
            ->count();
    }

    public function isFullyApproved(): bool
    {
        return $this->currentApprovalCount() >= $this->required_approvals;
    }

    public function isRejected(): bool
    {
        return $this->status === 'rejected' || $this->currentRejectionCount() > 0;
    }

    public function isPending(): bool
    {
        return $this->status === 'pending' && ! $this->isExpired();
    }

    public function isExpired(): bool
    {
        return $this->status === 'expired' ||
            ($this->status === 'pending' && $this->expires_at?->isPast());
    }

    public function canBeApprovedBy(User $admin): bool
    {
        // Cannot approve own request
        if ($this->requested_by === $admin->id) {
            return false;
        }

        // Must be pending
        if (! $this->isPending()) {
            return false;
        }

        // Cannot have already voted
        return ! $this->approvals()
            ->where('admin_id', $admin->id)
            ->exists();
    }

    public function getRemainingApprovalsNeeded(): int
    {
        return max(0, $this->required_approvals - $this->currentApprovalCount());
    }

    public function getTypeLabel(): string
    {
        return match ($this->type) {
            'role_title_change' => 'Role Title Change',
            'role_permission_change' => 'Role Permission Change',
            'role_create' => 'New Role Creation',
            'role_delete' => 'Role Deletion',
            default => ucfirst(str_replace('_', ' ', $this->type)),
        };
    }

    public function getStatusLabel(): string
    {
        if ($this->isExpired() && $this->status === 'pending') {
            return 'Expired';
        }

        return ucfirst($this->status);
    }

    public function getStatusColor(): string
    {
        if ($this->isExpired()) {
            return 'gray';
        }

        return match ($this->status) {
            'pending' => 'yellow',
            'approved' => 'green',
            'rejected' => 'red',
            'expired' => 'gray',
            default => 'gray',
        };
    }
}
