<?php

namespace App\Jobs;

use App\Enums\EmailSyncStatus;
use App\Models\EmailAccount;
use App\Models\EmailSyncLog;
use App\Services\EmailAdapters\AdapterFactory;
use App\Services\EmailSyncService;
use App\Support\EmailSyncLogger as Log;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Backfill Crawler - Fetches historical emails (UID < backfill_uid_cursor).
 *
 * This job runs in the background with low priority, syncing older emails
 * in chunks. It self-dispatches for the next batch until backfill is complete.
 * The forward crawler runs independently, so users can see new emails immediately.
 */
class BackfillEmailsJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 5;

    public int $timeout = 420;

    /**
     * Exponential backoff for retries.
     * Starts at 60s, then 5m, 10m, 20m.
     */
    public function backoff(): array
    {
        return [60, 300, 600, 1200];
    }

    /**
     * The number of seconds after which the job's unique lock will be released.
     */
    public int $uniqueFor = 300;

    /**
     * Number of emails to fetch per batch.
     */
    protected int $batchSize = 50;

    public function __construct(
        public int $accountId,
        public ?string $folderType = null
    ) {
        $this->onQueue('emails-backfill');
    }

    /**
     * The unique ID of the job.
     */
    public function uniqueId(): string
    {
        return 'backfill-'.$this->accountId.'-'.($this->folderType ?? 'all');
    }

    public function handle(EmailSyncService $syncService): void
    {
        $account = EmailAccount::find($this->accountId);

        if (! $account) {
            Log::warning('[BackfillEmailsJob] Account not found', ['account_id' => $this->accountId]);

            return;
        }

        Log::info('[BackfillEmailsJob] Starting backfill job', [
            'account_id' => $this->accountId,
            'email' => $account->email,
            'backfill_cursor' => $account->backfill_uid_cursor,
            'forward_cursor' => $account->forward_uid_cursor,
            'backfill_complete' => $account->backfill_complete,
        ]);

        // Check if backfill can run
        if (! $account->canRunBackfillCrawler()) {
            Log::info('[BackfillEmailsJob] Backfill complete or account not ready', [
                'account_id' => $this->accountId,
                'backfill_complete' => $account->backfill_complete,
                'sync_status' => $account->sync_status->value,
            ]);

            return;
        }

        $startTime = microtime(true);
        $totalFetched = 0;
        $folderResults = [];

        try {
            $adapter = AdapterFactory::make($account);

            // Check if backfill is supported by adapter (interface now requires it)
            $result = $adapter->backfill($account, $this->folderType, $this->batchSize, false);

            $totalFetched = $result['fetched'];
            $hasMoreToFetch = $result['has_more'];
            $folderResults = $result['details'] ?? [];
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            // Update backfill timestamp
            $account->update(['last_backfill_at' => now()]);

            // Refresh account to check latest cursor
            $account->refresh();

            EmailSyncLog::create([
                'email_account_id' => $account->id,
                'action' => 'backfill_batch',
                'details' => [
                    'fetched_count' => $totalFetched,
                    'duration_ms' => $durationMs,
                    'has_more' => $hasMoreToFetch,
                    'folder_results' => $folderResults,
                    'cursor_after' => $result['new_cursor'] ?? $account->backfill_uid_cursor,
                ],
            ]);

            Log::info('[BackfillEmailsJob] Batch completed', [
                'account_id' => $this->accountId,
                'fetched' => $totalFetched,
                'duration_ms' => $durationMs,
                'has_more' => $hasMoreToFetch,
            ]);

            // Check if backfill is complete
            if ($hasMoreToFetch && ! $account->backfill_complete) {
                Log::info('[BackfillEmailsJob] Dispatching next batch', [
                    'account_id' => $this->accountId,
                    'delay_seconds' => 5,
                ]);

                // Self-dispatch for next batch with delay
                self::dispatch($this->accountId, $this->folderType)
                    ->delay(now()->addSeconds(5));
            } elseif (! $hasMoreToFetch) {
                // Mark backfill as complete
                Log::info('[BackfillEmailsJob] No more to fetch, marking complete', [
                    'account_id' => $this->accountId,
                ]);
                $this->markBackfillComplete($account);
            }
        } catch (\Throwable $e) {
            Log::error('[BackfillEmailsJob] Job failed with exception', [
                'account_id' => $this->accountId,
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            throw $e;
        }
    }

    /**
     * Mark backfill as complete and update account status.
     */
    protected function markBackfillComplete(EmailAccount $account): void
    {
        $account->update([
            'backfill_complete' => true,
            'sync_status' => EmailSyncStatus::Completed,
            'initial_sync_completed_at' => $account->initial_sync_completed_at ?? now(),
        ]);

        EmailSyncLog::create([
            'email_account_id' => $account->id,
            'action' => 'backfill_completed',
            'details' => [
                'completed_at' => now()->toIso8601String(),
                'final_cursor' => $account->backfill_uid_cursor,
                'total_emails' => $account->emails()->count(),
            ],
        ]);

        Log::info('[BackfillEmailsJob] Backfill complete', [
            'account_id' => $account->id,
            'total_emails' => $account->emails()->count(),
        ]);

        // Dispatch sync status changed event
        \App\Events\Email\SyncStatusChanged::dispatch(
            $account,
            EmailSyncStatus::Completed->value
        );
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[BackfillEmailsJob] Job permanently failed', [
            'account_id' => $this->accountId,
            'error' => $exception->getMessage(),
            'trace' => $exception->getTraceAsString(),
        ]);

        EmailSyncLog::create([
            'email_account_id' => $this->accountId,
            'action' => 'backfill_failed',
            'details' => [
                'error' => $exception->getMessage(),
                'failed_at' => now()->toIso8601String(),
            ],
        ]);
    }

    public function tags(): array
    {
        return ['email', 'backfill-crawler', 'account:'.$this->accountId];
    }

    /**
     * Get the middleware the job should pass through.
     */
    public function middleware(): array
    {
        return [new \App\Jobs\Middleware\LogMemoryUsage];
    }
}
