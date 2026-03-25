<?php

namespace App\Models;

use App\Enums\DialerCallStatus;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Str;

class DialerCall extends Model
{
    use HasFactory;

    protected $fillable = [
        'public_id',
        'user_id',
        'provider',
        'provider_call_id',
        'direction',
        'from_number',
        'to_number',
        'status',
        'contact_name',
        'notes',
        'acd_context',
        'provider_payload',
        'requested_at',
        'started_at',
        'ended_at',
        'duration_seconds',
    ];

    protected $hidden = [
        'id',
        'user_id',
    ];

    protected function casts(): array
    {
        return [
            'status' => DialerCallStatus::class,
            'acd_context' => 'array',
            'provider_payload' => 'array',
            'requested_at' => 'datetime',
            'started_at' => 'datetime',
            'ended_at' => 'datetime',
            'duration_seconds' => 'integer',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (DialerCall $call): void {
            if (empty($call->public_id)) {
                $call->public_id = (string) Str::ulid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }
}
