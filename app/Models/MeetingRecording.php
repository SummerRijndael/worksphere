<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;

class MeetingRecording extends Model
{
    use HasUuids;

    protected $fillable = [
        'meeting_id',
        'cf_meeting_id',
        'cf_recording_id',
        'started_by',
        'status',
        'download_url',
        'duration_seconds',
        'cf_metadata',
    ];

    protected function casts(): array
    {
        return [
            'cf_metadata' => 'array',
        ];
    }

    // ─── Relationships ────────────────────────────────────────────────────────

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function startedBy(): BelongsTo
    {
        return $this->belongsTo(User::class, 'started_by');
    }

    // ─── Helpers ──────────────────────────────────────────────────────────────

    public function isActive(): bool
    {
        return in_array($this->status, ['pending', 'recording']);
    }
}
