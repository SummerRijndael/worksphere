<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\AvatarService;
use App\Services\FileSecurityValidator;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Validation\ValidationException;
use Tests\TestCase;

class AvatarSecurityTest extends TestCase
{
    use RefreshDatabase;

    protected AvatarService $avatarService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->avatarService = app(AvatarService::class);
    }

    /** @test */
    public function it_allows_valid_image_upload()
    {
        $user = User::factory()->create();
        $file = UploadedFile::fake()->image('avatar.jpg');

        // Should not throw exception
        $this->avatarService->processUpload($file, $user, 'avatars');

        $this->assertTrue($user->hasMedia('avatars'));
    }

    /** @test */
    public function it_blocks_php_script_masked_as_image()
    {
        $user = User::factory()->create();
        
        // Create a fake file with PHP content but .png extension
        $file = UploadedFile::fake()->createWithContent('malicious.png', '<?php echo "hacked"; ?>');

        $this->expectException(ValidationException::class);
        
        $this->avatarService->processUpload($file, $user, 'avatars');
    }

    /** @test */
    public function it_blocks_text_file_masked_as_image()
    {
        $user = User::factory()->create();
        
        // Create a fake file with explicit JPEG mime but random content (not JPEG signature)
        $file = UploadedFile::fake()->create('text.jpg', 100, 'image/jpeg');

        $this->expectException(ValidationException::class);
        
        $this->avatarService->processUpload($file, $user, 'avatars');
    }

    /** @test */
    public function it_blocks_blocked_extensions()
    {
        $user = User::factory()->create();
        
        $file = UploadedFile::fake()->create('malware.exe', 100);

        $this->expectException(ValidationException::class);
        
        $this->avatarService->processUpload($file, $user, 'avatars');
    }
}
