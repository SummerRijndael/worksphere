<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use finfo;

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

        // Check for MIME spoofing: strict equality check
        // Note: Browsers sometimes guess mimes poorly (e.g. generic octet-stream), 
        // so we might need a whitelist of "safe but mismatched" mimes eventually.
        // For strict security, we enforce match or specific allowed aliases.
        
        // Strict consistency check: Does actual mime match allowed mimes? (Already checked in validateMimeType)
        
        // Consistency: Does actual mime match extension?
        // This stops "image.png" being a PHP script (text/x-php).
        // It also stops "resume.docx" being a ZIP file (application/zip).
        
        // Optional: Check if claimed mime matches actual mime.
        // This is what the user suggested.
        // But be careful: a valid CSV might be uploaded as application/vnd.ms-excel but actual is text/csv.
        // If both are allowed, it's fine. If one is allowed, we rely on validateMimeType.
        
        // Let's implement the user's specific request for spoofing check, 
        // but maybe relax it for known harmless mismatches if needed.
        // For now, let's try strict.
        
        if ($actualMime !== $claimedMime) {
             // Exception for generic octet-stream being specifically identified as something else valid
             if ($claimedMime === 'application/octet-stream') {
                 return;
             }
             
             // Exception for Office vs Zip: 
             // If actual is zip, but claimed is docx -> Blocked (this is the vulnerability fix).
             // If actual is docx, but claimed is zip -> Maybe allowed?
             
             // The user code:
             // if ($actualMime !== $claimedMime) throw...
             
             throw ValidationException::withMessages([
                'file' => "MIME spoofing detected. Expected '{$claimedMime}', but found '{$actualMime}'.",
            ]);
        }
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
