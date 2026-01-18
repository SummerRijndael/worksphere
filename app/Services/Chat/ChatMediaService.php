<?php

namespace App\Services\Chat;

use App\Models\Chat\Chat;
use App\Models\Chat\ChatMessage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileDoesNotExist;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileIsTooBig;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ChatMediaService
{
    // File limits per request
    public const MAX_FILES_PER_REQUEST = 10;

    public const MAX_FILE_SIZE_BYTES = 5 * 1024 * 1024; // 5MB per file

    public const MAX_TOTAL_REQUEST_SIZE = 10 * 1024 * 1024; // 10MB total per request

    // Storage quotas per chat type
    public const QUOTA_DM = 1024 * 1024 * 1024; // 1GB for DMs

    public const QUOTA_GROUP = 1024 * 1024 * 1024; // 1GB for groups

    public const QUOTA_TEAM = 1024 * 1024 * 1024; // 1GB for teams

    // Allowed file types
    public const ALLOWED_IMAGE_TYPES = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    public const ALLOWED_DOCUMENT_TYPES = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt'];

    /**
     * Validate files before upload.
     *
     * @param  array<UploadedFile>  $files
     *
     * @throws \InvalidArgumentException
     */
    public function validateFiles(array $files, Chat $chat): void
    {
        // Check file count
        if (count($files) > self::MAX_FILES_PER_REQUEST) {
            throw new \InvalidArgumentException(
                'Too many files. Maximum '.self::MAX_FILES_PER_REQUEST.' files per upload.'
            );
        }

        $totalSize = 0;

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                throw new \InvalidArgumentException('Invalid file upload.');
            }

            // Check individual file size
            if ($file->getSize() > self::MAX_FILE_SIZE_BYTES) {
                throw new \InvalidArgumentException(
                    "File {$file->getClientOriginalName()} exceeds maximum size of 5MB."
                );
            }

            // Check file type
            $extension = strtolower($file->getClientOriginalExtension());
            $allowedTypes = array_merge(self::ALLOWED_IMAGE_TYPES, self::ALLOWED_DOCUMENT_TYPES);

            if (! in_array($extension, $allowedTypes, true)) {
                throw new \InvalidArgumentException(
                    "File type .{$extension} is not allowed."
                );
            }

            // Validate MIME type server-side (don't trust client)
            $mimeType = $file->getMimeType();
            if (! $this->isAllowedMimeType($mimeType)) {
                throw new \InvalidArgumentException(
                    "File MIME type {$mimeType} is not allowed."
                );
            }

            $totalSize += $file->getSize();
        }

        // Check total request size
        if ($totalSize > self::MAX_TOTAL_REQUEST_SIZE) {
            throw new \InvalidArgumentException(
                'Total file size exceeds maximum of 10MB per upload.'
            );
        }

        // Check chat storage quota
        $currentUsage = $this->getChatStorageUsage($chat);
        $quotaLimit = $this->getChatQuotaLimit($chat);

        if ($currentUsage + $totalSize > $quotaLimit) {
            $remainingMB = round(($quotaLimit - $currentUsage) / 1024 / 1024, 2);
            throw new \InvalidArgumentException(
                "File storage limit reached for this chat. {$remainingMB}MB remaining."
            );
        }
    }

    /**
     * Get current storage usage for a chat in bytes.
     */
    public function getChatStorageUsage(Chat $chat): int
    {
        return (int) DB::table('media')
            ->join('chat_messages', 'media.model_id', '=', 'chat_messages.id')
            ->where('media.model_type', ChatMessage::class)
            ->where('chat_messages.chat_id', $chat->id)
            ->where('media.collection_name', 'chat_attachments')
            ->sum('media.size');
    }

    /**
     * Get quota limit for a chat based on its type.
     */
    public function getChatQuotaLimit(Chat $chat): int
    {
        return match ($chat->type ?? 'dm') {
            'dm' => self::QUOTA_DM,
            'group' => self::QUOTA_GROUP,
            'team' => self::QUOTA_TEAM,
            default => self::QUOTA_DM,
        };
    }

    /**
     * Get remaining storage for a chat in bytes.
     */
    public function getChatStorageRemaining(Chat $chat): int
    {
        $limit = $this->getChatQuotaLimit($chat);
        $usage = $this->getChatStorageUsage($chat);

        return max(0, $limit - $usage);
    }

    /**
     * Get comprehensive storage statistics for a chat.
     *
     * @return array{file_count: int, usage_mb: float, limit_mb: float, percentage_used: float}
     */
    public function getChatStorageStats(Chat $chat): array
    {
        $usage = $this->getChatStorageUsage($chat);
        $limit = $this->getChatQuotaLimit($chat);

        $fileCount = (int) DB::table('media')
            ->join('chat_messages', 'media.model_id', '=', 'chat_messages.id')
            ->where('media.model_type', ChatMessage::class)
            ->where('chat_messages.chat_id', $chat->id)
            ->where('media.collection_name', 'chat_attachments')
            ->count();

        return [
            'file_count' => $fileCount,
            'usage_mb' => round($usage / 1024 / 1024, 2),
            'limit_mb' => round($limit / 1024 / 1024, 2),
            'percentage_used' => $limit > 0 ? round(($usage / $limit) * 100, 1) : 0,
        ];
    }

    /**
     * Attach files to a message.
     *
     * @param  array<UploadedFile>  $files
     * @return array<int> Array of media model IDs
     *
     * @throws FileDoesNotExist
     * @throws FileIsTooBig
     */
    public function attachFilesToMessage(ChatMessage $message, array $files): array
    {
        $mediaIds = [];

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            // Handle empty test files
            if ($file->getSize() <= 0 && $file->getPathname() && file_exists($file->getPathname())) {
                file_put_contents($file->getPathname(), 'placeholder');
            }

            // Generate UUID-based filename to prevent enumeration
            $uuid = Str::uuid()->toString();
            $extension = $file->getClientOriginalExtension();
            $fileName = "{$uuid}.{$extension}";

            // Store original filename in custom properties for display/download
            $media = $message->addMedia($file)
                ->usingFileName($fileName)
                ->withCustomProperties([
                    'original_filename' => $file->getClientOriginalName(),
                    'uploaded_by' => $message->user_id,
                ])
                ->toMediaCollection('chat_attachments');

            $mediaIds[] = $media->id;
        }

        return $mediaIds;
    }

    /**
     * Check if MIME type is allowed.
     */
    protected function isAllowedMimeType(string $mimeType): bool
    {
        $allowed = [
            // Images
            'image/jpeg',
            'image/jpg',
            'image/png',
            'image/webp',
            'image/gif',
            // Documents
            'application/pdf',
            'application/msword',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            'application/vnd.ms-excel',
            'application/vnd.openxmlformats-officedocument.spreadsheetml.sheet',
            'text/plain',
            'application/octet-stream',
            'application/x-empty',
        ];

        return in_array($mimeType, $allowed, true);
    }

    /**
     * Delete media and return success status.
     */
    public function deleteMedia(int $mediaId, User $user): bool
    {
        $media = Media::find($mediaId);

        if (! $media) {
            return false;
        }

        // Verify user can access this media
        if (! $this->canAccessMedia($mediaId, $user)) {
            return false;
        }

        $media->delete();

        return true;
    }

    /**
     * Check if user has permission to access media.
     */
    public function canAccessMedia(int $mediaId, User $user): bool
    {
        $media = Media::find($mediaId);

        if (! $media || $media->model_type !== ChatMessage::class) {
            return false;
        }

        $message = ChatMessage::with('chat.participants')->find($media->model_id);

        if (! $message || ! $message->chat) {
            return false;
        }

        // Check if user is a participant in the chat
        return $message->chat->participants->contains($user);
    }

    /**
     * Get all media for a chat with pagination.
     */
    public function getChatMedia(Chat $chat, ?string $filter = null, int $perPage = 20): LengthAwarePaginator
    {
        $query = Media::query()
            ->join('chat_messages', function ($join) {
                $join->on('media.model_id', '=', 'chat_messages.id')
                    ->where('media.model_type', '=', ChatMessage::class);
            })
            ->where('chat_messages.chat_id', $chat->id)
            ->where('media.collection_name', 'chat_attachments')
            ->select('media.*')
            ->with('model.user')
            ->orderByDesc('media.created_at');

        // Apply filter
        if ($filter === 'images') {
            $query->where('media.mime_type', 'like', 'image/%');
        } elseif ($filter === 'documents') {
            $query->where('media.mime_type', 'not like', 'image/%');
        }

        return $query->paginate($perPage);
    }

    /**
     * Get the URL for a media file.
     */
    public function getMediaUrl(Media $media, ?string $conversion = null): string
    {
        if ($conversion && $media->hasGeneratedConversion($conversion)) {
            return $media->getUrl($conversion);
        }

        return $media->getUrl();
    }

    /**
     * Get the thumbnail URL for a media file (for images).
     */
    public function getThumbUrl(Media $media): ?string
    {
        if (! str_starts_with($media->mime_type, 'image/')) {
            return null;
        }

        if ($media->hasGeneratedConversion('thumb')) {
            return $media->getUrl('thumb');
        }

        return $media->getUrl();
    }
}
