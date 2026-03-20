<?php

namespace App\Services\Uploads\Policies;

use App\Services\Uploads\UploadPolicyContract;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Str;

abstract class AbstractUploadPolicy implements UploadPolicyContract
{
    /**
     * @param  array<UploadedFile>  $files
     * @param  array<string, mixed>  $context
     */
    public function validateContext(array $files, array $context = []): void
    {
        // No-op by default.
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function customPropertiesForFile(UploadedFile $file, int $index, object $model, array $context = []): array
    {
        return [];
    }

    /**
     * @param  array<string, mixed>  $context
     */
    public function generateFileName(UploadedFile $file, int $index, object $model, array $context = []): string
    {
        $uuid = Str::uuid()->toString();
        $extension = trim((string) $file->getClientOriginalExtension());

        return $extension !== '' ? "{$uuid}.{$extension}" : $uuid;
    }
}
