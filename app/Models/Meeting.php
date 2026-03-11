<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Support\Facades\Crypt;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Str;

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
        // RealtimeKit meeting ID (set when a PRO recording session is initialised)
        'cf_meeting_id',
        'actual_start_time',
        'actual_end_time',
        'unique_participant_count',
        'peak_participant_count',
    ];

    protected $casts = [
        'start_time' => 'datetime',
        'end_time' => 'datetime',
        'actual_start_time' => 'datetime',
        'actual_end_time' => 'datetime',
        'settings' => 'array',
        'unique_participant_count' => 'integer',
        'peak_participant_count' => 'integer',
    ];

    protected $hidden = [
        'password',
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

    public static function looksLikePasswordHash(?string $value): bool
    {
        if (! is_string($value) || $value === '') {
            return false;
        }

        return Str::startsWith($value, ['$2y$', '$2b$', '$argon2i$', '$argon2id$']);
    }

    public static function looksLikeEncryptedPassword(?string $value): bool
    {
        if (! is_string($value) || $value === '') {
            return false;
        }

        try {
            Crypt::decryptString($value);

            return true;
        } catch (\Throwable) {
            return false;
        }
    }

    public function setPasswordAttribute($value): void
    {
        if (! is_string($value) || trim($value) === '') {
            $this->attributes['password'] = null;

            return;
        }

        if (static::looksLikePasswordHash($value) || static::looksLikeEncryptedPassword($value)) {
            $this->attributes['password'] = $value;

            return;
        }

        $this->attributes['password'] = Crypt::encryptString($value);
    }

    public function revealPassword(): ?string
    {
        $storedPassword = $this->attributes['password'] ?? null;

        if (! is_string($storedPassword) || $storedPassword === '') {
            return null;
        }

        if (static::looksLikeEncryptedPassword($storedPassword)) {
            try {
                return Crypt::decryptString($storedPassword);
            } catch (\Throwable) {
                return null;
            }
        }

        if (static::looksLikePasswordHash($storedPassword)) {
            return null;
        }

        return $storedPassword;
    }

    public function passwordMatches(?string $plainPassword): bool
    {
        $storedPassword = $this->attributes['password'] ?? null;

        if (! is_string($storedPassword) || $storedPassword === '' || ! is_string($plainPassword) || $plainPassword === '') {
            return false;
        }

        if (static::looksLikeEncryptedPassword($storedPassword)) {
            try {
                return hash_equals(Crypt::decryptString($storedPassword), $plainPassword);
            } catch (\Throwable) {
                return false;
            }
        }

        if (static::looksLikePasswordHash($storedPassword)) {
            return Hash::check($plainPassword, $storedPassword);
        }

        return hash_equals($storedPassword, $plainPassword);
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
                    ->orWhereRaw('DATE_ADD(started_at, INTERVAL duration_minutes + 1 MINUTE) > ?', [now()]);
            });
    }

    public function recordings(): \Illuminate\Database\Eloquent\Relations\HasMany
    {
        return $this->hasMany(MeetingRecording::class);
    }

    public function activeRecording(): \Illuminate\Database\Eloquent\Relations\HasOne
    {
        return $this->hasOne(MeetingRecording::class)
            ->whereIn('status', ['pending', 'recording'])
            ->latestOfMany();
    }

    /**
     * Whether this meeting has met the criteria for recording (PRO dev toggle).
     * Replace with billing check when subscriptions are live.
     */
    public function recordingEnabled(): bool
    {
        return config('services.cloudflare_realtime.recording_enabled', false);
    }

    /**
     * Check whether a given MeetingParticipant is the host of this meeting.
     */
    public function isHost(MeetingParticipant $participant): bool
    {
        return $participant->user_id !== null
            && $participant->user_id === $this->user_id;
    }
}
