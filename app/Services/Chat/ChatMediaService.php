<?php

namespace App\Services\Chat;

use App\Models\Chat\Chat;
use App\Models\Chat\ChatMessage;
use App\Models\User;
use App\Services\Uploads\Policies\DirectChatUploadPolicy;
use App\Services\Uploads\UploadEngine;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Storage;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileDoesNotExist;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileIsTooBig;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ChatMediaService
{
    public function __construct(
        protected UploadEngine $uploadEngine,
        protected DirectChatUploadPolicy $uploadPolicy
    ) {}

    // File limits per request
    public const MAX_FILES_PER_REQUEST = DirectChatUploadPolicy::MAX_FILES_PER_REQUEST;

    public const MAX_FILE_SIZE_BYTES = DirectChatUploadPolicy::MAX_FILE_SIZE_BYTES; // 5MB per file

    public const MAX_TOTAL_REQUEST_SIZE = DirectChatUploadPolicy::MAX_TOTAL_REQUEST_SIZE_BYTES; // 10MB total per request

    // Storage quotas per chat type
    public const QUOTA_DM = DirectChatUploadPolicy::QUOTA_DM; // 1GB for DMs

    public const QUOTA_GROUP = DirectChatUploadPolicy::QUOTA_GROUP; // 1GB for groups

    public const QUOTA_TEAM = DirectChatUploadPolicy::QUOTA_TEAM; // 1GB for teams

    // Allowed file types
    public const ALLOWED_IMAGE_TYPES = ['jpg', 'jpeg', 'png', 'webp', 'gif'];

    public const ALLOWED_DOCUMENT_TYPES = ['pdf', 'doc', 'docx', 'xls', 'xlsx', 'txt'];

    public const ALLOWED_AUDIO_TYPES = ['webm', 'ogg', 'mp3', 'wav', 'm4a', 'aac', 'flac'];

    /**
     * Validate files before upload.
     *
     * @param  array<UploadedFile>  $files
     *
     * @throws \InvalidArgumentException
     */
    public function validateFiles(array $files, Chat $chat): void
    {
        $this->uploadEngine->validateFiles($files, $this->uploadPolicy, [
            'chat' => $chat,
        ]);
    }

    /**
     * Get current storage usage for a chat in bytes.
     */
    public function getChatStorageUsage(Chat $chat): int
    {
        return $this->uploadPolicy->storageUsageForChat($chat);
    }

    /**
     * Get quota limit for a chat based on its type.
     */
    public function getChatQuotaLimit(Chat $chat): int
    {
        return $this->uploadPolicy->quotaLimitForChat($chat);
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
    public function attachFilesToMessage(ChatMessage $message, array $files, array $fileMetadata = []): array
    {
        $customPropertiesByIndex = [];

        foreach ($files as $index => $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            $originalFilename = (string) $file->getClientOriginalName();
            $clientMimeType = strtolower((string) ($file->getClientMimeType() ?? ''));
            $serverMimeType = strtolower((string) ($file->getMimeType() ?? ''));
            $detectedMime = $serverMimeType !== '' ? $serverMimeType : $clientMimeType;
            $isVoiceClip = $this->isVoiceClipUpload($originalFilename, $clientMimeType, $serverMimeType);
            $mediaKind = $this->inferMediaKind($detectedMime, $isVoiceClip);
            $entryMetadata = is_array($fileMetadata[$index] ?? null) ? $fileMetadata[$index] : [];
            $durationSeconds = $this->normalizeDurationSeconds($entryMetadata['duration_seconds'] ?? null);

            if (($entryMetadata['is_voice_clip'] ?? false) === true) {
                $isVoiceClip = true;
                $mediaKind = 'audio';
            }

            $customPropertiesByIndex[$index] = [
                'original_filename' => $originalFilename,
                'uploaded_by' => $message->user_id,
                'media_kind' => $mediaKind,
                'is_voice_clip' => $isVoiceClip,
            ];
            if ($durationSeconds !== null) {
                $customPropertiesByIndex[$index]['duration_seconds'] = $durationSeconds;
            }
        }

        return $this->uploadEngine->attachFilesToModel(
            $message,
            $files,
            $this->uploadPolicy,
            [
                'chat' => $message->chat,
                'custom_properties_by_index' => $customPropertiesByIndex,
            ]
        );
    }

    protected function normalizeDurationSeconds(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $seconds = (int) round((float) $value);

        return $seconds > 0 ? $seconds : null;
    }

    protected function isVoiceClipUpload(string $filename, string $clientMimeType, string $serverMimeType): bool
    {
        $normalizedName = strtolower(trim($filename));
        if (
            str_starts_with($normalizedName, 'voice-')
            || str_starts_with($normalizedName, 'recording-')
            || str_starts_with($normalizedName, 'audio-')
        ) {
            return true;
        }

        $normalizedClientMime = strtolower(trim($clientMimeType));
        $normalizedServerMime = strtolower(trim($serverMimeType));

        return str_starts_with($normalizedClientMime, 'audio/')
            && (
                str_starts_with($normalizedServerMime, 'audio/')
                || str_starts_with($normalizedServerMime, 'video/webm')
                || str_starts_with($normalizedServerMime, 'video/ogg')
            );
    }

    protected function inferMediaKind(string $mimeType, bool $isVoiceClip = false): string
    {
        if ($isVoiceClip) {
            return 'audio';
        }

        $normalized = strtolower(trim($mimeType));
        if ($normalized === '') {
            return 'file';
        }

        if (str_starts_with($normalized, 'image/')) {
            return 'image';
        }

        if (str_starts_with($normalized, 'audio/')) {
            return 'audio';
        }

        if (str_starts_with($normalized, 'video/')) {
            return 'video';
        }

        return 'file';
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
            // Audio
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
     * Uses temporary signed URLs since chat attachments are on private disk.
     */
    public function getMediaUrl(Media $media, ?string $conversion = null, int $expiryMinutes = 60): string
    {
        if ($conversion && $media->hasGeneratedConversion($conversion)) {
            return $media->getTemporaryUrl(now()->addMinutes($expiryMinutes), $conversion);
        }

        return $media->getTemporaryUrl(now()->addMinutes($expiryMinutes));
    }

    /**
     * Get the thumbnail URL for a media file (for images).
     * Uses temporary signed URLs since chat attachments are on private disk.
     */
    public function getThumbUrl(Media $media, int $expiryMinutes = 60): ?string
    {
        $mimeType = strtolower((string) $media->mime_type);
        $conversion = null;

        if (str_starts_with($mimeType, 'image/')) {
            $conversion = 'thumb';
        } elseif (
            str_starts_with($mimeType, 'video/')
            && ! (bool) $media->getCustomProperty('is_voice_clip', false)
        ) {
            $conversion = 'video_thumb';
        }

        if (! $conversion) {
            return null;
        }

        if ($media->hasGeneratedConversion($conversion)) {
            return $media->getTemporaryUrl(now()->addMinutes($expiryMinutes), $conversion);
        }

        return null;
    }
}
