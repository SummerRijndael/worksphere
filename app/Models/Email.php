<?php

namespace App\Models;

use App\Enums\EmailFolderType;
use Illuminate\Database\Eloquent\Factories\HasFactory;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Database\Eloquent\Relations\BelongsTo;
use Illuminate\Database\Eloquent\Relations\BelongsToMany;
use Illuminate\Database\Eloquent\SoftDeletes;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\HasMedia;
use Spatie\MediaLibrary\InteractsWithMedia;

class Email extends Model implements HasMedia
{
    use HasFactory;
    use InteractsWithMedia;
    use SoftDeletes;

    protected $fillable = [
        'public_id',
        'email_account_id',
        'user_id',
        'message_id',
        'thread_id',
        'folder',
        'from_email',
        'from_name',
        'to',
        'cc',
        'bcc',
        'subject',
        'preview',
        'body_html',
        'body_plain',
        'body_raw',
        'headers',
        'is_read',
        'is_starred',
        'is_draft',
        'has_attachments',
        'size_bytes',
        'imap_uid',
        'provider_id',
        'attachment_placeholders',
        'sent_at',
        'scheduled_at',
        'received_at',
        'sanitized_at',
        'is_important',
        'is_pinned',
    ];

    protected $appends = [
        'attachments',
        'date',
    ];

    protected function casts(): array
    {
        return [
            'to' => 'array',
            'cc' => 'array',
            'bcc' => 'array',
            'headers' => 'array',
            'is_read' => 'boolean',
            'is_starred' => 'boolean',
            'is_important' => 'boolean',
            'is_draft' => 'boolean',
            'has_attachments' => 'boolean',
            'imap_uid' => 'integer',
            'provider_id' => 'string',
            'attachment_placeholders' => 'array',
            'sent_at' => 'datetime',
            'scheduled_at' => 'datetime',
            'received_at' => 'datetime',
            'sanitized_at' => 'datetime',
            'is_pinned' => 'boolean',
        ];
    }

    // ==================
    // Boot
    // ==================

    protected static function booted(): void
    {
        static::creating(function (Email $email) {
            if (empty($email->public_id)) {
                $email->public_id = (string) Str::uuid();
            }
        });
    }

    // ==================
    // Relationships
    // ==================

    public function emailAccount(): BelongsTo
    {
        return $this->belongsTo(EmailAccount::class);
    }

    public function user(): BelongsTo
    {
        return $this->belongsTo(User::class);
    }

    public function labels(): BelongsToMany
    {
        return $this->belongsToMany(EmailLabel::class, 'email_email_label');
    }

    // ==================
    // Scopes
    // ==================

    public function scopeForUser($query, int $userId)
    {
        return $query->where('user_id', $userId);
    }

    public function scopeForAccount($query, int $accountId)
    {
        return $query->where('email_account_id', $accountId);
    }

    public function scopeInFolder($query, string $folder)
    {
        return $query->where('folder', $folder);
    }

    public function scopeImportant($query)
    {
        return $query->where('is_important', true);
    }

    public function scopeInbox($query)
    {
        return $query->where('folder', EmailFolderType::Inbox->value);
    }

    public function scopeSent($query)
    {
        return $query->where('folder', EmailFolderType::Sent->value);
    }

    public function scopeDrafts($query)
    {
        return $query->where('folder', EmailFolderType::Drafts->value);
    }

    public function scopeTrash($query)
    {
        return $query->where('folder', EmailFolderType::Trash->value);
    }

    public function scopeStarred($query)
    {
        return $query->where('is_starred', true);
    }

    public function scopePinned($query)
    {
        return $query->where('is_pinned', true);
    }

    public function scopeUnread($query)
    {
        return $query->where('is_read', false);
    }

    public function scopeRead($query)
    {
        return $query->where('is_read', true);
    }

    public function scopeNotDraft($query)
    {
        return $query->where('is_draft', false);
    }

    public function scopeSearch($query, string $search)
    {
        return $query->where(function ($q) use ($search) {
            $q->where('subject', 'like', "%{$search}%")
                ->orWhere('from_email', 'like', "%{$search}%")
                ->orWhere('from_name', 'like', "%{$search}%")
                ->orWhere('preview', 'like', "%{$search}%");
        });
    }

    // ==================
    // Helpers
    // ==================

    /**
     * Mark email as read.
     */
    public function markAsRead(): self
    {
        $this->update(['is_read' => true]);

        return $this;
    }

    /**
     * Mark email as unread.
     */
    public function markAsUnread(): self
    {
        $this->update(['is_read' => false]);

        return $this;
    }

    /**
     * Toggle starred status.
     */
    public function toggleStar(): self
    {
        $this->update(['is_starred' => ! $this->is_starred]);

        return $this;
    }

    /**
     * Toggle important status.
     */
    public function toggleImportant(): self
    {
        $this->update(['is_important' => ! $this->is_important]);

        return $this;
    }

    /**
     * Toggle pinned status.
     */
    public function togglePin(): self
    {
        $this->update(['is_pinned' => ! $this->is_pinned]);

        return $this;
    }

    /**
     * Move to folder.
     */
    public function moveToFolder(string $folder): self
    {
        $this->update(['folder' => $folder]);

        return $this;
    }

    /**
     * Hard-prune the email content (bodies and files).
     */
    public function prune(bool $forceDelete = false): void
    {
        // Delete all media associated with this email
        $this->clearMediaCollection('attachments');

        // Clear body data
        $this->update([
            'body_html' => null,
            'body_plain' => null,
            'body_raw' => null,
            'has_attachments' => false,
            'attachment_placeholders' => null,
        ]);

        if ($forceDelete) {
            $this->forceDelete();
        }
    }

    /**
     * Move to trash.
     */
    public function moveToTrash(): self
    {
        return $this->moveToFolder(EmailFolderType::Trash->value);
    }

    /**
     * Get sender display name.
     */
    public function getSenderDisplayAttribute(): string
    {
        return $this->from_name ?: $this->from_email;
    }

    /**
     * Get recipients as formatted string.
     */
    public function getRecipientsDisplayAttribute(): string
    {
        $to = collect($this->to ?? [])
            ->map(fn ($r) => $r['name'] ?? $r['email'])
            ->implode(', ');

        return $to ?: 'Unknown';
    }

    /**
     * Get date attribute (accessor).
     */
    public function getDateAttribute(): string
    {
        return $this->received_at
            ? $this->received_at->toIso8601String()
            : ($this->created_at ? $this->created_at->toIso8601String() : now()->toIso8601String());
    }

    /**
     * Register media collections.
     */
    public function registerMediaCollections(): void
    {
        $this->addMediaCollection('attachments')
            ->useDisk('private');
    }

    /**
     * Get attachments from media library and placeholders.
     */
    public function getAttachmentsAttribute(): array
    {
        $attachments = $this->getMedia('attachments')->map(function ($media) {
            return [
                'id' => (string) $media->id,
                'name' => $media->file_name,
                'size' => $media->human_readable_size,
                'type' => $media->mime_type,
                'url' => route('api.media.show', ['media' => $media->id]),
                'content_id' => $media->getCustomProperty('content_id'),
                'is_inline' => $media->getCustomProperty('is_inline', false),
                'is_ready' => file_exists($media->getPath()),
                'is_downloaded' => true,
            ];
        });

        // Merge with placeholders (on-demand attachments)
        if (! empty($this->attachment_placeholders)) {
            foreach ($this->attachment_placeholders as $index => $placeholder) {
                $attachments->push([
                    'id' => "placeholder_{$index}",
                    'name' => $placeholder['name'] ?? 'attachment',
                    'size' => $placeholder['size'] ?? '0 B',
                    'type' => $placeholder['mime'] ?? 'application/octet-stream',
                    'url' => null,
                    'content_id' => $placeholder['content_id'] ?? null,
                    'is_downloaded' => false,
                    'placeholder_index' => $index,
                ]);
            }
        }

        return $attachments->values()->toArray();
    }
}
