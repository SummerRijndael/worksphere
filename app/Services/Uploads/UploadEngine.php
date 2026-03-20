<?php

namespace App\Services\Uploads;

use App\Services\FileSecurityValidator;
use Illuminate\Http\UploadedFile;

class UploadEngine
{
    public function __construct(
        protected FileSecurityValidator $fileValidator
    ) {}

    /**
     * @param  array<UploadedFile>  $files
     * @param  array<string, mixed>  $context
     */
    public function validateFiles(array $files, UploadPolicyContract $policy, array $context = []): void
    {
        $maxFiles = max(1, $policy->maxFilesPerRequest($context));
        if (count($files) > $maxFiles) {
            throw new \InvalidArgumentException(
                "Too many files. Maximum {$maxFiles} files per upload."
            );
        }

        $maxSizePerFile = max(1, $policy->maxFileSizeBytes($context));
        $maxTotalSize = max(1, $policy->maxTotalRequestSizeBytes($context));
        $totalSize = 0;

        foreach ($files as $file) {
            if (! $file instanceof UploadedFile) {
                throw new \InvalidArgumentException('Invalid file upload.');
            }

            $fileSize = max(0, (int) ($file->getSize() ?? 0));
            if ($fileSize > $maxSizePerFile) {
                $humanSize = $this->formatSize($maxSizePerFile);
                throw new \InvalidArgumentException(
                    'File '.htmlspecialchars($file->getClientOriginalName())." exceeds maximum size of {$humanSize}."
                );
            }

            $this->fileValidator->validate($file);
            $totalSize += $fileSize;
        }

        if ($totalSize > $maxTotalSize) {
            $humanTotal = $this->formatSize($maxTotalSize);
            throw new \InvalidArgumentException(
                "Total file size exceeds maximum of {$humanTotal} per upload."
            );
        }

        $policy->validateContext($files, $context);
    }

    /**
     * @param  array<UploadedFile>  $files
     * @param  array<string, mixed>  $context
     * @return array<int> Media IDs
     */
    public function attachFilesToModel(object $model, array $files, UploadPolicyContract $policy, array $context = []): array
    {
        if (! method_exists($model, 'addMedia')) {
            throw new \InvalidArgumentException('Target model does not support media uploads.');
        }

        $mediaIds = [];

        foreach ($files as $index => $file) {
            if (! $file instanceof UploadedFile) {
                continue;
            }

            if (
                (int) ($file->getSize() ?? 0) <= 0
                && $file->getPathname()
                && file_exists($file->getPathname())
            ) {
                file_put_contents($file->getPathname(), 'placeholder');
            }

            $fileName = $policy->generateFileName($file, $index, $model, $context);
            $customProperties = $policy->customPropertiesForFile($file, $index, $model, $context);

            $fileAdder = $model->addMedia($file)
                ->usingFileName($fileName);

            if (! empty($customProperties)) {
                $fileAdder->withCustomProperties($customProperties);
            }

            $media = $fileAdder->toMediaCollection($policy->mediaCollection());
            $mediaIds[] = (int) $media->id;
        }

        return $mediaIds;
    }

    protected function formatSize(int $bytes): string
    {
        if ($bytes >= 1024 * 1024) {
            return (int) round($bytes / 1024 / 1024).'MB';
        }

        if ($bytes >= 1024) {
            return (int) round($bytes / 1024).'KB';
        }

        return $bytes.' bytes';
    }
}
