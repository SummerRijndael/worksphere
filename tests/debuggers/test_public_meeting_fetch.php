<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Meeting;

// Create a test meeting
$user = User::first();
$meeting = Meeting::create([
    'title' => 'Test Public Meeting Fetch',
    'start_time' => now(),
    'end_time' => now()->addHour(),
    'user_id' => $user->id,
    'status' => 'scheduled',
    'settings' => ['guest_access' => true]
]);

echo "Created meeting " . $meeting->public_id . "\n";

// Test HTTP request
$response = \Illuminate\Support\Facades\Http::get('http://127.0.0.1:8000/api/meetings/' . $meeting->public_id);
echo "Status: " . $response->status() . "\n";
echo "Body: " . $response->body() . "\n";

$meeting->delete();
