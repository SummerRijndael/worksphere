<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class AnalyticsDailyStat extends Model
{
    protected $fillable = [
        'date',
        'total_views',
        'unique_visitors',
        'avg_session_duration',
        'bounce_rate',
        'device_stats',
        'browser_stats',
        'page_stats',
        'referer_stats',
    ];

    protected $casts = [
        'date' => 'date',
        'device_stats' => 'array',
        'browser_stats' => 'array',
        'page_stats' => 'array',
        'referer_stats' => 'array',
    ];
}
