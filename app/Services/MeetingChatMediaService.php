<?php

namespace App\Services;

use App\Models\MeetingMessage;
use App\Services\Uploads\Policies\MeetingChatUploadPolicy;
use App\Services\Uploads\UploadEngine;
use Illuminate\Http\UploadedFile;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileDoesNotExist;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileIsTooBig;

class MeetingChatMediaService
{
    public const MAX_FILES_PER_REQUEST = MeetingChatUploadPolicy::MAX_FILES_PER_REQUEST;

    public const MAX_FILE_SIZE_BYTES = MeetingChatUploadPolicy::MAX_FILE_SIZE_BYTES; // 5MB

    public const MAX_TOTAL_REQUEST_SIZE = MeetingChatUploadPolicy::MAX_TOTAL_REQUEST_SIZE_BYTES; // 10MB

    public function __construct(
        protected UploadEngine $uploadEngine,
        protected MeetingChatUploadPolicy $uploadPolicy
    ) {}

    /**
     * @param  array<UploadedFile>  $files
     */
    public function validateFiles(array $files): void
    {
        $this->uploadEngine->validateFiles($files, $this->uploadPolicy);
    }

    /**
     * @param  array<UploadedFile>  $files
     * @return array<int> Media IDs
     *
     * @throws FileDoesNotExist
     * @throws FileIsTooBig
     */
    public function attachFilesToMessage(MeetingMessage $message, array $files): array
    {
        return $this->uploadEngine->attachFilesToModel(
            $message,
            $files,
            $this->uploadPolicy,
            [
                'participant_public_id' => $message->participant_public_id,
            ]
        );
    }
}
