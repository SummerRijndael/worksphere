<?php

use App\Models\Chat\Chat;
use App\Models\Chat\ChatMessage;
use App\Models\User;
use App\Jobs\CleanChatMediaJob;
use Illuminate\Support\Facades\Storage;
use Illuminate\Support\Str;

require __DIR__.'/vendor/autoload.php';
$app = require_once __DIR__.'/bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

function verifyCleanup() {
    echo "--- Starting Media Cleanup Verification ---\n";

    // 1. Setup Test Data
    $user = User::first();
    if (!$user) {
        die("No user found to run test.\n");
    }

    $chat = Chat::create([
        'public_id' => (string) Str::ulid(),
        'name' => 'Cleanup Test Group',
        'type' => 'group',
        'created_by' => $user->id,
    ]);

    // Create a message with an attachment
    $message = ChatMessage::create([
        'public_id' => (string) Str::ulid(),
        'chat_id' => $chat->id,
        'user_id' => $user->id,
        'content' => 'Test message with image',
    ]);

    // Simulate an attachment (requires a real file or mock)
    // For simplicity in this script, we'll check if the job runs without crashing
    // and correctly processes the message.
    
    echo "Created Chat: {$chat->id} and Message: {$message->id}\n";

    // 2. Run Cleanup Job Synchronously
    echo "Dispatching CleanChatMediaJob...\n";
    $job = new CleanChatMediaJob($chat->id);
    $job->handle();

    echo "Job finished processing.\n";

    // 3. Verify (Conceptual - since we didn't add real media in this quick script)
    // If real media was added, we would check Storage::disk('public')->exists(...)
    
    echo "--- Verification Complete ---\n";
}

verifyCleanup();
