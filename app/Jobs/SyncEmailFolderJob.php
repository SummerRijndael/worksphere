<?php

namespace App\Jobs;

use App\Contracts\EmailProviderAdapter;
use App\Enums\EmailSyncStatus;
use App\Models\EmailAccount;
use App\Models\EmailSyncLog;
use App\Services\EmailAdapters\AdapterFactory;
use App\Services\EmailSyncService;
use Illuminate\Bus\Batchable;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job to sync a single folder during Phase 2 (sequential full sync).
 *
 * Uses provider-specific adapters to handle differences between Gmail, Outlook,
 * and custom IMAP servers.
 */
class SyncEmailFolderJob implements ShouldQueue
{
    use Batchable, Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $tries = 3;

    public int $timeout = 300;

    protected ?EmailProviderAdapter $adapter = null;

    public function __construct(
        public int $accountId,
        public string $folder
    ) {
        $this->onQueue(config('email.jobs.sync.queue', 'emails'));
    }

    public function retryUntil(): \DateTime
    {
        return now()->addHours(24);
    }

    public function handle(EmailSyncService $syncService): void
    {
        $account = EmailAccount::find($this->accountId);

        if (! $account) {
            Log::warning('[SyncEmailFolderJob] Account not found', ['account_id' => $this->accountId]);

            return;
        }

        if ($account->sync_status !== EmailSyncStatus::Syncing) {
            Log::info('[SyncEmailFolderJob] Account not in syncing status, skipping', [
                'account_id' => $this->accountId,
                'status' => $account->sync_status->value,
            ]);

            return;
        }

        try {
            // Get provider-specific adapter
            $this->adapter = AdapterFactory::make($account);

            $startTime = microtime(true);
            $chunkSize = config('email.chunk_size', 100);
            $cursor = $account->sync_cursor ?? [];
            $folderData = $cursor['folders'][$this->folder] ?? ['synced' => 0, 'total' => 0];
            $offset = $folderData['synced'] ?? 0;

            // Get folder status using agnostic method
            $status = $this->adapter->getFolderStatus($account, $this->folder);
            $totalMessages = $status['exists'] ?? 0;

            // If we've already synced all, move to next folder
            if ($offset >= $totalMessages) {
                Log::info('[SyncEmailFolderJob] Folder already synced', [
                    'account_id' => $this->accountId,
                    'folder' => $this->folder,
                ]);

                $syncService->updateSyncCursor($account, $this->folder, $totalMessages, $totalMessages);
                $syncService->continueSync($account);

                return;
            }

            // Fetch next chunk using adapter (parsed to array)
            // Use fetchBody=false for headers-first sync
            $messages = $this->adapter->fetchMessages($account, $this->folder, $offset, $chunkSize, false);

            $fetchedCount = 0;
            foreach ($messages as $emailData) {
                // Skip if already exists (by imap_uid)
                $exists = $account->emails()
                    ->where('imap_uid', $emailData['imap_uid'] ?? null)
                    ->where('folder', $this->folder)
                    ->exists();

                if (! $exists) {
                    try {
                        $syncService->storeEmail($account, $emailData, $this->folder, false);
                        $fetchedCount++;
                    } catch (\Throwable $e) {
                        Log::warning('[SyncEmailFolderJob] Failed to store email', [
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            $newSynced = min($offset + $chunkSize, $totalMessages);
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            // Log
            EmailSyncLog::logChunkCompleted($account->id, $this->folder, $offset, $fetchedCount, $durationMs);

            $syncService->updateSyncCursor($account, $this->folder, $newSynced, $totalMessages);

            if ($newSynced < $totalMessages) {
                // Continue with next chunk
                self::dispatch($this->accountId, $this->folder)
                    ->delay(now()->addSeconds(2));
            } else {
                // Move to next folder
                $syncService->continueSync($account);
            }
        } catch (\Throwable $e) {
            Log::error('[SyncEmailFolderJob] Sync failed', [
                'account_id' => $this->accountId,
                'folder' => $this->folder,
                'error' => $e->getMessage(),
            ]);

            if (isset($account)) {
                $syncService->markSyncFailed($account, $e->getMessage());
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[SyncEmailFolderJob] Job failed', [
            'account_id' => $this->accountId,
            'folder' => $this->folder,
            'error' => $exception->getMessage(),
        ]);
    }

    public function tags(): array
    {
        return ['email', 'sync', 'folder:'.$this->folder, 'account:'.$this->accountId];
    }
}
