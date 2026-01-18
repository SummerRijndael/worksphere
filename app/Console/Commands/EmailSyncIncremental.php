<?php

namespace App\Console\Commands;

use App\Jobs\FetchNewEmailsJob;
use App\Services\EmailSyncService;
use Illuminate\Console\Command;

class EmailSyncIncremental extends Command
{
    protected $signature = 'email:sync-incremental';

    protected $description = 'Dispatch incremental sync jobs for fully synced accounts';

    public function handle(EmailSyncService $syncService): int
    {
        $accounts = $syncService->getAccountsForIncrementalSync();

        if ($accounts->isEmpty()) {
            $this->line('No accounts ready for incremental sync.');

            return self::SUCCESS;
        }

        $this->info("Dispatching incremental sync for {$accounts->count()} account(s).");

        foreach ($accounts as $account) {
            FetchNewEmailsJob::dispatch($account->id);
            $this->line("  → Queued: {$account->email}");
        }

        return self::SUCCESS;
    }
}
