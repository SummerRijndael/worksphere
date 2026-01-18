<?php

namespace App\Observers;

use App\Jobs\SyncGoogleEventJob;
use App\Models\Event;

class EventObserver
{
    /**
     * Handle the Event "created" event.
     */
    public function created(Event $event): void
    {
        // Only sync if it's NOT a Google Event being imported (loop protection handled in Service,
        // but double check here: if google_event_id is set on creation, it likely came from import)
        // However, we might want to Import -> Then Sync to other providers?
        // For distinct Google Sync, if google_event_id is present, it's already on Google.

        if ($event->google_event_id && $event->wasRecentlyCreated) {
            return;
        }

        SyncGoogleEventJob::dispatch($event);
    }

    /**
     * Handle the Event "updated" event.
     */
    public function updated(Event $event): void
    {
        // Outbound Sync: Push changes to Google
        // If google_event_id was just set (e.g. by SyncToGoogle), we shouldn't sync again?
        // SyncToGoogle updates `last_synced_at` and `google_event_id`.
        // We can check if only those changed.

        if ($event->wasChanged(['last_synced_at', 'google_event_id'])) {
            // Check if OTHER fields changed too
            $changes = $event->getChanges();
            unset($changes['last_synced_at']);
            unset($changes['google_event_id']);
            unset($changes['updated_at']);

            if (empty($changes)) {
                return;
            }
        }

        SyncGoogleEventJob::dispatch($event);
    }

    /**
     * Handle the Event "deleted" event.
     */
    public function deleted(Event $event): void
    {
        if ($event->google_event_id) {
            SyncGoogleEventJob::dispatch($event);
        }
    }
}
