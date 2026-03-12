<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\HasMany;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class MeetingMessage extends Model implements HasMedia
{
    use \Illuminate\Database\Eloquent\Concerns\HasUlids;
    use InteractsWithMedia;

    public const MEDIA_COLLECTION = 'meeting_chat_attachments';

    protected $fillable = [
        'public_id',
        'meeting_id',
        'participant_public_id',
        'body',
        'temp_id',
        'metadata',
        'reply_to_message_id',
        'thread_root_message_id',
        'is_pinned',
        'pinned_at',
        'pinned_by_participant_public_id',
    ];

    protected $casts = [
        'metadata' => 'array',
        'is_pinned' => 'boolean',
        'pinned_at' => 'datetime',
    ];

    /**
     * Get the columns that should receive a unique identifier.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    public function meeting(): BelongsTo
    {
        return $this->belongsTo(Meeting::class);
    }

    public function participant(): BelongsTo
    {
        return $this->belongsTo(MeetingParticipant::class, 'participant_public_id', 'public_id');
    }

    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(self::class, 'reply_to_message_id');
    }

    public function threadRoot(): BelongsTo
    {
        return $this->belongsTo(self::class, 'thread_root_message_id');
    }

    public function replies(): HasMany
    {
        return $this->hasMany(self::class, 'thread_root_message_id');
    }

    public function pinnedByParticipant(): BelongsTo
    {
        return $this->belongsTo(MeetingParticipant::class, 'pinned_by_participant_public_id', 'public_id');
    }

    public function registerMediaCollections(): void
    {
        $this->addMediaCollection(self::MEDIA_COLLECTION)
            ->useDisk('private')
            ->acceptsMimeTypes([
                'image/jpeg',
                'image/jpg',
                'image/png',
                'image/webp',
                'image/gif',
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'text/plain',
                'application/octet-stream',
            ]);
    }

    public function registerMediaConversions(?Media $media = null): void
    {
        $this->addMediaConversion('thumb')
            ->fit(Fit::Crop, 300, 300)
            ->format('webp')
            ->quality(80)
            ->optimize()
            ->nonQueued()
            ->performOnCollections(self::MEDIA_COLLECTION);

        $this->addMediaConversion('web')
            ->fit(Fit::Max, 1920, 1920)
            ->format('webp')
            ->quality(85)
            ->optimize()
            ->withResponsiveImages()
            ->performOnCollections(self::MEDIA_COLLECTION);
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    public function toAttachmentPayload(): array
    {
        $ttlMinutes = max(1, (int) config('chat_pipeline.meeting_chat_media_url_ttl_minutes', 30));
        $expiresAt = now()->addMinutes($ttlMinutes);

        $mediaItems = $this->relationLoaded('media')
            ? $this->media->where('collection_name', self::MEDIA_COLLECTION)->values()
            : $this->getMedia(self::MEDIA_COLLECTION);

        return $mediaItems->map(function (Media $media) use ($expiresAt) {
            $isImage = str_starts_with((string) $media->mime_type, 'image/');
            $viewUrl = URL::temporarySignedRoute('meeting.chat.media.view', $expiresAt, [
                'mediaId' => $media->id,
            ]);
            $downloadUrl = URL::temporarySignedRoute('meeting.chat.media.download', $expiresAt, [
                'mediaId' => $media->id,
            ]);

            $optimizedUrl = $isImage && $media->hasGeneratedConversion('web')
                ? URL::temporarySignedRoute('meeting.chat.media.conversion', $expiresAt, [
                    'mediaId' => $media->id,
                    'conversion' => 'web',
                ])
                : $viewUrl;

            return [
                'id' => $media->id,
                'name' => Str::limit($media->getCustomProperty('original_filename') ?? $media->file_name, 80),
                'size' => (int) $media->size,
                'mime_type' => $media->mime_type,
                'is_image' => $isImage,
                'url' => $optimizedUrl,
                'download_url' => $downloadUrl,
                'thumb_url' => $isImage && $media->hasGeneratedConversion('thumb')
                    ? URL::temporarySignedRoute('meeting.chat.media.conversion', $expiresAt, [
                        'mediaId' => $media->id,
                        'conversion' => 'thumb',
                    ])
                    : null,
            ];
        })->values()->all();
    }
}
