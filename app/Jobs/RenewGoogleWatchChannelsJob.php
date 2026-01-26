<?php

namespace App\Jobs;

use App\Contracts\GoogleCalendarContract;
use App\Models\SocialAccount;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class RenewGoogleWatchChannelsJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(GoogleCalendarContract $service): void
    {
        Log::info('Google Calendar: Detailed channel renewal check started.');

        // Find accounts expiring in the next 24 hours
        $expiringAccounts = SocialAccount::where('provider', 'google')
            ->whereNotNull('google_channel_expiration')
            ->where('google_channel_expiration', '<=', now()->addHours(24))
            ->with('user')
            ->get();

        Log::info("Google Calendar: Found {$expiringAccounts->count()} channels expiring soon.");

        foreach ($expiringAccounts as $account) {
            try {
                if (! $account->user) {
                    continue;
                }

                Log::info("Google Calendar: Renewing channel for user {$account->user_id}.");

                // Stop old channel (function needs to be added to service)
                if (method_exists($service, 'stopChannel')) {
                    $service->stopChannel($account);
                }

                // Start new watch
                $service->watchCalendar($account->user);

                Log::info("Google Calendar: Renewed channel for user {$account->user_id}.");

            } catch (\Exception $e) {
                Log::error("Google Calendar: Failed to renew channel for user {$account->user_id}: ".$e->getMessage());
            }
        }
    }
}
