<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Concerns\HasUuids;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MeetingRecording extends Model implements HasMedia
{
    use HasUuids;
    use InteractsWithMedia;

    public const MEDIA_COLLECTION = 'recording_files';

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

    public function registerMediaCollections(): void
    {
        $disk = config('services.cloudflare_realtime.recording_storage_disk', 'private');

        $this->addMediaCollection(self::MEDIA_COLLECTION)
            ->useDisk($disk)
            ->singleFile();
    }

    public function getDisplayNameAttribute(): string
    {
        $startedAt = $this->created_at;
        if (! $startedAt) {
            return 'Meeting Recording';
        }

        return 'Recording - '.$startedAt->timezone(config('app.timezone'))->format('M j, Y g:i A');
    }

    public function getRecordingMediaAttribute(): ?Media
    {
        return $this->getFirstMedia(self::MEDIA_COLLECTION);
    }
}
