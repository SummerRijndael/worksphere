<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\MassPrunable;
use Illuminate\Database\Eloquent\Model;

class PageView extends Model
{
    use MassPrunable;

    protected $fillable = [
        'session_id',
        'user_id',
        'ip_address',
        'url',
        'path',
        'method',
        'referer',
        'user_agent',
        'device_type',
        'browser',
        'platform',
        'created_at',
    ];

    protected $casts = [
        'created_at' => 'datetime',
    ];

    public function user()
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the prunable model query.
     */
    public function prunable(): Builder
    {
        $days = config('analytics.retention_days', 90);

        return static::where('created_at', '<=', now()->subDays($days));
    }
}
