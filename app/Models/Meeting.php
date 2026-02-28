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

    public function event(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(Event::class);
    }

    public function polls(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MeetingPoll::class);
    }

    public function breakoutSessions(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(BreakoutSession::class);
    }

    public function activeBreakoutSession(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(BreakoutSession::class)
            ->where('status', 'active')
            ->where(function ($query) {
                // Return if NO duration is set (infinite) OR if duration + 1 min grace has not passed
                $query->whereNull('duration_minutes')
                    ->orWhere(function ($q) {
                        if (config('database.default') === 'sqlite') {
                            $q->whereRaw("datetime(started_at, '+' || (duration_minutes + 1) || ' minutes') > ?", [now()]);
                        } else {
                            $q->whereRaw('DATE_ADD(started_at, INTERVAL duration_minutes + 1 MINUTE) > ?', [now()]);
                        }
                    });
            });
    }

    /**
     * Check whether a given MeetingParticipant is the host of this meeting.
     */
    public function isHost(MeetingParticipant $participant): bool
    {
        return $participant->user_id !== null
            && $participant->user_id == $this->user_id;
    }
}
