<?php

namespace App\Services;

use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;

/**
 * Service for validating file uploads using magic number (file signature) validation.
 *
 * Prevents MIME type spoofing by verifying the actual file content against expected signatures.
 */
class FileSecurityValidator
{
    /**
     * Magic number signatures for common file types.
     * Format: MIME type => array of possible byte signatures
     */
    protected const MAGIC_NUMBERS = [
        // Images
        'image/jpeg' => [
            [0xFF, 0xD8, 0xFF], // JPEG start
        ],
        'image/png' => [
            [0x89, 0x50, 0x4E, 0x47, 0x0D, 0x0A, 0x1A, 0x0A], // PNG signature
        ],
        'image/gif' => [
            [0x47, 0x49, 0x46, 0x38, 0x37, 0x61], // GIF87a
            [0x47, 0x49, 0x46, 0x38, 0x39, 0x61], // GIF89a
        ],

        // Documents
        'application/pdf' => [
            [0x25, 0x50, 0x44, 0x46], // %PDF
        ],
        'text/plain' => [
            // Text files don't have a magic number, validate differently
            'text',
        ],

        // Microsoft Office (legacy)
        'application/msword' => [
            [0xD0, 0xCF, 0x11, 0xE0, 0xA1, 0xB1, 0x1A, 0xE1], // DOC (OLE2)
        ],

        // Microsoft Office (OpenXML) - ZIP-based
        'application/vnd.openxmlformats-officedocument.wordprocessingml.document' => [
            [0x50, 0x4B, 0x03, 0x04], // ZIP (DOCX is ZIP-based)
            [0x50, 0x4B, 0x05, 0x06], // Empty ZIP
            [0x50, 0x4B, 0x07, 0x08], // Spanned ZIP
        ],
    ];

    /**
     * Dangerous file extensions that should always be blocked.
     */
    protected const BLOCKED_EXTENSIONS = [
        'exe', 'bat', 'cmd', 'com', 'pif', 'scr', 'vbs', 'js', 'jar',
        'sh', 'bash', 'elf', 'app', 'deb', 'rpm', 'dmg', 'pkg',
        'php', 'asp', 'aspx', 'jsp', 'cgi', 'pl', 'py', 'rb',
    ];

    /**
     * Maximum file size to read for magic number validation (in bytes).
     */
    protected const MAGIC_NUMBER_READ_SIZE = 16;

    /**
     * Validate a file upload for security.
     *
     * @throws ValidationException
     */
    public function validate(UploadedFile $file): void
    {
        // Check for blocked extensions
        $this->validateExtension($file);

        // Validate MIME type against allowed list
        $this->validateMimeType($file);

        // Validate file signature (magic number)
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
                'file' => "Files with extension '.{$extension}' are not allowed for security reasons.",
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
        $fileMime = $file->getMimeType();

        if (! in_array($fileMime, $allowedMimes, true)) {
            throw ValidationException::withMessages([
                'file' => "File type '{$fileMime}' is not allowed. Allowed types: ".implode(', ', $allowedMimes),
            ]);
        }
    }

    /**
     * Validate file signature (magic number) matches the claimed MIME type.
     *
     * @throws ValidationException
     */
    protected function validateFileSignature(UploadedFile $file): void
    {
        $mimeType = $file->getMimeType();

        // Skip validation for plain text (no magic number)
        if ($mimeType === 'text/plain') {
            return;
        }

        // Get expected signatures for this MIME type
        if (! isset(self::MAGIC_NUMBERS[$mimeType])) {
            // If we don't have magic numbers for this type, skip validation
            // (should have been caught by MIME validation)
            return;
        }

        $expectedSignatures = self::MAGIC_NUMBERS[$mimeType];

        // Read the file header
        $fileHandle = fopen($file->getRealPath(), 'rb');
        if (! $fileHandle) {
            throw ValidationException::withMessages([
                'file' => 'Unable to read file for validation.',
            ]);
        }

        $fileHeader = fread($fileHandle, self::MAGIC_NUMBER_READ_SIZE);
        fclose($fileHandle);

        if ($fileHeader === false) {
            throw ValidationException::withMessages([
                'file' => 'Unable to read file header for validation.',
            ]);
        }

        // Convert file header to byte array
        $headerBytes = array_values(unpack('C*', $fileHeader));

        // Check if any expected signature matches
        $signatureMatches = false;
        foreach ($expectedSignatures as $signature) {
            if ($this->matchesSignature($headerBytes, $signature)) {
                $signatureMatches = true;
                break;
            }
        }

        if (! $signatureMatches) {
            throw ValidationException::withMessages([
                'file' => "File content does not match the expected format for '{$mimeType}'. The file may be corrupted or mislabeled.",
            ]);
        }
    }

    /**
     * Check if header bytes match the expected signature.
     */
    protected function matchesSignature(array $headerBytes, array $signature): bool
    {
        foreach ($signature as $index => $expectedByte) {
            if (! isset($headerBytes[$index]) || $headerBytes[$index] !== $expectedByte) {
                return false;
            }
        }

        return true;
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
                'file' => "Filename '{$originalName}' contains suspicious double extension. Please rename the file.",
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
