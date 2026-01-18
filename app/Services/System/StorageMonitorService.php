<?php

namespace App\Services\System;

use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Storage;
use Throwable;

class StorageMonitorService
{
    /**
     * Get combined storage statistics.
     */
    public function getStorageStats(): array
    {
        return [
            'local' => $this->getLocalUsage(),
            's3' => $this->getS3Usage(),
        ];
    }

    /**
     * Calculate local public storage usage.
     */
    public function getLocalUsage(): array
    {
        try {
            $path = storage_path('app/public');
            if (! is_dir($path)) {
                return $this->formatStats(0, 0);
            }

            $size = 0;
            $count = 0;
            $directory = new \RecursiveDirectoryIterator($path, \FilesystemIterator::SKIP_DOTS);
            $iterator = new \RecursiveIteratorIterator($directory, \RecursiveIteratorIterator::LEAVES_ONLY);

            foreach ($iterator as $file) {
                if ($file->isFile()) {
                    $size += $file->getSize();
                    $count++;
                }
            }

            return $this->formatStats($size, $count, $path);
        } catch (Throwable $e) {
            return [
                'size_bytes' => 0,
                'size_formatted' => 'Error',
                'file_count' => 0,
                'path' => storage_path('app/public'),
                'error' => $e->getMessage(),
            ];
        }
    }

    /**
     * Calculate S3 bucket usage (if configured).
     */
    public function getS3Usage(): ?array
    {
        $disk = 's3';

        // Check if S3 is configured
        if (! Config::get("filesystems.disks.{$disk}")) {
            return null;
        }

        // Check if S3 bucket is configured
        if (empty(Config::get("filesystems.disks.{$disk}.bucket"))) {
            return null;
        }

        try {
            // This can be slow for large buckets.
            // Ideally, we'd use CloudWatch metrics or cache this result heavily.
            // For now, we'll list all files.

            // Optimized listing that fetches metadata including size
            // This avoids N+1 calls for size()
            $contents = Storage::disk($disk)->listContents('', true);

            $size = 0;
            $count = 0;

            foreach ($contents as $item) {
                if ($item->isFile()) {
                    $size += $item->fileSize() ?? 0;
                    $count++;
                }
            }

            return $this->formatStats($size, $count, Config::get("filesystems.disks.{$disk}.bucket"));

        } catch (Throwable $e) {
            return [
                'size_bytes' => 0,
                'size_formatted' => 'Error/Unavailable',
                'file_count' => 0,
                'path' => Config::get("filesystems.disks.{$disk}.bucket"),
                'error' => $e->getMessage(),
            ];
        }
    }

    protected function formatStats(int $bytes, int $count, string $path = ''): array
    {
        return [
            'size_bytes' => $bytes,
            'size_formatted' => $this->formatBytes($bytes),
            'file_count' => $count,
            'path' => $path,
        ];
    }

    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        if ($bytes === 0) {
            return '0 B';
        }

        $units = ['B', 'KB', 'MB', 'GB', 'TB'];
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= (1 << (10 * $pow));

        return round($bytes, $precision).' '.$units[$pow];
    }
}
