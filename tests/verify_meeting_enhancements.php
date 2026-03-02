<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Meeting;
use App\Models\Event;
use Illuminate\Support\Facades\Auth;
use App\Services\MeetingService;

$user = User::first();
Auth::login($user);

$service = app(MeetingService::class);

echo "--- Test 1: Create Meeting with Calendar Entry ---\n";
try {
    $data = [
        'title' => 'Test Meeting with Calendar',
        'description' => 'Testing calendar integration',
        'start_time' => now()->addHour()->toDateTimeString(),
        'settings' => [
            'guest_access' => true,
        ],
        'save_to_calendar' => true,
        'participants' => [
            ['type' => 'email', 'email' => 'external@example.com']
        ]
    ];

    $meeting = $service->createMeeting($user, $data);
    echo "✅ Meeting created: " . $meeting->public_id . "\n";

    $event = Event::where('meeting_id', $meeting->id)->first();
    if ($event) {
        echo "✅ Calendar Event created: " . $event->public_id . "\n";
        if (in_array('external@example.com', $event->external_attendees)) {
            echo "✅ External attendee found in event\n";
        } else {
            echo "❌ External attendee NOT found in event\n";
        }
    } else {
        echo "❌ Calendar Event NOT created\n";
    }
} catch (\Exception $e) {
    echo "❌ Test 1 Failed: " . $e->getMessage() . "\n";
}

echo "\n--- Test 2: Reject External Guest when guest_access is OFF ---\n";
try {
    $data = [
        'title' => 'Test Restricted Meeting',
        'start_time' => now()->addHours(2)->toDateTimeString(),
        'settings' => [
            'guest_access' => false,
        ],
        'participants' => [
            ['type' => 'email', 'email' => 'forbidden@example.com']
        ]
    ];

    $service->createMeeting($user, $data);
    echo "❌ Test 2 Failed: Should have rejected the external guest\n";
} catch (\Illuminate\Validation\ValidationException $e) {
    echo "✅ Successfully rejected external guest: " . $e->getMessage() . "\n";
} catch (\Exception $e) {
    echo "❌ Test 2 Failed with unexpected error: " . $e->getMessage() . "\n";
}

echo "\nVerification complete.\n";
