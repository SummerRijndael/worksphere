<?php

use App\Models\User;
use Illuminate\Support\Str;
use Laragear\WebAuthn\Models\WebAuthnCredential;

require __DIR__.'/../../vendor/autoload.php';
$app = require __DIR__.'/../../bootstrap/app.php';
$app->make(\Illuminate\Contracts\Console\Kernel::class)->bootstrap();

echo "--- Debugging Passkey Relationship ---\n";

// 1. Create specific debug user
$user = User::firstOrCreate(
    ['email' => 'debug_passkey@example.com'],
    [
        'name' => 'Debug Passkey',
        'password' => bcrypt('password'),
        'public_id' => Str::uuid()->toString(),
    ]
);

echo 'User ID: '.$user->id."\n";
echo 'User Public ID: '.$user->public_id."\n";

// 2. Create Dummy Credential (manually, bypassing complex Attestation flow)
// We just want to check relation loading
$credentialId = Str::random(20);

// Check if exists
WebAuthnCredential::where('id', $credentialId)->delete();

$credential = (new WebAuthnCredential)->forceFill([
    'id' => $credentialId,
    'user_id' => $user->id, // This is the handle
    'alias' => 'Debug Key',
    'rp_id' => 'localhost',
    'origin' => 'http://localhost',
    'public_key' => 'dummy_key',
    'attestation_format' => 'none',
    'aaguid' => Str::uuid()->toString(),
]);

$credential->authenticatable()->associate($user);
$credential->save();

echo 'Credential Saved. ID: '.$credential->id."\n";
echo 'Credential Authenticatable ID: '.$credential->authenticatable_id."\n";
echo 'Credential Authenticatable Type: '.$credential->authenticatable_type."\n";

// 3. Reload and Check
$loadedCredential = WebAuthnCredential::find($credential->id);

if (! $loadedCredential) {
    echo "ERROR: Could not load credential.\n";
    exit(1);
}

echo "Loaded Credential.\n";
$loadedUser = $loadedCredential->authenticatable;

if ($loadedUser) {
    echo "SUCCESS: User loaded from credential.\n";
    echo 'Loaded User ID: '.$loadedUser->id."\n";
} else {
    echo "Agorra: User relation returned NULL.\n";
    echo "Debug Info:\n";
    print_r($loadedCredential->toArray());
}

// Cleanup
$credential->delete();
// $user->delete(); // Keep user for inspection if needed
