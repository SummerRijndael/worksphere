<?php

namespace App\Console\Commands;

use App\Services\EmailSyncService;
use Illuminate\Console\Command;

class SyncEmailAccounts extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'email:sync';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Dispatch jobs to sync email accounts (incremental and full)';

    /**
     * Execute the console command.
     */
    public function handle(EmailSyncService $syncService)
    {
        $this->info('Starting email sync check...');

        // 1. Full Sync / Seeding
        $acountsNeedingSync = $syncService->getAccountsNeedingSync();
        foreach ($acountsNeedingSync as $account) {
            if ($account->needs_reauth) {
                // Skip accounts that need re-authentication
                $this->warn("Skipping account {$account->email} (needs re-auth)");

                continue;
            }

            if ($account->sync_status->value === 'pending') {
                $this->info("Starting seed for {$account->email}");
                $syncService->startSeed($account);
            } elseif ($account->sync_status->value === 'seeding' || $account->sync_status->value === 'syncing') {
                $this->info("Continuing sync for {$account->email}");
                $syncService->continueSync($account);
            }
        }

        // 2. Incremental Sync (New Emails)
        $accountsForIncremental = $syncService->getAccountsForIncrementalSync();
        foreach ($accountsForIncremental as $account) {
            if ($account->needs_reauth) {
                continue;
            }

            $this->info("Fetching new emails for {$account->email}");
            $syncService->fetchNewEmails($account);
        }

        $this->info('Email sync check completed.');
    }
}
