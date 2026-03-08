<?php

use App\Jobs\ProcessExpiredPermissions;
use App\Jobs\SendPermissionExpiryReminders;
use App\Services\RoleChangeService;
use Illuminate\Foundation\Inspiring;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Schedule;

Artisan::command('inspire', function () {
    $this->comment(Inspiring::quote());
})->purpose('Display an inspiring quote');

/*
|--------------------------------------------------------------------------
| Scheduled Tasks - Permission Service
|--------------------------------------------------------------------------
*/

// Process expired permissions every hour
Schedule::job(new ProcessExpiredPermissions)
    ->hourly()
    ->name('process-expired-permissions')
    ->withoutOverlapping()
    ->onOneServer();

// Send permission expiry reminders daily at 9 AM
Schedule::job(new SendPermissionExpiryReminders(7))
    ->dailyAt('09:00')
    ->name('send-permission-expiry-reminders-7day')
    ->withoutOverlapping()
    ->onOneServer();

// Send urgent expiry reminders (1-day) at 9:05 AM
Schedule::job(new SendPermissionExpiryReminders(1))
    ->dailyAt('09:05')
    ->name('send-permission-expiry-reminders-1day')
    ->withoutOverlapping()
    ->onOneServer();

// Expire old role change requests daily at midnight
Schedule::call(function () {
    app(RoleChangeService::class)->expireOldRequests();
})
    ->daily()
    ->name('expire-role-change-requests')
    ->withoutOverlapping()
    ->onOneServer();

// Horizon metrics snapshot every 5 minutes
Schedule::command('horizon:snapshot')
    ->everyFiveMinutes()
    ->onOneServer();

// Send event reminders every minute
Schedule::job(new \App\Jobs\SendEventRemindersJob)
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// Check ticket SLA breaches and send deadline reminders every 5 minutes
Schedule::job(new \App\Jobs\ProcessTicketRemindersJob)
    ->everyFiveMinutes()
    ->name('ticket-reminders')
    ->withoutOverlapping()
    ->onOneServer();

// Run server monitor checks every minute
Schedule::command('server-monitor:run-checks')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

Schedule::command('email:sync-incremental')
    ->everyTwoMinutes()
    ->name('email:sync-incremental')
    ->withoutOverlapping()
    ->onOneServer();

// Watchdog to rescue stuck email sync jobs
Schedule::command('email:sync-watchdog')
    ->everyTenMinutes()
    ->name('email-sync-watchdog')
    ->withoutOverlapping()
    ->onOneServer();

// Schedule maintenance tasks streaming
Schedule::command('maintenance:stream-cache-stats')->everyMinute()->runInBackground();
Schedule::command('monitor:stream')->everyMinute()->runInBackground();

// Scheduled Daily Backup (Queued on 'heavy') at 1 AM
Schedule::job(new \App\Jobs\CreateSystemBackup('both'), 'heavy')
    ->dailyAt('01:00')
    ->name('daily-system-backup')
    ->withoutOverlapping()
    ->onOneServer();

// Monitor for Zombie Backup Processes
Schedule::command('backup:monitor-status')
    ->hourly()
    ->name('monitor-backup-status')
    ->withoutOverlapping()
    ->onOneServer();

// Prune stale presence users every 5 minutes
Schedule::job(new \App\Jobs\PrunePresenceJob)
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Detect active meetings with no connected participants every minute
Schedule::command('meetings:prune')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// Prune audit logs daily at 2:30 AM
Schedule::command('audit:prune --days=30')
    ->dailyAt('02:30')
    ->name('prune-audit-logs')
    ->withoutOverlapping()
    ->onOneServer();

// Auto-archive old completed tasks daily at 3:30 AM
Schedule::job(new \App\Jobs\ArchiveOldTasksJob)
    ->dailyAt('03:30')
    ->name('auto-archive-tasks')
    ->withoutOverlapping()
    ->onOneServer();

// Prune old page views daily at 5:30 AM
Schedule::command('model:prune', [
    '--model' => [\App\Models\PageView::class],
])->dailyAt('05:30')->onOneServer();

// Monitor external services every 10 minutes
Schedule::command('monitor:external-services')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Check team health daily at 2 AM
Schedule::job(new \App\Jobs\CheckTeamHealthJob)
    ->dailyAt('02:00')
    ->name('team-health-check')
    ->withoutOverlapping()
    ->onOneServer();

// Renew Google Calendar Watch Channels (Daily)
Schedule::job(new \App\Jobs\RenewGoogleWatchChannelsJob)
    ->dailyAt('03:00')
    ->name('renew-google-calendar-channels')
    ->withoutOverlapping()
    ->onOneServer();

// Safety-net: register channels for users with NO active channel (weekly, Sunday 4 AM).
// RenewGoogleWatchChannelsJob (3 AM daily) handles proactive renewal of expiring channels.
// This job only fills gaps: failed initial registrations or truly expired/missing channels.
Schedule::command('google:watch-all')
    ->weekly()
    ->sundays()
    ->at('04:00')
    ->name('google-watch-all-safety-net')
    ->withoutOverlapping()
    ->onOneServer();

// Prune old emails and content (Daily at 5 AM)
Schedule::job(new \App\Jobs\PruneEmailsJob)
    ->dailyAt('05:00')
    ->name('prune-emails')
    ->withoutOverlapping()
    ->onOneServer();

// Send scheduled emails (Every Minute)
Schedule::job(new \App\Jobs\SendScheduledEmailsJob)
    ->everyMinute()
    ->name('send-scheduled-emails')
    ->withoutOverlapping()
    ->onOneServer();

// Firewall: Escalate repeat offenders (runs before unblockip)
Schedule::command('firewall:escalate')
    ->everyMinute()
    ->name('firewall-escalate')
    ->withoutOverlapping()
    ->onOneServer();

// Prune expired database sessions daily
Schedule::call(function () {
    if (config('session.driver') === 'database') {
        \Illuminate\Support\Facades\DB::table(config('session.table', 'sessions'))
            ->where('last_activity', '<=', now()->subMinutes(config('session.lifetime'))->getTimestamp())
            ->delete();
    }
})->dailyAt('02:15')->name('prune-expired-sessions')->onOneServer();

// Prune expired sanctum tokens daily
Schedule::command('sanctum:prune-expired --hours=24')
    ->dailyAt('02:20')
    ->name('prune-sanctum-tokens')
    ->withoutOverlapping()
    ->onOneServer();
