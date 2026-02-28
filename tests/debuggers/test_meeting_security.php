<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Auth;

$user = User::first();
Auth::login($user);

// Clean up old
\App\Models\Meeting::where('title', 'Test Security Meeting')->delete();
\App\Models\Event::where('title', 'Test Security Meeting')->delete();

$meeting = \App\Models\Meeting::create([
    'public_id' => 'test-sec-'.uniqid(),
    'title' => 'Test Security Meeting',
    'start_time' => now(),
    'end_time' => now()->addHour(),
    'user_id' => $user->id,
    'status' => 'scheduled',
    'password' => 'secret123',
    'settings' => ['invited_only' => true, 'guest_access' => true],
]);

$event = \App\Models\Event::create([
    'public_id' => 'test-evt-'.uniqid(),
    'title' => 'Test Security Meeting',
    'start_time' => now(),
    'end_time' => now()->addHour(),
    'user_id' => $user->id,
    'meeting_id' => $meeting->id,
    'external_attendees' => ['guest@example.com'],
]);

echo "--- Running MeetingService Security Tests ---\n";

// Join as guest without password -> Should fail
try {
    app(\App\Contracts\MeetingServiceContract::class)->joinMeeting($meeting, null, 'Guest', 'guest@example.com', null, null);
    echo "❌ FAILED: joined without password\n";
} catch (\Exception $e) {
    if (str_contains($e->getMessage(), 'REQUIRES_PASSWORD') || str_contains($e->getMessage(), 'Incorrect meeting password')) {
        echo "✅ PASSED: password required\n";
    } else {
        echo '❌ FAILED: unexpected error without password - '.$e->getMessage()."\n";
    }
}

// Join as guest with password and guest email inside whitelist -> Should bypass lobby
try {
    $res = app(\App\Contracts\MeetingServiceContract::class)->joinMeeting($meeting, null, 'Guest', 'guest@example.com', 'secret123', null);
    if ($res['participant']->status === 'admitted') {
        echo "✅ PASSED: guest admitted immediately\n";
    } else {
        echo '❌ FAILED: guest not admitted immediately, status: '.$res['participant']->status."\n";
    }
} catch (\Exception $e) {
    echo '❌ FAILED: unexpected error with password - '.$e->getMessage()."\n";
}

// Join as guest with password but UNINVITED email -> Should fail
try {
    app(\App\Contracts\MeetingServiceContract::class)->joinMeeting($meeting, null, 'Hacker', 'hacker@example.com', 'secret123', null);
    echo "❌ FAILED: uninvited guest was able to join\n";
} catch (\Exception $e) {
    if (str_contains($e->getMessage(), 'This meeting is restricted to invited participants')) {
        echo "✅ PASSED: uninvited guest blocked\n";
    } else {
        echo '❌ FAILED: expected ACL block but got: '.$e->getMessage()."\n";
    }
}

// Join as user who is not invited
$user2 = User::where('id', '!=', $user->id)->first();
try {
    app(\App\Contracts\MeetingServiceContract::class)->joinMeeting($meeting, $user2, $user2->name, $user2->email, 'secret123', null);
    echo "❌ FAILED: uninvited user was able to join\n";
} catch (\Exception $e) {
    if (str_contains($e->getMessage(), 'This meeting is restricted to invited participants')) {
        echo "✅ PASSED: uninvited user blocked\n";
    } else {
        echo '❌ FAILED: expected ACL block for user but got: '.$e->getMessage()."\n";
    }
}

echo "--- Tests Complete ---\n";
