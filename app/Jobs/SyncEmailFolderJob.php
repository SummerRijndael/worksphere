<?php

namespace App\Jobs;

use App\Contracts\EmailProviderAdapter;
use App\Enums\EmailSyncStatus;
use App\Models\EmailAccount;
use App\Models\EmailSyncLog;
use App\Services\EmailAdapters\AdapterFactory;
use App\Services\EmailSyncService;
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
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

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

        // Get provider-specific adapter
        $this->adapter = AdapterFactory::make($account);

        $startTime = microtime(true);
        $chunkSize = config('email.chunk_size', 100);
        $cursor = $account->sync_cursor ?? [];
        $folderData = $cursor['folders'][$this->folder] ?? ['synced' => 0, 'total' => 0];
        $offset = $folderData['synced'] ?? 0;

        try {
            // Refresh token if needed (for OAuth providers)
            if (! $this->adapter->refreshTokenIfNeeded($account)) {
                throw new \RuntimeException('Failed to refresh OAuth token');
            }

            // Create and connect client using adapter
            $client = $this->adapter->createClient($account);
            $client->connect();

            $imapFolderName = $this->adapter->getFolderName($this->folder);
            $folder = $client->getFolder($imapFolderName);

            if (! $folder) {
                Log::warning('[SyncEmailFolderJob] Folder not found', [
                    'account_id' => $this->accountId,
                    'folder' => $imapFolderName,
                ]);

                // Skip to next folder
                $syncService->updateSyncCursor($account, $this->folder, 0, 0);
                $syncService->continueSync($account);

                return;
            }

            $totalMessages = $folder->examine()['exists'] ?? 0;

            // If we've already synced all, move to next folder
            if ($offset >= $totalMessages) {
                Log::info('[SyncEmailFolderJob] Folder already synced', [
                    'account_id' => $this->accountId,
                    'folder' => $this->folder,
                ]);

                $syncService->continueSync($account);

                return;
            }

            // Fetch next chunk using adapter
            $start = $offset + 1;
            $end = min($offset + $chunkSize, $totalMessages);

            $uidsToFetch = $this->adapter->fetchUidRange($folder, $start, $end);

            $messages = collect();
            if (! empty($uidsToFetch)) {
                // Fetch messages individually to handle library limitations and avoid "BAD command" errors
                foreach ($uidsToFetch as $uid) {
                    try {
                        $msg = $folder->query()->getMessageByUid($uid);
                        if ($msg) {
                            $messages->push($msg);
                        }
                    } catch (\Throwable $e) {
                        // One bad UID shouldn't fail the whole chunk
                        Log::warning("[SyncEmailFolderJob] Failed to fetch UID {$uid}", ['error' => $e->getMessage()]);
                    }
                }
            } else {
                // Fallback query
                try {
                    $messages = $folder->query()
                        ->limit($chunkSize)
                        ->setOffset($offset)
                        ->get();
                } catch (\Throwable $e) {
                    Log::error('[SyncEmailFolderJob] Fallback query failed', ['error' => $e->getMessage()]);
                    $messages = collect([]);
                }
            }

            $fetchedCount = 0;
            foreach ($messages as $message) {
                // Skip if already exists (by imap_uid)
                $exists = $account->emails()
                    ->where('imap_uid', $message->getUid())
                    ->where('folder', $this->folder)
                    ->exists();

                if (! $exists) {
                    try {
                        $emailData = $this->parseMessage($message);
                        $syncService->storeEmailFromImap($account, $emailData, $this->folder);
                        $fetchedCount++;
                    } catch (\Throwable $e) {
                        Log::warning('[SyncEmailFolderJob] Failed to store email', [
                            'error' => $e->getMessage(),
                        ]);
                    }
                }
            }

            $client->disconnect();

            $newSynced = min($offset + $chunkSize, $totalMessages);
            $durationMs = (int) ((microtime(true) - $startTime) * 1000);

            // Update cursor
            $syncService->updateSyncCursor($account, $this->folder, $newSynced, $totalMessages);

            // Log
            EmailSyncLog::logChunkCompleted($account->id, $this->folder, $offset, $fetchedCount, $durationMs);

            Log::info('[SyncEmailFolderJob] Chunk completed', [
                'account_id' => $this->accountId,
                'folder' => $this->folder,
                'offset' => $offset,
                'fetched' => $fetchedCount,
                'progress' => "{$newSynced}/{$totalMessages}",
            ]);

            // Check if folder is complete
            if ($newSynced >= $totalMessages) {
                // Move to next folder
                $syncService->continueSync($account);
            } else {
                // Continue with next chunk
                self::dispatch($this->accountId, $this->folder)
                    ->delay(now()->addSeconds(2));
            }
        } catch (\Throwable $e) {
            Log::error('[SyncEmailFolderJob] Sync failed', [
                'account_id' => $this->accountId,
                'folder' => $this->folder,
                'error' => $e->getMessage(),
            ]);

            EmailSyncLog::logError($account->id, $e->getMessage(), $this->folder);

            throw $e;
        }
    }

    /**
     * Parse IMAP message to email data array.
     */
    protected function parseMessage($message): array
    {
        $from = $message->getFrom()[0] ?? null;

        $fromEmail = 'unknown@unknown.com';
        $fromName = null;

        if ($from && is_object($from)) {
            $fromEmail = $from->mail ?? 'unknown@unknown.com';
            $fromName = $from->personal ?? null;
        } elseif (is_string($from)) {
            $fromEmail = $from;
        }

        $textBody = $message->getTextBody() ?? '';
        $preview = \Illuminate\Support\Str::limit(trim(preg_replace('/\s+/', ' ', $textBody)), 200);

        return [
            'message_id' => $message->getMessageId()?->first(),
            'from_email' => $fromEmail,
            'from_name' => $fromName ?? $fromEmail,
            'to' => $this->formatRecipients($message->getTo()),
            'cc' => $this->formatRecipients($message->getCc()),
            'bcc' => [],
            'subject' => $message->getSubject()?->first() ?? '(No Subject)',
            'preview' => $preview,
            'body_html' => $message->getHTMLBody(),
            'body_plain' => $textBody,
            'is_read' => $message->getFlags()->contains('Seen'),
            'is_starred' => $message->getFlags()->contains('Flagged'),
            'has_attachments' => $message->hasAttachments(),
            'imap_uid' => $message->getUid(),
            'date' => $message->getDate()?->first()?->toDate(),
        ];
    }

    /**
     * Format recipients to array of [name, email].
     */
    protected function formatRecipients($attribute): array
    {
        if (! $attribute || ! method_exists($attribute, 'toArray')) {
            return [];
        }

        $flattened = [];
        foreach ($attribute->toArray() as $recipient) {
            $flattened[] = [
                'name' => $recipient->personal ?? null,
                'email' => $recipient->mail ?? '',
            ];
        }

        return $flattened;
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
