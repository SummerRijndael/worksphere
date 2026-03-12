<?php

namespace App\Services;

use App\Models\MeetingMessage;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileDoesNotExist;
use Spatie\MediaLibrary\MediaCollections\Exceptions\FileIsTooBig;

class MeetingChatMediaService
{
    public const MAX_FILES_PER_REQUEST = 10;

    public const MAX_FILE_SIZE_BYTES = 5 * 1024 * 1024; // 5MB

    public const MAX_TOTAL_REQUEST_SIZE = 10 * 1024 * 1024; // 10MB

    public function __construct(
        protected FileSecurityValidator $fileValidator
    ) {}

    /**
     * @param  array<UploadedFile>  $files
     */
    public function validateFiles(array $files): void
    {
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

            if ($file->getSize() > self::MAX_FILE_SIZE_BYTES) {
                throw new \InvalidArgumentException(
                    'File '.htmlspecialchars($file->getClientOriginalName()).' exceeds maximum size of 5MB.'
                );
            }

            $this->fileValidator->validate($file);
            $totalSize += $file->getSize();
        }

        if ($totalSize > self::MAX_TOTAL_REQUEST_SIZE) {
            throw new \InvalidArgumentException('Total file size exceeds maximum of 10MB per upload.');
        }
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
        $mediaIds = [];

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            if ($file->getSize() <= 0 && $file->getPathname() && file_exists($file->getPathname())) {
                file_put_contents($file->getPathname(), 'placeholder');
            }

            $uuid = Str::uuid()->toString();
            $extension = $file->getClientOriginalExtension();
            $filename = "{$uuid}.{$extension}";

            $media = $message->addMedia($file)
                ->usingFileName($filename)
                ->withCustomProperties([
                    'original_filename' => $file->getClientOriginalName(),
                    'uploaded_by_participant_public_id' => $message->participant_public_id,
                ])
                ->toMediaCollection(MeetingMessage::MEDIA_COLLECTION);

            $mediaIds[] = $media->id;
        }

        return $mediaIds;
    }
}

