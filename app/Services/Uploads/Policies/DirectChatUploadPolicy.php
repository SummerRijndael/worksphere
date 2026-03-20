<?php

namespace App\Services\Uploads\Policies;

use App\Models\Chat\Chat;
use App\Models\Chat\ChatMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;

class DirectChatUploadPolicy extends AbstractUploadPolicy
{
    public const MAX_FILES_PER_REQUEST = 10;

    public const MAX_FILE_SIZE_BYTES = 5 * 1024 * 1024;

    public const MAX_TOTAL_REQUEST_SIZE_BYTES = 10 * 1024 * 1024;

    public const QUOTA_DM = 1024 * 1024 * 1024;

    public const QUOTA_GROUP = 1024 * 1024 * 1024;

    public const QUOTA_TEAM = 1024 * 1024 * 1024;

    /**
     * @param  array<string, mixed>  $context
     */
    public function maxFilesPerRequest(array $context = []): int
    {
        return self::MAX_FILES_PER_REQUEST;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function maxFileSizeBytes(array $context = []): int
    {
        return self::MAX_FILE_SIZE_BYTES;
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function maxTotalRequestSizeBytes(array $context = []): int
    {
        return self::MAX_TOTAL_REQUEST_SIZE_BYTES;
    }

    public function mediaCollection(): string
    {
        return 'chat_attachments';
    }

    /**
     * @param  array<UploadedFile>  $files
     * @param  array<string, mixed>  $context
     */
    public function validateContext(array $files, array $context = []): void
    {
        $chat = $context['chat'] ?? null;
        if (! $chat instanceof Chat) {
            throw new \InvalidArgumentException('Chat context is required for direct chat uploads.');
        }

        $incomingTotal = array_reduce($files, function (int $carry, mixed $file): int {
            if (! $file instanceof UploadedFile) {
                return $carry;
            }

            return $carry + max(0, (int) ($file->getSize() ?? 0));
        }, 0);

        $currentUsage = $this->storageUsageForChat($chat);
        $quotaLimit = $this->quotaLimitForChat($chat);

        if ($currentUsage + $incomingTotal > $quotaLimit) {
            $remainingMB = round(($quotaLimit - $currentUsage) / 1024 / 1024, 2);
            throw new \InvalidArgumentException(
                "File storage limit reached for this chat. {$remainingMB}MB remaining."
            );
        }
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function customPropertiesForFile(UploadedFile $file, int $index, object $model, array $context = []): array
    {
        $defaults = [
            'original_filename' => $file->getClientOriginalName(),
        ];

        $map = $context['custom_properties_by_index'] ?? [];
        if (! is_array($map)) {
            return $defaults;
        }

        $entry = $map[$index] ?? null;
        if (! is_array($entry)) {
            return $defaults;
        }

        return array_merge($defaults, $entry);
    }

    public function storageUsageForChat(Chat $chat): int
    {
        return (int) DB::table('media')
            ->join('chat_messages', 'media.model_id', '=', 'chat_messages.id')
            ->where('media.model_type', ChatMessage::class)
            ->where('chat_messages.chat_id', $chat->id)
            ->where('media.collection_name', $this->mediaCollection())
            ->sum('media.size');
    }

    public function quotaLimitForChat(Chat $chat): int
    {
        return match ($chat->type ?? 'dm') {
            'dm' => self::QUOTA_DM,
            'group' => self::QUOTA_GROUP,
            'team' => self::QUOTA_TEAM,
            default => self::QUOTA_DM,
        };
    }
}
