<?php

require __DIR__.'/../vendor/autoload.php';
$app = require_once __DIR__.'/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Http\Request;

$user = User::first();
Auth::login($user);

$controller = app(\App\Http\Controllers\Api\MeetingController::class);

echo "--- Test 3: API store with valid data ---\n";
try {
    $request = Request::create('/api/meetings', 'POST', [
        'title' => 'API Test Meeting',
        'start_time' => now()->addMinutes(30)->toDateTimeString(),
        'save_to_calendar' => true,
        'settings' => ['guest_access' => true],
        'participants' => [
            ['type' => 'email', 'email' => 'api-test@example.com', 'name' => 'API Test']
        ]
    ]);
    
    // Set the user on the request
    $request->setUserResolver(fn() => $user);

    $response = $controller->store($request);
    
    if ($response instanceof \App\Http\Resources\MeetingResource) {
        echo "✅ API Request Successful\n";
    } else {
        echo "❌ API Request Failed. Response type: " . get_class($response) . "\n";
        echo "Response data: " . json_encode($response->getData()) . "\n";
    }
} catch (\Exception $e) {
    echo "❌ Test 3 Failed: " . $e->getMessage() . "\n";
}

echo "\n--- Test 4: API store rejection (external guest with guest_access OFF) ---\n";
try {
    $request = Request::create('/api/meetings', 'POST', [
        'title' => 'API Restricted Meeting',
        'start_time' => now()->addMinutes(45)->toDateTimeString(),
        'save_to_calendar' => true,
        'settings' => ['guest_access' => false],
        'participants' => [
            ['type' => 'email', 'email' => 'api-forbidden@example.com', 'name' => 'Forbidden']
        ]
    ]);
    
    $request->setUserResolver(fn() => $user);

    $response = $controller->store($request);
    
    if ($response instanceof \Illuminate\Http\JsonResponse) {
        $data = $response->getData(true);
        if ($response->getStatusCode() === 422) {
            echo "✅ API correctly rejected request with 422: " . $data['message'] . "\n";
        } else {
            echo "❌ API returned unexpected status code: " . $response->getStatusCode() . "\n";
        }
    } else {
        echo "❌ API did NOT return JsonResponse for error. Got: " . get_class($response) . "\n";
    }
} catch (\Exception $e) {
    echo "❌ Test 4 Failed: " . $e->getMessage() . "\n";
}

echo "\nVerification complete.\n";
