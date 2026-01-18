<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class RoleChangeApproval extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'request_id',
        'admin_id',
        'action',
        'password_verified_at',
        'comment',
    ];

    protected function casts(): array
    {
        return [
            'password_verified_at' => 'datetime',
            'created_at' => 'datetime',
        ];
    }

    // Relationships

    public function request(): BelongsTo
    {
        return $this->belongsTo(RoleChangeRequest::class, 'request_id');
    }

    public function admin(): BelongsTo
    {
        return $this->belongsTo(User::class, 'admin_id');
    }

    // Scopes

    /**
     * @param  Builder<RoleChangeApproval>  $query
     * @return Builder<RoleChangeApproval>
     */
    public function scopeApprovals(Builder $query): Builder
    {
        return $query->where('action', 'approve');
    }

    /**
     * @param  Builder<RoleChangeApproval>  $query
     * @return Builder<RoleChangeApproval>
     */
    public function scopeRejections(Builder $query): Builder
    {
        return $query->where('action', 'reject');
    }

    /**
     * @param  Builder<RoleChangeApproval>  $query
     * @return Builder<RoleChangeApproval>
     */
    public function scopeByAdmin(Builder $query, User|int $admin): Builder
    {
        $adminId = $admin instanceof User ? $admin->id : $admin;

        return $query->where('admin_id', $adminId);
    }

    // Helper methods

    public function isApproval(): bool
    {
        return $this->action === 'approve';
    }

    public function isRejection(): bool
    {
        return $this->action === 'reject';
    }

    public function getActionLabel(): string
    {
        return $this->isApproval() ? 'Approved' : 'Rejected';
    }

    public function getActionColor(): string
    {
        return $this->isApproval() ? 'green' : 'red';
    }
}
