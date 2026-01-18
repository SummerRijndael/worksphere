<?php

namespace App\Console\Commands;

use App\Services\EmailSyncService;
use Illuminate\Console\Command;

class EmailSyncWatchdog extends Command
{
    protected $signature = 'email:sync-watchdog';

    protected $description = 'Watch for email accounts that need sync and dispatch jobs';

    public function handle(EmailSyncService $syncService): int
    {
        $accounts = $syncService->getAccountsNeedingSync();

        if ($accounts->isEmpty()) {
            $this->line('No accounts need syncing.');

            return self::SUCCESS;
        }

        $this->info("Found {$accounts->count()} account(s) needing sync.");

        foreach ($accounts as $account) {
            $this->line("Processing account #{$account->id} ({$account->email})...");

            try {
                match ($account->sync_status->value) {
                    'pending' => $syncService->startSeed($account),
                    'seeding', 'syncing' => $syncService->continueSync($account),
                    default => null,
                };

                $this->info("  → Dispatched sync for {$account->email}");
            } catch (\Throwable $e) {
                $this->error("  → Failed: {$e->getMessage()}");
            }
        }

        return self::SUCCESS;
    }
}
