<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;

class Event extends Model
{
    protected $fillable = [
        'user_id',
        'public_id',
        'title',
        'description',
        'start_time',
        'end_time',
        'location',
        'is_all_day',
        'reminder_minutes_before',
        'reminder_minutes_before',
        'notification_sent_at',
        'external_attendees',
        'google_event_id',
        'last_synced_at',
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

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'is_all_day' => 'boolean',
        'notification_sent_at' => 'datetime',
        'external_attendees' => 'array',
    ];

    public function organizer(): \Illuminate\Database\Eloquent\Relations\BelongsTo
    {
        return $this->belongsTo(User::class, 'user_id');
    }

    public function attendees(): \Illuminate\Database\Eloquent\Relations\BelongsToMany
    {
        return $this->belongsToMany(User::class, 'event_attendees')
            ->using(EventAttendee::class)
            ->withPivot('status')
            ->withTimestamps();
    }

    public function scopeBetween($query, $start, $end)
    {
        return $query->where('start_time', '>=', $start)
            ->where('start_time', '<=', $end);
    }
}
