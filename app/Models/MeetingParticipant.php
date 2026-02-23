<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class MeetingParticipant extends Model
{
    protected $fillable = [
        'meeting_id',
        'user_id',
        'public_id',
        'role',
        'status',
        'metadata',
    ];

    protected $casts = [
        'metadata' => 'array',
    ];

    protected static function boot()
    {
        parent::boot();

        static::creating(function ($model) {
            if (empty($model->public_id)) {
                $model->public_id = (string) \Illuminate\Support\Str::ulid();
            }
        });
    }

    public function getRouteKeyName()
    {
        return 'public_id';
    }

    public function meeting(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function user(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
