<?php

namespace App\Models\Chat;

use App\Models\User;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Laravel\Scout\Searchable;
use Spatie\Image\Enums\Fit;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ChatMessage extends Model implements HasMedia
{
    use HasFactory;
    use \Illuminate\Database\Eloquent\Concerns\HasUlids;
    use InteractsWithMedia;
    use Searchable;

    /**
     * The table associated with the model.
     */
    protected $table = 'chat_messages';

    /**
     * Get the columns that should receive a unique identifier.
     *
     * @return array<int, string>
     */
    public function uniqueIds(): array
    {
        return ['public_id'];
    }

    /**
     * The attributes that are mass assignable.
     *
     * @var list<string>
     */
    protected $fillable = [
        'public_id',
        'chat_id',
        'user_id',
        'content',
        'type', // 'user' or 'system'
        'reply_to_message_id',
        'metadata',
    ];

    /**
     * The attributes that should be cast.
     *
     * @return array<string, string>
     */
    protected function casts(): array
    {
        return [
            'metadata' => 'array',
        ];
    }

    /**
     * The relationships that should always be loaded.
     *
     * @var list<string>
     */
    protected $with = ['user'];

    /**
     * Check if this is a system message.
     */
    public function isSystemMessage(): bool
    {
        return $this->type === 'system';
    }

    /**
     * The relationships that should have their timestamps updated.
     *
     * @var list<string>
     */
    protected $touches = ['chat'];

    /**
     * Bootstrap the model.
     */
    protected static function booted(): void
    {
        static::creating(function (ChatMessage $message) {
            // Ensure content is never null (can be empty for attachment-only messages)
            if ($message->content === null) {
                $message->content = '';
            }

            // Auto-assign user if not set (unless system message)
            if (! $message->user_id && $message->type !== 'system') {
                $message->user_id = auth()->id()
                    ?? $message->chat?->participants()->first()?->id
                    ?? User::query()->value('id');
            }
        });
    }

    /**
     * Get the chat this message belongs to.
     *
     * @return BelongsTo<Chat, ChatMessage>
     */
    public function chat(): BelongsTo
    {
        return $this->belongsTo(Chat::class);
    }

    /**
     * Get the user who sent this message.
     *
     * @return BelongsTo<User, ChatMessage>
     */
    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    /**
     * Get the message this is a reply to.
     *
     * @return BelongsTo<ChatMessage, ChatMessage>
     */
    public function replyTo(): BelongsTo
    {
        return $this->belongsTo(ChatMessage::class, 'reply_to_message_id');
    }

    /**
     * Register the media collections for chat attachments.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('chat_attachments')
            ->useDisk('private')
            ->acceptsMimeTypes([
                // Images
                'image/jpeg',
                'image/jpg',
                'image/png',
                'image/webp',
                'image/gif',
                // Audio clips
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
                // Some browsers produce audio-only recordings with video/* container MIME
                'video/webm',
                'video/ogg',
                'video/mp4',
                // Documents
                'application/pdf',
                'application/msword',
                'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
                'application/vnd.ms-excel',
                'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
                'text/plain',
                // Other
                'application/octet-stream',
            ]);
    }

    /**
     * Register the media conversions for optimized display.
     */
    public function registerMediaConversions(?Media $media = null): void
    {
        // Thumbnail for fast loading in message lists
        $this->addMediaConversion('thumb')
            ->fit(Fit::Crop, 300, 300)
            ->format('webp')
            ->quality(80)
            ->optimize()
            ->nonQueued()
            ->performOnCollections('chat_attachments');

        // Web-optimized view (high quality, compressed, EXIF stripped)
        $this->addMediaConversion('web')
            ->fit(Fit::Max, 1920, 1920)
            ->format('webp')
            ->quality(85)
            ->optimize()
            ->withResponsiveImages()
            ->performOnCollections('chat_attachments');

        // Video poster thumbnail for chat previews.
        // Keep this separate from image `thumb` to avoid changing existing image behavior.
        if ($media && str_starts_with((string) $media->mime_type, 'video/') && ! (bool) $media->getCustomProperty('is_voice_clip', false)) {
            $this->addMediaConversion('video_thumb')
                ->extractVideoFrameAtSecond(1)
                ->fit(Fit::Crop, 300, 300)
                ->format('webp')
                ->quality(80)
                ->optimize()
                ->performOnCollections('chat_attachments');
        }
    }

    /**
     * Check if this message has any attachments.
     */
    public function hasAttachments(): bool
    {
        return $this->getMedia('chat_attachments')->isNotEmpty();
    }

    /**
     * Check if this is a reply to another message.
     */
    public function isReply(): bool
    {
        return $this->reply_to_message_id !== null;
    }

    /**
     * Check if message is from a specific user.
     */
    public function isFrom(User $user): bool
    {
        return $this->user_id === $user->id;
    }

    /**
     * Get the indexable data array for the model.
     *
     * @return array<string, mixed>
     */
    public function toSearchableArray(): array
    {
        return [
            'id' => $this->id,
            'chat_id' => $this->chat_id,
            'content' => $this->content,
            'user_id' => $this->user_id,
            'created_at' => $this->created_at?->timestamp,
        ];
    }

    /**
     * Determine if the model should be searchable.
     */
    /**
     * Scope a query to only include messages visible to a specific user.
     *
     * @param  \Illuminate\Database\Eloquent\Builder<static>  $query
     */
    public function scopeVisibleTo($query, User $user): void
    {
        $viewerPublicId = strtolower(trim((string) $user->public_id));
        if ($viewerPublicId === '') {
            return;
        }

        $query->where(function ($visibility) use ($viewerPublicId) {
            $visibility->whereNull('metadata')
                ->orWhereNull('metadata->hidden_for_user_public_ids')
                ->orWhereJsonDoesntContain('metadata->hidden_for_user_public_ids', $viewerPublicId);
        });
    }
}
