<?php

namespace App\Jobs;

use App\Models\PermissionOverride;
use App\Notifications\PermissionExpiryReminder;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Support\Facades\Log;

class SendPermissionExpiryReminders implements ShouldQueue
{
    use Queueable;

    /**
     * The number of times the job may be attempted.
     */
    public int $tries = 3;

    /**
     * The number of seconds to wait before retrying.
     */
    public int $backoff = 120;

    /**
     * Create a new job instance.
     *
     * @param  int  $daysAhead  Number of days ahead to look for expiring permissions
     */
    public function __construct(
        public int $daysAhead = 7
    ) {
        $this->onQueue('notifications');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $expiringOverrides = PermissionOverride::expiringSoon($this->daysAhead)
            ->with(['user', 'grantedByUser'])
            ->get();

        if ($expiringOverrides->isEmpty()) {
            return;
        }

        $count = 0;
        foreach ($expiringOverrides as $override) {
            // Notify the user
            if ($override->user) {
                $override->user->notify(new PermissionExpiryReminder($override));
                $count++;
            }

            // Notify the admin who granted it
            if ($override->grantedByUser && $override->grantedByUser->id !== $override->user?->id) {
                $override->grantedByUser->notify(new PermissionExpiryReminder($override, true));
            }
        }

        if ($count > 0) {
            Log::info("Sent {$count} permission expiry reminders for permissions expiring in {$this->daysAhead} days.");
        }
    }

    /**
     * Get the tags that should be assigned to the job.
     *
     * @return array<string>
     */
    public function tags(): array
    {
        return ['expiry-reminders', 'notifications', "days-ahead:{$this->daysAhead}"];
    }
}
