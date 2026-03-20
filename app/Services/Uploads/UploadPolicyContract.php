<?php

namespace App\Services\Uploads;

use Illuminate\Http\UploadedFile;

interface UploadPolicyContract
{
    /**
     * @param  array<string, mixed>  $context
     */
    public function maxFilesPerRequest(array $context = []): int;

    /**
     * @param  array<string, mixed>  $context
     */
    public function maxFileSizeBytes(array $context = []): int;

    /**
     * @param  array<string, mixed>  $context
     */
    public function maxTotalRequestSizeBytes(array $context = []): int;

    public function mediaCollection(): string;

    /**
     * @param  array<UploadedFile>  $files
     * @param  array<string, mixed>  $context
     */
    public function validateContext(array $files, array $context = []): void;

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function customPropertiesForFile(UploadedFile $file, int $index, object $model, array $context = []): array;

    /**
     * @param  array<string, mixed>  $context
     */
    public function generateFileName(UploadedFile $file, int $index, object $model, array $context = []): string;
}
