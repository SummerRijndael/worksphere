<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Database\Eloquent\SoftDeletes;

class FirewallIp extends Model
{
    use SoftDeletes;

    protected $table = 'firewall_ips';

    protected $fillable = [
        'ip',
        'log_id',
        'blocked',
        'reason',
        'user_id',
        'expires_at',
        'label',
    ];

    protected $casts = [
        'blocked' => 'boolean',
        'expires_at' => 'datetime',
    ];

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    // Alias for backward compatibility if needed, or consistent naming
    public function blockedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function addedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    // Helper to check if IP is expired
    public function isExpired(): bool
    {
        return $this->expires_at && $this->expires_at->isPast();
    }

    /**
     * Compatibility with Akaunting\Firewall
     */
    public function log(): BelongsTo
    {
        return $this->belongsTo('Akaunting\\Firewall\\Models\\Log');
    }

    public function logs(): HasMany
    {
        return $this->hasMany('Akaunting\\Firewall\\Models\\Log', 'ip', 'ip');
    }

    public function scopeBlocked($query, $ip = null)
    {
        $q = $query->where('blocked', 1);

        if ($ip) {
            $q = $query->where('ip', $ip);
        }

        return $q;
    }
}
