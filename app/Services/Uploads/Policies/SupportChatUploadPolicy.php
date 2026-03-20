<?php

namespace App\Services\Uploads\Policies;

use App\Models\SupportMessage;
use Illuminate\Http\UploadedFile;

class SupportChatUploadPolicy extends AbstractUploadPolicy
{
    public const MAX_FILES_PER_REQUEST = 10;

    public const MAX_FILE_SIZE_BYTES = 5 * 1024 * 1024;

    public const MAX_TOTAL_REQUEST_SIZE_BYTES = 10 * 1024 * 1024;

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
        return SupportMessage::MEDIA_COLLECTION;
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function customPropertiesForFile(UploadedFile $file, int $index, object $model, array $context = []): array
    {
        $senderUserId = $context['sender_user_id'] ?? ($model->sender_user_id ?? null);

        return [
            'original_filename' => $file->getClientOriginalName(),
            'uploaded_by_user_id' => $senderUserId,
        ];
    }
}
