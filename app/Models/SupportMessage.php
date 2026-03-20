<?php

namespace App\Models;

use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Support\Facades\URL;
use Illuminate\Support\Str;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class SupportMessage extends Model implements HasMedia
{
    use HasFactory;
    use InteractsWithMedia;

    public const SENDER_CUSTOMER = 'customer';

    public const SENDER_AGENT = 'agent';

    public const SENDER_BOT = 'bot';

    public const SENDER_SYSTEM = 'system';

    public const MEDIA_COLLECTION = 'support_chat_attachments';

    /**
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'conversation_id',
        'sender_type',
        'sender_user_id',
        'body',
        'is_private_note',
        'attachments',
        'metadata',
    ];

    /**
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'is_private_note' => 'boolean',
            'attachments' => 'array',
            'metadata' => 'array',
        ];
    }

    protected static function boot(): void
    {
        parent::boot();

        static::creating(function (SupportMessage $message): void {
            if (empty($message->public_id)) {
                $message->public_id = (string) Str::ulid();
            }
        });
    }

    public function getRouteKeyName(): string
    {
        return 'public_id';
    }

    /**
     * @return BelongsTo<SupportConversation, SupportMessage>
     */
    public function conversation(): BelongsTo
    {
        return $this->belongsTo(SupportConversation::class, 'conversation_id');
    }

    /**
     * @return BelongsTo<User, SupportMessage>
     */
    public function sender(): BelongsTo
    {
        return $this->belongsTo(User::class, 'sender_user_id');
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
                'audio/webm',
                'audio/ogg',
                'audio/mpeg',
                'audio/mp3',
                'audio/wav',
                'audio/x-wav',
                'audio/mp4',
                'audio/x-m4a',
                'audio/aac',
                'audio/flac',
                'video/webm',
                'video/ogg',
                'video/mp4',
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
        $ttlMinutes = max(1, (int) config('support_chat.media_url_ttl_minutes', 30));
        $expiresAt = now()->addMinutes($ttlMinutes);

        $mediaItems = $this->relationLoaded('media')
            ? $this->media->where('collection_name', self::MEDIA_COLLECTION)->values()
            : $this->getMedia(self::MEDIA_COLLECTION);

        return $mediaItems->map(function (Media $media) use ($expiresAt) {
            $isImage = str_starts_with((string) $media->mime_type, 'image/');
            $viewUrl = URL::temporarySignedRoute('media.show', $expiresAt, [
                'media' => $media->id,
            ]);
            $optimizedUrl = $isImage && $media->hasGeneratedConversion('web')
                ? URL::temporarySignedRoute('media.show', $expiresAt, [
                    'media' => $media->id,
                    'conversion' => 'web',
                ])
                : $viewUrl;

            return [
                'id' => $media->id,
                'name' => Str::limit((string) ($media->getCustomProperty('original_filename') ?? $media->file_name), 120),
                'size' => (int) $media->size,
                'mime_type' => $media->mime_type,
                'is_image' => $isImage,
                'url' => $optimizedUrl,
                'download_url' => $viewUrl,
                'thumb_url' => $isImage && $media->hasGeneratedConversion('thumb')
                    ? URL::temporarySignedRoute('media.show', $expiresAt, [
                        'media' => $media->id,
                        'conversion' => 'thumb',
                    ])
                    : null,
            ];
        })->values()->all();
    }
}
