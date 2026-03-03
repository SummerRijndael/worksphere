<?php

namespace App\Console\Commands;

use App\Jobs\WatchGoogleCalendarJob;
use App\Models\SocialAccount;
use Illuminate\Console\Command;

class GoogleWatchAllCommand extends Command
{
    protected $signature = 'google:watch-all';
    protected $description = 'Safety-net: register watch channels for users who have no active channel yet.';

    public function handle(): int
    {
        // Only target accounts with NO active channel or an already-expired channel.
        // Accounts whose channel is still valid are handled by RenewGoogleWatchChannelsJob
        // (which stops + renews expiring channels proactively within 24 h of expiry).
        // This command only fills gaps: newly connected users whose initial WatchGoogleCalendarJob
        // failed, or accounts where the channel was never registered.
        $accounts = SocialAccount::where('provider', 'google')
            ->whereNotNull('access_token')
            ->whereJsonContains('scopes', 'https://www.googleapis.com/auth/calendar.events')
            ->where(function ($q) {
                $q->whereNull('google_channel_id')
                  ->orWhere('google_channel_expiration', '<=', now());
            })
            ->with('user')
            ->get();

        $count = $accounts->count();
        $this->info("Found {$count} account(s) with no active watch channel.");

        foreach ($accounts as $account) {
            if (! $account->user) {
                continue;
            }

            $this->info("Dispatching watch job for user {$account->user_id} ({$account->user->name}).");
            WatchGoogleCalendarJob::dispatch($account->user);
        }

        $this->info('Done.');

        return Command::SUCCESS;
    }
}
