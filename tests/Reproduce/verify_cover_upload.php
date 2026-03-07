<?php

require __DIR__.'/../../vendor/autoload.php';
$app = require_once __DIR__.'/../../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Services\MediaService;
use Illuminate\Http\UploadedFile;

// 1. Get a user
$user = User::first();
if (! $user) {
    exit("No user found.\n");
}

echo "Testing for user: {$user->name} ({$user->public_id})\n";

// 2. Create a dummy image file
$tempPath = tempnam(sys_get_temp_dir(), 'test_image');
$image = imagecreatetruecolor(100, 100);
$bg = imagecolorallocate($image, 255, 0, 0); // Red
imagefill($image, 0, 0, $bg);
imagepng($image, $tempPath);
imagedestroy($image);

$file = new UploadedFile($tempPath, 'test_cover.png', 'image/png', null, true);

// 3. Process Upload via MediaService (simulating UserController behavior)
$mediaService = app(MediaService::class);
try {
    // This simulates what UserController does: $mediaService->attachFromRequest(..., 'cover', 'cover_photos', $filename, null, 'public')
    $media = $mediaService->attach($user, $file, 'cover_photos', 'test_cover.png', null, 'public');

    echo "\nUpload Successful!\n";
    echo "Disk used: {$media->disk}\n";
    echo "Path Generator Output:\n";
    echo '- Base Path: '.$media->getPath()."\n";
    echo '- URL: '.$media->getUrl()."\n";
    echo "- Expected Path Contains: avatar/cover_p/{$user->public_id}/{$media->id}\n";

    // Validate path
    if (strpos($media->getPath(), "avatar/cover_p/{$user->public_id}/{$media->id}") !== false) {
        echo "✅ Path generator correctly placed file in avatar/cover_p/\n";
    } else {
        echo "❌ Path generator FAILED. Output did not match expected directory.\n";
    }

    // Validate disk
    if ($media->disk === 'public') {
        echo "✅ Disk correctly resolved to 'public'.\n";
    } else {
        echo "❌ Disk resolution FAILED. Expected 'public', got '{$media->disk}'.\n";
    }

} catch (\Exception $e) {
    echo 'Error: '.$e->getMessage()."\n";
} finally {
    // Cleanup
    if (file_exists($tempPath)) {
        unlink($tempPath);
    }
}
