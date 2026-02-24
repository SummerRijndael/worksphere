<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Meeting extends Model
{
    protected $fillable = [
        'public_id',
        'user_id',
        'title',
        'description',
        'start_time',
        'end_time',
        'status',
        'settings',
        'password',
        'is_locked',
        'app_id',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'settings' => 'array',
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

    public function host(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function participants(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MeetingParticipant::class);
    }
}
