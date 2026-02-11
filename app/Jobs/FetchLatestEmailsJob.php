<?php

namespace App\Jobs;

use App\Enums\EmailFolderType;
use App\Models\Email;
use App\Models\EmailAccount;
use App\Models\EmailSyncLog;
use App\Services\EmailAdapters\AdapterFactory;
use App\Services\EmailSyncService;
use App\Support\EmailSyncLogger as Log;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldBeUnique;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

/**
 * Forward Crawler - Fetches newest emails (UID > forward_uid_cursor).
 *
 * This job runs frequently (every 2 minutes) and fetches new emails
 * that arrived since the last check. It can run during seeding, syncing,
 * or completed status - allowing users to see new emails immediately.
 */
class FetchLatestEmailsJob implements ShouldBeUnique, ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    public int $tries = 5;

    public int $timeout = 180; // 3 minutes for bootstrap/large batches

    public int $maxExceptions = 3;

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
    public int $uniqueFor = 600;

    public function __construct(
        public int $accountId
    ) {
        $this->onQueue('emails-live');
    }

    /**
     * The unique ID of the job.
     */
    public function uniqueId(): string
    {
        return 'fetch-latest-'.$this->accountId;
    }

    public function handle(EmailSyncService $syncService): void
    {
        $startTime = microtime(true);
        $account = EmailAccount::find($this->accountId);

        if (! $account) {
            Log::warning('[FetchLatestEmailsJob] Account not found', ['account_id' => $this->accountId]);

            return;
        }

        Log::info('[FetchLatestEmailsJob] Starting forward sync', [
            'account_id' => $this->accountId,
            'email' => $account->email,
            'forward_cursor' => $account->forward_uid_cursor,
        ]);

        // Check if forward crawler can run (active, verified, and in valid status)
        if (! $account->canRunForwardCrawler()) {
            Log::debug('[FetchLatestEmailsJob] Account not ready for forward sync', [
                'account_id' => $this->accountId,
                'status' => $account->sync_status->value,
            ]);

            return;
        }

        $totalFetched = 0;

        try {
            if ($account->provider === 'gmail') {
                $totalFetched = $syncService->syncIncrementalUpdates($account);

                // Also ensure watch is active
                $this->ensureWatchIsActive($account);
            } else {
                $adapter = AdapterFactory::make($account);
                $client = $adapter->createClient($account, false);
                $client->connect();

                // [CRITICAL FIX] Fetch from all subscribed folders for forward sync.
                $disabledFolders = $account->disabled_folders ?? [];
                $folders = collect(EmailFolderType::cases())->filter(function ($type) use ($disabledFolders) {
                    return ! in_array($type->value, $disabledFolders);
                });

                foreach ($folders as $folderType) {
                    $fetched = $this->fetchForwardForFolder(
                        $client,
                        $adapter,
                        $account,
                        $folderType,
                        $syncService
                    );
                    $totalFetched += $fetched;
                }

                $client->disconnect();
            }

            // Update forward sync timestamp
            $account->update(['last_forward_sync_at' => now()]);

            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            if ($totalFetched > 0) {
                EmailSyncLog::create([
                    'email_account_id' => $account->id,
                    'action' => 'forward_fetch',
                    'details' => [
                        'fetched_count' => $totalFetched,
                        'duration_ms' => $durationMs,
                    ],
                ]);

                Log::info('[FetchLatestEmailsJob] Fetched new emails', [
                    'account_id' => $this->accountId,
                    'count' => $totalFetched,
                    'duration_ms' => $durationMs,
                ]);
            }
        } catch (\Throwable $e) {
            $message = $e->getMessage();
            $isRateLimit = str_contains(strtolower($message), 'rate limit') ||
                           str_contains(strtolower($message), 'quota') ||
                           str_contains(strtolower($message), 'too many requests');

            if ($isRateLimit) {
                Log::warning('[FetchLatestEmailsJob] Rate limit detected, failing to trigger backoff', [
                    'account_id' => $this->accountId,
                    'error' => $message,
                ]);
                throw $e; // Throw to trigger exponential backoff
            }

            Log::error('[FetchLatestEmailsJob] Sync failed', [
                'account_id' => $this->accountId,
                'error' => $message,
            ]);

            $account->update(['sync_error' => substr($message, 0, 255)]);
        }
    }

    /**
     * Fetch new emails for a specific folder (forward direction).
     */
    protected function fetchForwardForFolder(
        \Webklex\PHPIMAP\Client $client,
        $adapter,
        EmailAccount $account,
        EmailFolderType $folderType,
        EmailSyncService $syncService
    ): int {
        $folderName = $adapter->getFolderName($folderType->value);
        $folder = $client->getFolder($folderName);

        if (! $folder) {
            return 0;
        }

        try {
            // Get the current forward cursor for this account
            $forwardCursor = $account->forward_uid_cursor ?? 0;

            // Get folder info
            $examine = $folder->examine();
            $uidNext = $examine['uidnext'] ?? 0;

            // Check for UID reset (Server UIDNEXT < DB Cursor)
            // This happens if the mailbox was recreated or UIDVALIDITY changed
            if ($uidNext > 0 && $forwardCursor > 0 && $uidNext < $forwardCursor) {
                Log::warning('[FetchLatestEmailsJob] UID Mismatch detected. Resetting cursors.', [
                    'account_id' => $account->id,
                    'uidnext' => $uidNext,
                    'forward_cursor' => $forwardCursor,
                ]);

                $account->update([
                    'forward_uid_cursor' => 0,
                    'backfill_uid_cursor' => 0,
                    'backfill_complete' => false,
                ]);
                $forwardCursor = 0; // Proceed to bootstrap
            }

            // If cursor is 0, this is first run - set cursor to latest UID
            if ($forwardCursor === 0) {
                // Fetch latest UIDs (max 20) to bootstrap quickly
                // Backfill will handle the rest
                $uids = $adapter->fetchLatestUids($folder, 20);

                $fetched = 0;
                $maxUid = 0;

                foreach ($uids as $uid) {
                    $maxUid = max($maxUid, $uid);

                    try {
                        // Check if already exists
                        $exists = Email::where('email_account_id', $account->id)
                            ->where('imap_uid', $uid)
                            ->where('folder', $folderType->value)
                            ->exists();

                        if (! $exists) {
                            $message = $adapter->getMessageByUid($folder, $uid);
                            if ($message) {
                                // delegate parsing to adapter (handles X-GM-LABELS for Gmail)
                                // [Lazy Sync] Set skipAttachments to true, fetchBody to false
                                $emailData = $adapter->parseMessage($message, true, false);

                                // valid folder is either what adapter detected (from labels) or the current folder we are syncing
                                $targetFolder = $emailData['folder'] ?? $folderType->value;

                                $syncService->storeEmail($account, $emailData, $targetFolder);
                                $fetched++;
                            }
                        }
                    } catch (\Throwable $e) {
                        Log::warning('[FetchLatestEmailsJob] Failed to fetch bootstrap UID', [
                            'uid' => $uid,
                            'error' => $e->getMessage(),
                        ]);
                    }
                }

                // Update forward cursor to the highest UID we've seen
                if ($maxUid > 0) {
                    $account->update(['forward_uid_cursor' => $maxUid]);
                }

                return $fetched;
            }

            // Normal forward fetch: get messages > forwardCursor
            if ($uidNext <= $forwardCursor + 1) {
                // No new messages
                return 0;
            }

            // Fetch UIDs greater than cursor with a limit to prevent memory spikes
            // We fetch a batch (e.g. 500) to ensure we don't load 50k headers at once if the gap is huge.
            $batchSize = config('email.fetch_batch_size', 500);
            $rangeEnd = $forwardCursor + $batchSize;

            // If we know the max UID, we can clamp it
            if ($uidNext > 0) {
                $rangeEnd = min($rangeEnd, $uidNext);
            }

            $range = ($forwardCursor + 1).':'.$rangeEnd;
            $overview = $folder->overview($range);

            $newUids = [];
            foreach ($overview as $item) {
                $uid = $adapter->extractUidFromOverview($item);
                if ($uid && $uid > $forwardCursor) {
                    $newUids[] = $uid;
                }
            }

            if (empty($newUids)) {
                return 0;
            }

            // Limit to 100 per batch
            $newUids = array_slice($newUids, 0, 100);

            $fetched = 0;
            $maxUid = $forwardCursor;

            foreach ($newUids as $uid) {
                try {
                    // Check if already exists
                    $exists = Email::where('email_account_id', $account->id)
                        ->where('imap_uid', $uid)
                        ->where('folder', $folderType->value)
                        ->exists();

                    if ($exists) {
                        $maxUid = max($maxUid, $uid);

                        continue;
                    }

                    $message = $adapter->getMessageByUid($folder, $uid);
                    if ($message) {
                        // delegate parsing to adapter (handles X-GM-LABELS for Gmail)
                        // [Lazy Sync] Set skipAttachments to true, fetchBody to false
                        $emailData = $adapter->parseMessage($message, true, false);

                        // valid folder is either what adapter detected (from labels) or the current folder we are syncing
                        $targetFolder = $emailData['folder'] ?? $folderType->value;

                        $syncService->storeEmail($account, $emailData, $targetFolder);
                        $fetched++;
                        $maxUid = max($maxUid, $uid);
                    }
                } catch (\Throwable $e) {
                    Log::warning('[FetchLatestEmailsJob] Failed to fetch UID', [
                        'uid' => $uid,
                        'error' => $e->getMessage(),
                    ]);
                }
            }

            // Update forward cursor
            if ($maxUid > $forwardCursor) {
                $account->update(['forward_uid_cursor' => $maxUid]);
            }

            return $fetched;
        } catch (\Throwable $e) {
            Log::warning('[FetchLatestEmailsJob] Folder fetch failed', [
                'folder' => $folderType->value,
                'error' => $e->getMessage(),
            ]);

            return 0;
        }
    }

    /**
     * Ensure Gmail watch is active (expires every 7 days).
     * We renew it every 24 hours to be safe.
     */
    protected function ensureWatchIsActive(EmailAccount $account): void
    {
        $cursor = $account->sync_cursor ?? [];
        $lastWatchAt = $cursor['last_watch_at'] ?? null;

        if (! $lastWatchAt || now()->parse($lastWatchAt)->diffInHours(now()) >= 24) {
            $adapter = AdapterFactory::make($account);
            if ($adapter->subscribeToNotifications($account)) {
                $cursor = $account->sync_cursor ?? [];
                $cursor['last_watch_at'] = now()->toIso8601String();
                $account->update(['sync_cursor' => $cursor]);

                Log::info('[FetchLatestEmailsJob] Renewed Gmail watch', ['account_id' => $account->id]);
            }
        }
    }

    public function failed(\Throwable $exception): void
    {
        Log::error('[FetchLatestEmailsJob] Job failed', [
            'account_id' => $this->accountId,
            'error' => $exception->getMessage(),
        ]);
    }

    public function tags(): array
    {
        return ['email', 'forward-crawler', 'account:'.$this->accountId];
    }

    /**
     * Get the middleware the job should pass through.
     */
    public function middleware(): array
    {
        return [new \App\Jobs\Middleware\LogMemoryUsage];
    }
}
