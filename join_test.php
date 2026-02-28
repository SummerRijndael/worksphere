<?php

require __DIR__ . '/vendor/autoload.php';
$app = require_once __DIR__ . '/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Meeting;
use App\Http\Resources\MeetingResource;
use App\Contracts\MeetingServiceContract;
use Illuminate\Support\Facades\Validator;

$user = User::where('email', 'ev.ryann.olaso@gmail.com')->first();
$meeting = Meeting::latest()->first();

$start = microtime(true);
echo "Starting simulation...\n";

// 1. Validation
try {
    $validator = Validator::make(['email' => $user->email], ['email' => 'nullable|email']);
    $validator->validate();
    echo "Validation done: " . (microtime(true) - $start) . "\n";
} catch (\Exception $e) {
    echo "Validation failed: " . $e->getMessage() . "\n";
}

// 2. Join logic
$service = app(MeetingServiceContract::class);
$result = $service->joinMeeting($meeting, $user, $user->name, $user->email, null, null);
echo "Join logic done: " . (microtime(true) - $start) . "\n";

// 3. Resource serialization
$resource = new MeetingResource($result['meeting']);
$data = $resource->resolve();
echo "Resource resolve done: " . (microtime(true) - $start) . "\n";

$json = json_encode(['data' => [
    'meeting' => $data,
    'participant' => $result['participant'],
]]);
echo "JSON encode done: " . (microtime(true) - $start) . "\n";

echo "All steps finished in " . (microtime(true) - $start) . "\n";
