<?php

namespace App\Services;

use finfo;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Service for validating file uploads using strict MIME type verification via finfo.
 *
 * Prevents MIME type spoofing by verifying the actual file content against claimed MIME types.
 */
class FileSecurityValidator
{
    /**
     * Dangerous file extensions that should always be blocked.
     */
    protected const BLOCKED_EXTENSIONS = [
        'exe', 'bat', 'cmd', 'com', 'pif', 'scr', 'vbs', 'js', 'jar',
        'sh', 'bash', 'elf', 'app', 'deb', 'rpm', 'dmg', 'pkg',
        'php', 'asp', 'aspx', 'jsp', 'cgi', 'pl', 'py', 'rb',
    ];

    /**
     * Validate a file upload for security.
     *
     * @throws ValidationException
     */
    public function validate(UploadedFile $file): void
    {
        // Check for blocked extensions
        $this->validateExtension($file);

        // Validate MIME type against allowed list (using actual content mime)
        $this->validateMimeType($file);

        // Validate file signature / consistency
        $this->validateFileSignature($file);

        // Check for double extensions
        $this->validateDoubleExtension($file);
    }

    /**
     * Validate file extension is not in blocked list.
     *
     * @throws ValidationException
     */
    protected function validateExtension(UploadedFile $file): void
    {
        $extension = strtolower($file->getClientOriginalExtension());

        if (in_array($extension, self::BLOCKED_EXTENSIONS, true)) {
            throw ValidationException::withMessages([
                'file' => "Files with extension '.".htmlspecialchars($extension)."' are not allowed for security reasons.",
            ]);
        }
    }

    /**
     * Validate MIME type against allowed list from config.
     *
     * @throws ValidationException
     */
    protected function validateMimeType(UploadedFile $file): void
    {
        $allowedMimes = config('email_attachments.allowed_mimes', []);

        // Use finfo to get the reliable MIME type based on content
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $actualMime = $finfo->file($file->getRealPath());

        if (! in_array($actualMime, $allowedMimes, true)) {
            throw ValidationException::withMessages([
                'file' => "File type '".htmlspecialchars($actualMime)."' is not allowed. Allowed types: ".implode(', ', $allowedMimes),
            ]);
        }
    }

    /**
     * Validate file signature (consistency check).
     *
     * @throws ValidationException
     */
    protected function validateFileSignature(UploadedFile $file): void
    {
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $actualMime = $finfo->file($file->getRealPath());
        $claimedMime = $file->getClientMimeType();

        // If they match perfectly, we're good.
        if ($actualMime === $claimedMime) {
            return;
        }

        // 1. Blacklist check: Never allow spoofing high-risk executable types.
        $highRiskMimes = [
            'text/x-php', 'application/x-executable', 'application/x-sharedlib',
            'application/x-sh', 'text/x-shellscript', 'application/x-msdownload',
        ];

        if (in_array($actualMime, $highRiskMimes, true)) {
            throw ValidationException::withMessages([
                'file' => 'Security violation: High-risk file type spoofing detected.',
            ]);
        }

        // 2. Whitelist check: Allow common harmless mismatches.
        $safeMismatches = [
            // Claimed => [Actuals]
            'application/vnd.ms-excel' => ['text/plain', 'text/csv'],
            'text/csv' => ['text/plain', 'application/csv', 'application/vnd.ms-excel'],
            'image/png' => ['image/x-png'],
            'image/jpeg' => ['image/pjpeg'],
            'application/octet-stream' => true, // Always allow identified stream
        ];

        if (isset($safeMismatches[$claimedMime])) {
            $allowedActuals = $safeMismatches[$claimedMime];
            if ($allowedActuals === true || in_array($actualMime, $allowedActuals, true)) {
                return;
            }
        }

        // 3. Document mismatch check (Strictness for macros)
        // If claimed is a modern office doc, but actual is just a ZIP, it's often fine,
        // but it's a common vector for macro malware if processed incorrectly.
        // We rely on validateMimeType to ensure both are in the global allowed list.

        // Default: Throw if not a known safe mismatch
        throw ValidationException::withMessages([
            'file' => 'File content does not match the file extension provided (MIME spoofing detected).',
        ]);
    }

    /**
     * Validate that filename doesn't contain double extensions (e.g., file.pdf.exe).
     *
     * @throws ValidationException
     */
    protected function validateDoubleExtension(UploadedFile $file): void
    {
        $originalName = $file->getClientOriginalName();

        // Remove the last extension
        $nameWithoutExtension = pathinfo($originalName, PATHINFO_FILENAME);

        // Check if there's another extension in the filename
        $suspiciousExtension = pathinfo($nameWithoutExtension, PATHINFO_EXTENSION);

        if ($suspiciousExtension && in_array(strtolower($suspiciousExtension), self::BLOCKED_EXTENSIONS, true)) {
            throw ValidationException::withMessages([
                'file' => "Filename '".htmlspecialchars($originalName)."' contains suspicious double extension. Please rename the file.",
            ]);
        }
    }

    /**
     * Validate multiple files.
     *
     * @param  array<UploadedFile>  $files
     *
     * @throws ValidationException
     */
    public function validateMultiple(array $files): void
    {
        foreach ($files as $index => $file) {
            try {
                $this->validate($file);
            } catch (ValidationException $e) {
                // Re-throw with file index
                throw ValidationException::withMessages([
                    "attachments.{$index}" => $e->getMessage(),
                ]);
            }
        }
    }
}
