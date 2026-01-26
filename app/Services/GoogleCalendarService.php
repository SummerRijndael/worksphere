<?php

namespace App\Services;

use App\Contracts\GoogleCalendarContract;
use App\Models\Event;
use App\Models\User;
use Google\Client;
use Google\Service\Calendar;

class GoogleCalendarService implements GoogleCalendarContract
{
    protected Client $client;

    public function __construct()
    {
        $this->client = new Client;
        $this->client->setClientId(config('services.google.client_id'));
        $this->client->setClientSecret(config('services.google.client_secret'));
        $this->client->setAccessType('offline');
    }

    /**
     * Set the user for the client to act as.
     * Returns false if user is not connected.
     */
    public function optimizeClientForUser(User $user): bool
    {
        $account = $user->getSocialAccount('google');

        if (! $account || ! $account->access_token) {
            return false;
        }

        // Check if token expired
        if ($account->token_expires_at && $account->token_expires_at->isPast()) {
            if (! $account->refresh_token) {
                return false; // Cannot refresh
            }

            // Refresh token
            $this->client->fetchAccessTokenWithRefreshToken($account->refresh_token);
            $newTokens = $this->client->getAccessToken();

            // Update DB
            $account->update([
                'access_token' => $newTokens['access_token'],
                'token_expires_at' => now()->addSeconds($newTokens['expires_in']),
                // refresh_token usually stays same unless rotated
                'refresh_token' => $newTokens['refresh_token'] ?? $account->refresh_token,
            ]);
        } else {
            $this->client->setAccessToken($account->access_token);
        }

        return true;
    }

    public function syncToGoogle(Event $event)
    {
        if (! $this->optimizeClientForUser($event->organizer)) {
            return; // User not connected
        }

        $service = new Calendar($this->client);

        $googleEvent = new Calendar\Event([
            'summary' => $event->title,
            'location' => $event->location,
            'description' => $event->description,
            'start' => $this->formatDate($event->start_time, $event->is_all_day),
            'end' => $this->formatDate($event->end_time, $event->is_all_day),
        ]);

        try {
            if ($event->google_event_id) {
                // Update existing
                $service->events->update('primary', $event->google_event_id, $googleEvent);
            } else {
                // Create new
                $createdEvent = $service->events->insert('primary', $googleEvent);
                $event->google_event_id = $createdEvent->id;
                $event->last_synced_at = now();
                $event->saveQuietly();
            }
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Failed to sync event {$event->id} to Google: ".$e->getMessage());
        }
    }

    public function deleteFromGoogle(Event $event)
    {
        if (! $event->google_event_id || ! $this->optimizeClientForUser($event->organizer)) {
            return;
        }

        $service = new Calendar($this->client);
        try {
            $service->events->delete('primary', $event->google_event_id);
            $service->events->delete('primary', $event->google_event_id);
            $event->google_event_id = null;
            $event->saveQuietly();
        } catch (\Exception $e) {
            // 410 Gone means already deleted, ignore
            if ($e->getCode() != 410) {
                \Illuminate\Support\Facades\Log::error("Failed to delete event {$event->id} from Google: ".$e->getMessage());
            }
        }
    }

    protected function formatDate($date, $isAllDay)
    {
        if ($isAllDay) {
            return ['date' => $date->format('Y-m-d')];
        }

        return ['dateTime' => $date->toRfc3339String()];
    }

    public function watchCalendar(User $user)
    {
        \Illuminate\Support\Facades\Log::info('DEBUG: GoogleCalendarService::watchCalendar - ENTERING', ['user_id' => $user->id]);

        if (! $this->optimizeClientForUser($user)) {
            \Illuminate\Support\Facades\Log::info('DEBUG: GoogleCalendarService::watchCalendar - EXITING (User not connected)');

            return;
        }

        $service = new Calendar($this->client);

        // Use a UUID for unique channel ID to allow renewal overlapping
        $channelId = (string) \Illuminate\Support\Str::uuid();

        $channel = new \Google\Service\Calendar\Channel;
        $channel->setId($channelId);
        $channel->setType('web_hook');
        $channel->setAddress(config('app.url').'/api/calendar/webhook');

        // Custom expiration (optional, Google defaults to 7 days, we can request less but not more usually)
        // We track what Google returns.

        try {
            $webhookUrl = config('app.url').'/api/calendar/webhook';
            \Illuminate\Support\Facades\Log::info('DEBUG: GoogleCalendarService::watchCalendar - CALLING Google API (events->watch)', [
                'channel_id' => $channelId,
                'webhook_address' => $webhookUrl,
            ]);

            $watchResponse = $service->events->watch('primary', $channel);

            \Illuminate\Support\Facades\Log::info('DEBUG: GoogleCalendarService::watchCalendar - GOOGLE API SUCCESS');

            // Save Channel Details
            $account = $user->getSocialAccount('google');
            $expirationMs = $watchResponse->expiration; // Google returns Unix timestamp in MS
            $expirationDate = $expirationMs ? \Carbon\Carbon::createFromTimestampMs($expirationMs) : now()->addDays(7);

            $account->update([
                'google_channel_id' => $watchResponse->id,
                'google_resource_id' => $watchResponse->resourceId,
                'google_channel_expiration' => $expirationDate,
            ]);

            \Illuminate\Support\Facades\Log::info("DEBUG: Google Service: Successfully subscribed to 'primary' calendar. Channel ID: {$watchResponse->id}, Expires: {$expirationDate}");
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("DEBUG: Google Service: Failed to watch calendar for user {$user->id}: ".$e->getMessage());
            throw $e;
        } finally {
            \Illuminate\Support\Facades\Log::info('DEBUG: GoogleCalendarService::watchCalendar - EXITING');
        }
    }

    public function stopChannel(\App\Models\SocialAccount $account)
    {
        if (! $account->google_channel_id || ! $account->google_resource_id) {
            return;
        }

        if (! $this->optimizeClientForUser($account->user)) {
            return;
        }

        $service = new Calendar($this->client);
        $channel = new \Google\Service\Calendar\Channel;
        $channel->setId($account->google_channel_id);
        $channel->setResourceId($account->google_resource_id);

        try {
            $service->channels->stop($channel);
            $account->update([
                'google_channel_id' => null,
                'google_resource_id' => null,
                'google_channel_expiration' => null,
            ]);
            \Illuminate\Support\Facades\Log::info("DEBUG: Google Service: Stopped channel {$account->google_channel_id}");
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::warning("DEBUG: Google Service: Failed to stop channel {$account->google_channel_id}: ".$e->getMessage());
        }
    }

    public function syncFromGoogle(string $channelId)
    {
        // For security, rely on channel_id lookup in social_accounts table
        // instead of naive ID extraction.
        $account = \App\Models\SocialAccount::where('google_channel_id', $channelId)->first();

        // Fallback for transition period or multiple channel support
        if (! $account) {
            // Only if using the old naive ID schema
            $userId = str_replace('google-calendar-', '', $channelId);
            $user = User::find($userId);
            if ($user) {
                $account = $user->getSocialAccount('google');
            }
        }

        if (! $account || ! $account->user) {
            \Illuminate\Support\Facades\Log::warning("Received webhook for unknown channel: {$channelId}");

            return;
        }

        $user = $account->user;

        if (! $this->optimizeClientForUser($user)) {
            return;
        }

        $service = new Calendar($this->client);

        try {
            $params = [
                'showDeleted' => true,
                'singleEvents' => true,
            ];

            // Use Sync Token if available, otherwise fallback to recent
            if ($account->google_sync_token) {
                $params['syncToken'] = $account->google_sync_token;
            } else {
                $params['updatedMin'] = now()->subMinutes(15)->toRfc3339String();
            }

            $events = $service->events->listEvents('primary', $params);

            // Store new Sync Token
            if ($events->getNextSyncToken()) {
                $account->update(['google_sync_token' => $events->getNextSyncToken()]);
            }

            foreach ($events->getItems() as $googleEvent) {
                if ($googleEvent->status === 'cancelled') {
                    // Handle deletion
                    Event::withoutEvents(function () use ($googleEvent) {
                        Event::where('google_event_id', $googleEvent->id)->delete();
                    });

                    continue;
                }

                // Handle creation/update
                $startTime = $googleEvent->start->dateTime ?? $googleEvent->start->date;
                $endTime = $googleEvent->end->dateTime ?? $googleEvent->end->date;
                $isAllDay = ! isset($googleEvent->start->dateTime);

                // Find local event by google_id
                $event = Event::where('google_event_id', $googleEvent->id)->first();

                if ($event) {
                    // Update
                    $event->fill([
                        'title' => $googleEvent->summary,
                        'description' => $googleEvent->description,
                        'location' => $googleEvent->location,
                        'start_time' => $startTime,
                        'end_time' => $endTime,
                        'is_all_day' => $isAllDay,
                        'last_synced_at' => now(),
                    ])->saveQuietly();
                } else {
                    // Create (if not exists locally)
                    Event::withoutEvents(function () use ($user, $googleEvent, $startTime, $endTime, $isAllDay) {
                        Event::create([
                            'user_id' => $user->id,
                            'google_event_id' => $googleEvent->id,
                            'title' => $googleEvent->summary ?? '(No Title)',
                            'description' => $googleEvent->description,
                            'location' => $googleEvent->location,
                            'start_time' => $startTime,
                            'end_time' => $endTime,
                            'is_all_day' => $isAllDay,
                            'last_synced_at' => now(),
                        ]);
                    });
                }
            }

            // If result was paginated, we should strictly loop pages.
            // For now assuming single page for partial syncs (usual case).
            // But if full sync, might need loop.
            // Google PHP Library auto-pagination possible? Yes usually.

        } catch (\Google\Service\Exception $e) {
            if ($e->getCode() == 410) {
                // Sync token invalid (expired/cleared). Clear it and perform full sync next time (or now).
                $account->update(['google_sync_token' => null]);
                \Illuminate\Support\Facades\Log::info("Google Sync Token expired for user {$user->id}, clearing.");
                // Could recurse once to retry without token
            }
            \Illuminate\Support\Facades\Log::error("Failed to sync from Google for user {$user->id}: ".$e->getMessage());
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Failed to sync from Google for user {$user->id}: ".$e->getMessage());
        }
    }
}
