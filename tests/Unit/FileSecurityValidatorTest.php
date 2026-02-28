<?php

namespace Tests\Unit;

use App\Services\FileSecurityValidator;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class FileSecurityValidatorTest extends TestCase
{
    protected FileSecurityValidator $validator;

    protected function setUp(): void
    {
        parent::setUp();
        $this->validator = new FileSecurityValidator;

        // Mock config for allowed mimes to emulate a typical setup
        config(['email_attachments.allowed_mimes' => [
            'image/jpeg',
            'image/png',
            'application/pdf',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document', // DOCX
        ]]);
    }

    /** @test */
    public function it_blocks_generic_zip_renamed_as_docx()
    {
        // 1. Create a generic ZIP file (PK header)
        $path = sys_get_temp_dir().'/test_fake_docx.docx';
        file_put_contents($path, "PK\x03\x04".str_repeat("\0", 50));

        // 2. Upload it claiming to be a docx
        $file = new UploadedFile(
            $path,
            'test_fake_docx.docx',
            'application/vnd.openxmlformats-officedocument.wordprocessingml.document',
            null,
            true
        );

        // 3. Expect validation exception because actual mime is application/zip, which is NOT in our allowed list
        $this->expectException(ValidationException::class);
        $this->expectExceptionMessage("File type 'application/zip' is not allowed");

        $this->validator->validate($file);

        unlink($path);
    }

    /** @test */
    public function it_detects_mime_spoofing_via_finfo()
    {
        // User claims PNG, but file is JPEG
        $path = sys_get_temp_dir().'/fake_png.png';
        // Write JPEG header
        file_put_contents($path, "\xFF\xD8\xFF");

        $file = new UploadedFile(
            $path,
            'fake_png.png',
            'image/png', // Client claims PNG
            null,
            true
        );

        // If we strictly enforce client mime matching actual mime:
        // $this->expectExceptionMessage("MIME spoofing detected");

        // OR if we just rely on validated mime being allowed:
        // Actual is image/jpeg. Allowed? Yes.
        // Extension is .png.
        // This is a "confused" file. Valid JPEG content in .png file.
        // FileSecurityValidator currently checks extension (png ok), mime (jpeg ok).
        // Does it check consistency?
        // With manual magic numbers: it checked if 'image/jpeg' (actual) matches the signatures... wait.
        // Current code: validateFileSignature uses $file->getMimeType().
        // $file->getMimeType() returns image/jpeg.
        // It looks up MAGIC_NUMBERS['image/jpeg'].
        // It checks file header against JPEG signatures.
        // It matches.
        // So current code ALLOWS valid JPEG renamed to PNG.

        // This test documents current vs desired behavior.
        // If we want to be strict, we can fail this.
        // For now, let's just assert it validates (no exception) to establish baseline,
        // OR if we implement the user's "Mime Spoofing" check (Client vs Actual), it should FAIL.
        // The user suggested: "MIME spoofing detected. Expected {$claimedMime}, but found {$actualMime}."

        // Let's assume we implement the user's suggestion.
        $this->expectException(ValidationException::class);
        // We expect it to eventually fail with spoofing detection message
        // But for now, let's just see if it fails at all or passes.
        // If I run this against current code, it PASSES (no exception).
        // I will implement the check, so I expect exception.

        $this->validator->validate($file);

        unlink($path);
    }
}
