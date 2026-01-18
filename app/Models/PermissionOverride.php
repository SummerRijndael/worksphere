<?php

namespace App\Models;

use Carbon\Carbon;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class PermissionOverride extends Model
{
    protected $fillable = [
        'user_id',
        'permission',
        'type',
        'scope',
        'team_id',
        'is_temporary',
        'expires_at',
        'expiry_behavior',
        'grace_period_days',
        'reason',
        'granted_by',
        'approved_at',
        'revoked_at',
        'revoked_by',
        'revoke_reason',
    ];

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (PermissionOverride $override): void {
            if (empty($override->public_id)) {
                $override->public_id = (string) Str::uuid();
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
            'is_temporary' => 'boolean',
            'expires_at' => 'datetime',
            'approved_at' => 'datetime',
            'revoked_at' => 'datetime',
            'grace_period_days' => 'integer',
        ];
    }

    // Relationships

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function team(): BelongsTo
    {
        return $this->belongsTo(Team::class);
    }

    public function grantedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'granted_by');
    }

    public function revokedByUser(): BelongsTo
    {
        return $this->belongsTo(User::class, 'revoked_by');
    }

    // Scopes

    /**
     * @param  Builder<PermissionOverride>  $query
     * @return Builder<PermissionOverride>
     */
    public function scopeActive(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where(function (Builder $q) {
                $q->where('is_temporary', false)
                    ->orWhere(function (Builder $q2) {
                        // Not expired yet
                        $q2->whereNull('expires_at')
                            ->orWhere('expires_at', '>', now());
                    });
            });
    }

    /**
     * @param  Builder<PermissionOverride>  $query
     * @return Builder<PermissionOverride>
     */
    public function scopeExpired(Builder $query): Builder
    {
        return $query->whereNull('revoked_at')
            ->where('is_temporary', true)
            ->whereNotNull('expires_at')
            ->where('expires_at', '<=', now());
    }

    /**
     * @param  Builder<PermissionOverride>  $query
     * @return Builder<PermissionOverride>
     */
    public function scopeTemporary(Builder $query): Builder
    {
        return $query->where('is_temporary', true);
    }

    /**
     * @param  Builder<PermissionOverride>  $query
     * @return Builder<PermissionOverride>
     */
    public function scopeGrants(Builder $query): Builder
    {
        return $query->where('type', 'grant');
    }

    /**
     * @param  Builder<PermissionOverride>  $query
     * @return Builder<PermissionOverride>
     */
    public function scopeBlocks(Builder $query): Builder
    {
        return $query->where('type', 'block');
    }

    /**
     * @param  Builder<PermissionOverride>  $query
     * @return Builder<PermissionOverride>
     */
    public function scopeForUser(Builder $query, User|int $user): Builder
    {
        $userId = $user instanceof User ? $user->id : $user;

        return $query->where('user_id', $userId);
    }

    /**
     * @param  Builder<PermissionOverride>  $query
     * @return Builder<PermissionOverride>
     */
    public function scopeForTeam(Builder $query, Team|int|null $team): Builder
    {
        if ($team === null) {
            return $query->whereNull('team_id');
        }

        $teamId = $team instanceof Team ? $team->id : $team;

        return $query->where('team_id', $teamId);
    }

    /**
     * @param  Builder<PermissionOverride>  $query
     * @return Builder<PermissionOverride>
     */
    public function scopeGlobal(Builder $query): Builder
    {
        return $query->where('scope', 'global');
    }

    /**
     * @param  Builder<PermissionOverride>  $query
     * @return Builder<PermissionOverride>
     */
    public function scopeTeamScoped(Builder $query): Builder
    {
        return $query->where('scope', 'team');
    }

    /**
     * @param  Builder<PermissionOverride>  $query
     * @return Builder<PermissionOverride>
     */
    public function scopeForPermission(Builder $query, string $permission): Builder
    {
        return $query->where('permission', $permission);
    }

    /**
     * Scope for overrides expiring soon.
     *
     * @param  Builder<PermissionOverride>  $query
     * @return Builder<PermissionOverride>
     */
    public function scopeExpiringSoon(Builder $query, int $daysAhead = 7): Builder
    {
        return $query->active()
            ->temporary()
            ->whereNotNull('expires_at')
            ->where('expires_at', '>', now())
            ->where('expires_at', '<=', now()->addDays($daysAhead));
    }

    // Helper methods

    public function isActive(): bool
    {
        if ($this->revoked_at !== null) {
            return false;
        }

        if (! $this->is_temporary) {
            return true;
        }

        return ! $this->isExpired() || $this->inGracePeriod();
    }

    public function isExpired(): bool
    {
        if (! $this->is_temporary || $this->expires_at === null) {
            return false;
        }

        return $this->expires_at->isPast();
    }

    public function inGracePeriod(): bool
    {
        if ($this->expiry_behavior !== 'grace_period' || ! $this->isExpired()) {
            return false;
        }

        if ($this->grace_period_days === null) {
            return false;
        }

        $graceEndDate = $this->expires_at->addDays($this->grace_period_days);

        return $graceEndDate->isFuture();
    }

    public function daysUntilExpiry(): ?int
    {
        if (! $this->is_temporary || $this->expires_at === null) {
            return null;
        }

        if ($this->isExpired()) {
            if ($this->inGracePeriod()) {
                $graceEndDate = $this->expires_at->addDays($this->grace_period_days);

                return (int) now()->diffInDays($graceEndDate, false);
            }

            return 0;
        }

        return (int) now()->diffInDays($this->expires_at, false);
    }

    public function getEffectiveExpiryDate(): ?Carbon
    {
        if (! $this->is_temporary || $this->expires_at === null) {
            return null;
        }

        if ($this->expiry_behavior === 'grace_period' && $this->grace_period_days) {
            return $this->expires_at->copy()->addDays($this->grace_period_days);
        }

        return $this->expires_at;
    }

    public function isGrant(): bool
    {
        return $this->type === 'grant';
    }

    public function isBlock(): bool
    {
        return $this->type === 'block';
    }

    public function isGlobal(): bool
    {
        return $this->scope === 'global';
    }

    public function isTeamScoped(): bool
    {
        return $this->scope === 'team';
    }
}
