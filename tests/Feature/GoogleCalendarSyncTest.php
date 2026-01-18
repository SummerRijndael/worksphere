<?php

namespace Tests\Feature;

use App\Jobs\SyncGoogleEventJob;
use App\Models\Event;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Tests\TestCase;

class GoogleCalendarSyncTest extends TestCase
{
    use RefreshDatabase;

    public function test_creating_event_dispatches_sync_job()
    {
        Queue::fake();

        $user = User::factory()->create();

        $event = Event::create([
            'user_id' => $user->id,
            'title' => 'Test Job Dispatch',
            'start_time' => now(),
            'end_time' => now()->addHour(),
        ]);

        Queue::assertPushed(SyncGoogleEventJob::class, function ($job) use ($event) {
            return $job->event->id === $event->id;
        });
    }

    public function test_importing_from_google_does_not_dispatch_job()
    {
        Queue::fake();

        $user = User::factory()->create();

        // Simulate import by setting google_event_id
        $event = Event::create([
            'user_id' => $user->id,
            'title' => 'Imported Event',
            'start_time' => now(),
            'end_time' => now()->addHour(),
            'google_event_id' => 'existing_google_id',
        ]);

        Queue::assertNotPushed(SyncGoogleEventJob::class);
    }

    public function test_updating_event_dispatches_sync_job()
    {
        Queue::fake();
        $user = User::factory()->create();
        $event = Event::create([
            'user_id' => $user->id,
            'title' => 'Original Title',
            'start_time' => now(),
            'end_time' => now()->addHour(),
        ]);

        // Consume the creation job
        Queue::assertPushed(SyncGoogleEventJob::class);
        $this->travel(1)->second(); // Ensure timestamp change if needed

        // Clear pushed jobs for next assertion
        // Queue::fake() resets? No, it accumulates.
        // We can inspect the count or specific properties.

        $event->update(['title' => 'Updated Title']);

        Queue::assertPushed(SyncGoogleEventJob::class, 2); // Should be pushed twice (create + update)
    }

    public function test_updating_last_synced_at_does_not_dispatch_job()
    {
        Queue::fake();
        $user = User::factory()->create();
        $event = Event::create([
            'user_id' => $user->id,
            'title' => 'Sync Check',
            'start_time' => now(),
            'end_time' => now()->addHour(),
        ]);

        // Assert loaded
        Queue::assertPushed(SyncGoogleEventJob::class, 1);

        // Simulate sync saving quiet timestamp
        // Actually observer checks for changes.
        // If we use saveQuietly() (like in Service), Observer IS NOT FIRED.
        // But if we used normal update of just last_synced_at?

        $event->last_synced_at = now();
        $event->save();

        // Should NOT dispatch new job
        Queue::assertPushed(SyncGoogleEventJob::class, 1);
    }

    public function test_deleting_event_dispatches_sync_job()
    {
        Queue::fake();

        $user = User::factory()->create();
        $event = Event::create([
            'user_id' => $user->id,
            'title' => 'Event to Delete',
            'start_time' => now(),
            'end_time' => now()->addHour(),
            'google_event_id' => 'g_123', // Must have google ID to trigger delete sync
        ]);

        $event->delete();

        Queue::assertPushed(SyncGoogleEventJob::class, function ($job) use ($event) {
            return $job->event->id === $event->id;
        });
    }
}
