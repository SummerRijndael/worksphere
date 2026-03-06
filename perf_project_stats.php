<?php

require __DIR__ . '/vendor/autoload.php';

$app = require_once __DIR__ . '/bootstrap/app.php';

use App\Models\Project;
use App\Models\Team;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;
use App\Http\Controllers\Api\ProjectController;

$kernel = $app->make(Illuminate\Contracts\Http\Kernel::class);
$kernel->handle(Request::capture());

// Find any project
$project = Project::first();

if (!$project) {
    echo "No project found for testing. Creating one...\n";
    $team = Team::factory()->create(['name' => 'Perf Team ' . uniqid()]);
    $project = Project::factory()->for($team)->create(['name' => 'Perf Test Project ' . uniqid()]);
}

$team = $project->team;
$user = $team->owner;

// Ensure it has at least one task
if ($project->tasks()->count() === 0) {
    echo "Adding a task to project for testing...\n";
    $project->tasks()->create([
        'team_id' => $team->id,
        'title' => 'Test Task ' . uniqid(),
        'status' => \App\Enums\TaskStatus::Open,
        'created_by' => $user->id,
    ]);
}

echo "Project: {$project->name} (Prefix: {$project->prefix})\n";
echo "Team: {$team->name}\n";

// Mock auth user (must be team owner or member)
auth()->login($user);

$controller = app(ProjectController::class);

// Measure performance
DB::enableQueryLog();
$start = microtime(true);

$response = $controller->stats($team, $project);

$end = microtime(true);
$queries = DB::getQueryLog();
DB::disableQueryLog();

echo "\n--- Performance Baseline ---\n";
echo "Execution Time: " . round(($end - $start) * 1000, 2) . "ms\n";
echo "Query Count: " . count($queries) . "\n";
echo "Response Data: " . json_encode(json_decode($response->getContent()), JSON_PRETTY_PRINT) . "\n";

// Analyze queries
if (count($queries) > 0) {
    echo "\n--- Query Summary ---\n";
    $tables = [];
    foreach ($queries as $query) {
        if (preg_match('/from `([^`]+)`/i', $query['query'], $matches)) {
            $table = $matches[1];
            $tables[$table] = ($tables[$table] ?? 0) + 1;
        }
    }
    foreach ($tables as $table => $count) {
        echo "Table `{$table}`: {$count} queries\n";
    }
}
