<?php

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

$user = User::first();
Auth::login($user);

// Clean up old
\App\Models\Event::where('title', 'External Meeting Event')->delete();
\App\Models\Meeting::where('title', 'External Meeting Event')->delete();

$request = new Request();
$request->merge([
    'title' => 'External Meeting Event',
    'start_time' => now()->addDay()->toDateTimeString(),
    'end_time' => now()->addDay()->addHour()->toDateTimeString(),
    'is_meeting' => true,
    'external_emails' => ['external@example.com']
]);

// Set the user on the root request for FormRequest validation context if needed
app()->instance('request', $request);

try {
    $controller = app(\App\Http\Controllers\CalendarController::class);
    $response = $controller->store($request);
    
    $event = \App\Models\Event::where('title', 'External Meeting Event')->first();
    $meeting = \App\Models\Meeting::find($event->meeting_id);
    
    if ($meeting && $meeting->password && strlen($meeting->password) === 10) {
        echo "✅ PASSED: Password auto-generated for external meeting\n";
    } else {
        echo "❌ FAILED: Password NOT auto-generated. Meeting array: " . json_encode($meeting->toArray()) . "\n";
    }
    
    if ($meeting->settings['invited_only']) {
        echo "✅ PASSED: invited_only flag set\n";
    } else {
        echo "❌ FAILED: invited_only flag NOT set\n";
    }
} catch (\Exception $e) {
    echo "❌ FAILED: Error creating event - " . $e->getMessage() . "\n";
}

