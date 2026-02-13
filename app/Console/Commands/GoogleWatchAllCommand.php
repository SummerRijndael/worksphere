<?php

namespace App\Console\Commands;

use App\Jobs\WatchGoogleCalendarJob;
use App\Models\User;
use Illuminate\Console\Command;

class GoogleWatchAllCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'google:watch-all';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch WatchGoogleCalendarJob for all users with linked Google accounts';

    /**
     * Execute the console command.
     */
    public function handle(): int
    {
        $this->info('Finding users with Google accounts...');

        $query = User::whereHas('socialAccounts', function ($query) {
            $query->where('provider', 'google');
        });

        $count = $query->count();
        $this->info("Found {$count} users.");

        $query->chunkById(100, function ($users) {
            foreach ($users as $user) {
                $this->info("Dispatching Watch job for User ID: {$user->id} ({$user->name})");
                WatchGoogleCalendarJob::dispatch($user);
            }
        });

        $this->info('All jobs dispatched.');

        return Command::SUCCESS;
    }
}
