<?php

namespace Tests\Feature;

use App\Contracts\AvatarContract;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Tests\TestCase;

class AvatarServiceFileCheckTest extends TestCase
{
    use RefreshDatabase;

    protected AvatarContract $avatarService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->avatarService = app(AvatarContract::class);
        Storage::fake('public');
    }

    public function test_resolve_user_with_existing_file_returns_url()
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('avatar.jpg');

        $user->addMedia($file)
            ->toMediaCollection('avatars');

        $result = $this->avatarService->resolve($user);

        $this->assertNotNull($result->url);
        $this->assertStringContainsString('avatar', $result->url); // Basic check
    }

    public function test_resolve_user_with_missing_file_returns_null_url_and_logs_error()
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('avatar.jpg');

        $media = $user->addMedia($file)
            ->toMediaCollection('avatars');

        // Manually delete the file from the disk
        // storage_path('app/public') is faked, so we use Storage::disk('public')->delete()
        // But Spatie stores it in specific folders.
        // Let's just delete the directory of the media.
        // With Custom Path Generator, it is at avatar/{uuid}/{media_id}/

        // Wait, Storage::fake('public') intercepts 'public' disk.
        // Spatie uses 'public' disk.
        // But file_exists($path) checks LOCAL ROOT filesystem path if the driver is local.
        // My implementation in AvatarService uses:
        // $path = $media->getPath($variant);
        // if (file_exists($path)) ...

        // If Storage::fake is used, $media->getPath() returns a path in /tmp/storage...
        // And if I delete it via Storage::disk('public')->delete(), the file at that path should be gone.

        // Get the path relative to the disk
        $relativePath = $media->getPathRelativeToRoot();
        // Or simply:
        $storage = Storage::disk('public');
        $files = $storage->allFiles();
        // Delete all files to simulate data loss
        foreach ($files as $f) {
            $storage->delete($f);
        }

        // Verify file is gone using file_exists on the full path
        $fullPath = $media->getPath();
        $this->assertFileDoesNotExist($fullPath);

        // Clear log file first
        $logPath = storage_path('app/private/sys/logs/avatar_errors.log');
        if (file_exists($logPath)) {
            unlink($logPath);
        }

        // Run resolve
        $result = $this->avatarService->resolve($user);

        // Assert URL is null (fallback)
        $this->assertNull($result->url);

        // Assert Log was created
        $this->assertFileExists($logPath);
        $logContent = file_get_contents($logPath);
        $this->assertStringContainsString("User ID {$user->id}", $logContent);
    }
}
