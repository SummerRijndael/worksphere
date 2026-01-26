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

// Send urgent expiry reminders (1-day) at 9 AM
Schedule::job(new SendPermissionExpiryReminders(1))
    ->dailyAt('09:00')
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
Schedule::command('events:send-reminders')
    ->everyMinute()
    ->withoutOverlapping()
    ->onOneServer();

// Check ticket SLA breaches and send deadline reminders every 5 minutes
Schedule::command('tickets:reminders')
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
    ->everyFiveMinutes()
    ->name('email:sync-incremental')
    ->withoutOverlapping()
    ->onOneServer();

// Schedule maintenance tasks streaming
Schedule::command('maintenance:stream-cache-stats')->everyMinute()->runInBackground();
Schedule::command('monitor:stream')->everyMinute()->runInBackground();

// Scheduled Daily Backup (Queued on 'heavy')
Schedule::job(new \App\Jobs\CreateSystemBackup('both'), 'heavy')
    ->daily()
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
Schedule::command('presence:prune')
    ->everyFiveMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Prune audit logs daily
Schedule::command('audit:prune --days=30')
    ->daily()
    ->name('prune-audit-logs')
    ->withoutOverlapping()
    ->onOneServer();

// Prune old page views daily
Schedule::command('model:prune', [
    '--model' => [\App\Models\PageView::class],
])->daily()->onOneServer();

// Monitor external services every 10 minutes
Schedule::command('monitor:external-services')
    ->everyTenMinutes()
    ->withoutOverlapping()
    ->onOneServer();

// Check team health daily at 2 AM
Schedule::command('teams:check-health')
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
