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
        $channelId = 'google-calendar-'.$user->id; // simplified channel ID

        $channel = new \Google\Service\Calendar\Channel;
        $channel->setId($channelId);
        $channel->setType('web_hook');
        $channel->setAddress(config('app.url').'/api/calendar/webhook');

        try {
            $webhookUrl = config('app.url').'/api/calendar/webhook';
            \Illuminate\Support\Facades\Log::info('DEBUG: GoogleCalendarService::watchCalendar - CALLING Google API (events->watch)', [
                'channel_id' => $channelId,
                'webhook_address' => $webhookUrl,
            ]);
            $service->events->watch('primary', $channel);
            \Illuminate\Support\Facades\Log::info('DEBUG: GoogleCalendarService::watchCalendar - GOOGLE API SUCCESS');

            \Illuminate\Support\Facades\Log::info("DEBUG: Google Service: Successfully subscribed to 'primary' calendar for user {$user->id}. Channel ID: {$channelId}");
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("DEBUG: Google Service: Failed to watch calendar for user {$user->id}: ".$e->getMessage());
            throw $e; // Re-throw to make job fail
        } finally {
            \Illuminate\Support\Facades\Log::info('DEBUG: GoogleCalendarService::watchCalendar - EXITING');
        }
    }

    public function syncFromGoogle(string $channelId)
    {
        // Extract user ID from channel ID 'google-calendar-{id}'
        // This is a naive implementation, robust one stores channel ID in DB.
        $userId = str_replace('google-calendar-', '', $channelId);
        $user = User::find($userId);

        if (! $user || ! $this->optimizeClientForUser($user)) {
            return;
        }

        $service = new Calendar($this->client);

        // Use syncToken if available for incremental sync
        // For now, simple list of recently modified

        try {
            $events = $service->events->listEvents('primary', [
                'updatedMin' => now()->subMinutes(15)->toRfc3339String(), // Sync recent changes
                'showDeleted' => true,
                'singleEvents' => true,
            ]);

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
                    // We need to be careful of loops if we just pushed this event.
                    // Check 'last_synced_at' vs updated time?
                    // Or avoid loop by checking if we just touched it.
                    // For now, assume create if new from Google.

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

        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error("Failed to sync from Google for user {$user->id}: ".$e->getMessage());
        }
    }
}
