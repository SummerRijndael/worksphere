<?php

require __DIR__ . '/../vendor/autoload.php';

$app = require_once __DIR__ . '/../bootstrap/app.php';
$kernel = $app->make(Illuminate\Contracts\Console\Kernel::class);
$kernel->bootstrap();

use App\Models\User;
use App\Models\Team;
use App\Services\PermissionService;
use Illuminate\Support\Facades\Config;

echo "Starting RBAC Scoping Verification...\n";

// 1. Setup Test Data
$service = app(PermissionService::class);
$user = User::factory()->create();
$team = Team::factory()->create(['owner_id' => $user->id]);
$user->teams()->attach($team, ['role' => 'team_lead']); // Owner is also technically a member usually

echo "User created: {$user->id}\n";
echo "Team created: {$team->id} (Owner: {$user->id})\n";

// 2. Verify Helper Method logic
echo "\n--- Verify Scope Parsing ---\n";
$globalScope = $service->getPermissionScope('users.view');
$teamScope = $service->getPermissionScope('invoices.view');
$unknownScope = $service->getPermissionScope('non.existent.permission');

echo "users.view scope: " . $globalScope . " (Expected: global)\n";
echo "invoices.view scope: " . $teamScope . " (Expected: team)\n";
echo "non.existent scope: " . $unknownScope . " (Expected: global)\n";

if ($globalScope !== 'global' || $teamScope !== 'team') {
    echo "❌ FAILED: Scope parsing is incorrect.\n";
    exit(1);
}
echo "✅ PASSED: Scope parsing\n";

// 3. Verify Team Owner Permission Checks

// TEST A: Global Permission (users.view)
// Should be FALSE because scope is global, even though user is Team Owner
echo "\n--- Test A: Global Permission Check (users.view) ---\n";
$canViewUsers = $service->hasTeamPermission($user, $team, 'users.view');
echo "Has 'users.view' via Team Context: " . ($canViewUsers ? 'YES' : 'NO') . "\n";

if ($canViewUsers) {
    echo "❌ FAILED: Team Owner was granted global permission via team context!\n";
    exit(1);
} else {
    echo "✅ PASSED: Team Owner properly denied global permission.\n";
}

// TEST B: Team Permission (invoices.view)
// Should be TRUE because scope is team, and user is Team Owner
echo "\n--- Test B: Team Permission Check (invoices.view) ---\n";
$canViewInvoices = $service->hasTeamPermission($user, $team, 'invoices.view');
echo "Has 'invoices.view' via Team Context: " . ($canViewInvoices ? 'YES' : 'NO') . "\n";

if ($canViewInvoices) {
    echo "✅ PASSED: Team Owner granted team permission.\n";
} else {
    echo "❌ FAILED: Team Owner was denied team permission they should have!\n";
    exit(1);
}

// Cleanup
$team->delete();
$user->delete();

echo "\nALL TESTS PASSED.\n";
