<?php

namespace App\Models;

use Akaunting\Firewall\Models\Ip as BaseIp;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class FirewallIp extends BaseIp
{
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
}
