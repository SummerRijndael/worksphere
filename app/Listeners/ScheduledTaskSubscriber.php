<?php

namespace App\Listeners;

use App\Events\ScheduledTaskStatusChanged;
use Illuminate\Console\Events\ScheduledTaskFailed;
use Illuminate\Console\Events\ScheduledTaskFinished;
use Illuminate\Console\Events\ScheduledTaskStarting;
use Illuminate\Events\Dispatcher;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;

class ScheduledTaskSubscriber
{
    /**
     * Handle scheduled task starting.
     */
    public function handleStarting(ScheduledTaskStarting $event): void
    {
        $taskName = $this->getTaskName($event->task);

        if (! $taskName) {
            return;
        }

        $cacheKey = "scheduled_task_status:{$taskName}";
        $startTime = now()->toIso8601String();

        Cache::put($cacheKey, [
            'status' => 'running',
            'start_time' => $startTime,
            'last_run' => Cache::get($cacheKey)['last_run'] ?? null, // Preserve previous last_run until finished
        ], 60 * 60 * 24); // Keep for 24 hours

        // Broadcast real-time status update
        ScheduledTaskStatusChanged::dispatch([
            'name' => $taskName,
            'status' => 'running',
            'start_time' => $startTime,
            'duration' => null,
            'error' => null,
        ]);
    }

    /**
     * Handle scheduled task finished.
     */
    public function handleFinished(ScheduledTaskFinished $event): void
    {
        $taskName = $this->getTaskName($event->task);

        if (! $taskName) {
            return;
        }

        $cacheKey = "scheduled_task_status:{$taskName}";
        $currentData = Cache::get($cacheKey, []);
        $lastRun = now()->toIso8601String();

        Cache::put($cacheKey, [
            'status' => 'success',
            'start_time' => $currentData['start_time'] ?? null,
            'end_time' => $lastRun,
            'last_run' => $lastRun,
            'duration' => $event->runtime,
        ], 60 * 60 * 24 * 7); // Keep for 7 days

        // Broadcast real-time status update
        ScheduledTaskStatusChanged::dispatch([
            'name' => $taskName,
            'status' => 'success',
            'start_time' => $currentData['start_time'] ?? null,
            'last_run' => $lastRun,
            'duration' => $event->runtime,
            'error' => null,
        ]);
    }

    /**
     * Handle scheduled task failed.
     */
    public function handleFailed(ScheduledTaskFailed $event): void
    {
        $taskName = $this->getTaskName($event->task);

        if (! $taskName) {
            return;
        }

        $cacheKey = "scheduled_task_status:{$taskName}";
        $currentData = Cache::get($cacheKey, []);
        $lastRun = now()->toIso8601String();
        $errorMessage = $event->exception->getMessage();

        Cache::put($cacheKey, [
            'status' => 'failed',
            'start_time' => $currentData['start_time'] ?? null,
            'end_time' => $lastRun,
            'last_run' => $lastRun,
            'error' => $errorMessage,
        ], 60 * 60 * 24 * 7);

        // Broadcast real-time status update
        ScheduledTaskStatusChanged::dispatch([
            'name' => $taskName,
            'status' => 'failed',
            'start_time' => $currentData['start_time'] ?? null,
            'last_run' => $lastRun,
            'duration' => null,
            'error' => $errorMessage,
        ]);
    }

    /**
     * Get the name of the task.
     */
    protected function getTaskName($task): ?string
    {
        // Try to get the name property if manually assigned (->name('foo'))
        // Note: access to closure/callback properties might be limited, relying on the 'description' or 'command'

        // In Laravel 10+, the event->task is \Illuminate\Console\Scheduling\Event
        // It has a 'description' property if ->name() was called, or ->description()

        // However, standard ->name() sets the description property on the Event object.
        if (! empty($task->description)) {
            return $task->description;
        }

        // If it's a command, use the command signature
        if (Str::contains($task->command, 'artisan')) {
            // Extract command name from "php artisan command:name"
            preg_match('/artisan\s+(?:\'|")?([^\s"\']+)(?:\'|")?/', $task->command, $matches);

            return $matches[1] ?? null;
        }

        return null;
    }

    /**
     * Register the listeners for the subscriber.
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            ScheduledTaskStarting::class => 'handleStarting',
            ScheduledTaskFinished::class => 'handleFinished',
            ScheduledTaskFailed::class => 'handleFailed',
        ];
    }
}
