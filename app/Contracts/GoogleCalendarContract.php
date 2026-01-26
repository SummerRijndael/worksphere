<?php

namespace App\Contracts;

use App\Models\Event;
use App\Models\User;

interface GoogleCalendarContract
{
    /**
     * Set the user context for the Google Client.
     */
    public function optimizeClientForUser(User $user): bool;

    /**
     * Sync a local event to Google Calendar.
     */
    public function syncToGoogle(Event $event);

    /**
     * Delete an event from Google Calendar.
     */
    public function deleteFromGoogle(Event $event);

    /**
     * Setup a webhook to watch for changes on the user's primary calendar.
     */
    public function watchCalendar(User $user);

    /**
     * Sync changes from Google based on a notification channel (webhook).
     */
    public function syncFromGoogle(string $channelId);

    /**
     * Stop a notification channel.
     */
    public function stopChannel(\App\Models\SocialAccount $account);
}
