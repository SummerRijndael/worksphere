<?php

namespace App\Services\Support;

use App\Models\SupportMessage;
use App\Services\Uploads\Policies\SupportChatUploadPolicy;
use App\Services\Uploads\UploadEngine;
use Illuminate\Http\UploadedFile;

class SupportChatMediaService
{
    public function __construct(
        protected UploadEngine $uploadEngine,
        protected SupportChatUploadPolicy $uploadPolicy
    ) {}

    /**
     * Reuses existing upload validation and security checks.
     *
     * @param  array<UploadedFile>  $files
     */
    public function validateFiles(array $files): void
    {
        $this->uploadEngine->validateFiles($files, $this->uploadPolicy);
    }

    /**
     * @param  array<UploadedFile>  $files
     * @return array<int, array<string, mixed>>
     */
    public function attachFilesToMessage(SupportMessage $message, array $files): array
    {
        $this->uploadEngine->attachFilesToModel(
            $message,
            $files,
            $this->uploadPolicy,
            [
                'sender_user_id' => $message->sender_user_id,
            ]
        );

        return $message->fresh('media')->toAttachmentPayload();
    }
}
