<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Str;

class BreakoutSession extends Model
{
    protected $fillable = [
        'public_id',
        'meeting_id',
        'status',
        'rooms_config',
        'duration_minutes',
        'started_at',
        'ended_at',
    ];

    protected $casts = [
        'rooms_config' => 'array',
        'started_at' => 'datetime',
        'ended_at' => 'datetime',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->public_id)) {
                $model->public_id = (string) Str::ulid();
            }
        });
    }

    public function meeting()
    {
        return $this->belongsTo(Meeting::class);
    }
}
