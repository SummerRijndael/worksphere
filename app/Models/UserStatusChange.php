<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class UserStatusChange extends Model
{
    public $timestamps = false;

    protected $fillable = [
        'user_id',
        'from_status',
        'to_status',
        'reason',
        'changed_by',
        'password_verified_at',
        'metadata',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (UserStatusChange $change): void {
            if (empty($change->public_id)) {
                $change->public_id = (string) Str::uuid();
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
            'password_verified_at' => 'datetime',
            'metadata' => 'array',
            'created_at' => 'datetime',
        ];
    }

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function changedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'changed_by');
    }

    // Scopes

    /**
     * @param  Builder<UserStatusChange>  $query
     * @return Builder<UserStatusChange>
     */
    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $query->where('user_id', $userId);
    }

    /**
     * @param  Builder<UserStatusChange>  $query
     * @return Builder<UserStatusChange>
     */
    public function scopeToStatus(Builder $query, string $status): Builder
    {
        return $query->where('to_status', $status);
    }

    /**
     * @param  Builder<UserStatusChange>  $query
     * @return Builder<UserStatusChange>
     */
    public function scopeFromStatus(Builder $query, string $status): Builder
    {
        return $query->where('from_status', $status);
    }

    /**
     * @param  Builder<UserStatusChange>  $query
     * @return Builder<UserStatusChange>
     */
    public function scopeRecent(Builder $query, int $days = 30): Builder
    {
        return $query->where('created_at', '>=', now()->subDays($days));
    }

    /**
     * @param  Builder<UserStatusChange>  $query
     * @return Builder<UserStatusChange>
     */
    public function scopeByAdmin(Builder $query, User|int $admin): Builder
    {
        $adminId = $admin instanceof User ? $admin->id : $admin;

        return $query->where('changed_by', $adminId);
    }

    // Helper methods

    public function getFromStatusLabel(): string
    {
        $statuses = config('roles.statuses', []);

        return $statuses[$this->from_status]['label'] ?? ucfirst($this->from_status);
    }

    public function getToStatusLabel(): string
    {
        $statuses = config('roles.statuses', []);

        return $statuses[$this->to_status]['label'] ?? ucfirst($this->to_status);
    }

    public function getFromStatusColor(): string
    {
        $statuses = config('roles.statuses', []);

        return $statuses[$this->from_status]['color'] ?? 'gray';
    }

    public function getToStatusColor(): string
    {
        $statuses = config('roles.statuses', []);

        return $statuses[$this->to_status]['color'] ?? 'gray';
    }

    public function isActivation(): bool
    {
        return $this->to_status === 'active' && $this->from_status !== 'active';
    }

    public function isDeactivation(): bool
    {
        return $this->from_status === 'active' && $this->to_status !== 'active';
    }

    public function isSuspension(): bool
    {
        return $this->to_status === 'suspended';
    }

    public function isBlock(): bool
    {
        return $this->to_status === 'blocked';
    }
}
