<?php

namespace App\Services;

use App\Contracts\EmailSyncServiceContract;
use App\Enums\AuditAction;
use App\Enums\AuditCategory;
use App\Enums\EmailFolderType;
use App\Enums\EmailSyncStatus;
use App\Events\Email\EmailReceived;
use App\Events\Email\SyncStatusChanged;
use App\Jobs\BackfillEmailsJob;
use App\Jobs\FetchLatestEmailsJob;
use App\Jobs\SyncEmailFolderJob;
use App\Models\Email;
use App\Models\EmailAccount;
use App\Models\EmailSyncLog;
use App\Services\EmailAdapters\AdapterFactory;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Bus;

class EmailSyncService implements EmailSyncServiceContract
{
    /**
     * Start initial seed for an account using dual-crawler architecture.
     *
     * This dispatches both the forward crawler (for new emails) and
     * backfill crawler (for historical emails) to run in parallel.
     */
    public function startSeed(EmailAccount $account): void
    {
        // Update status and initialize cursors
        $account->update([
            'sync_status' => EmailSyncStatus::Syncing,
            'sync_cursor' => $this->initializeSyncCursor(),
            'sync_error' => null,
            'sync_started_at' => now(),
            'forward_uid_cursor' => null,
            'backfill_uid_cursor' => null,
            'backfill_complete' => false,
        ]);

        // Log sync start
        EmailSyncLog::create([
            'email_account_id' => $account->id,
            'action' => 'dual_sync_started',
            'details' => [
                'started_at' => now()->toIso8601String(),
                'folders' => array_map(fn ($f) => $f->value, EmailFolderType::priorityFolders()),
            ],
        ]);

        // Initialize the batch for tracking
        $batch = Bus::batch([
            new FetchLatestEmailsJob($account->id),
            (new BackfillEmailsJob($account->id))->delay(now()->addSeconds(10)),
        ])->name('EmailSync:'.$account->email)
          ->finally(function ($batch) use ($account) {
              Log::info('[EmailSync] Initial sync batch finished', ['account_id' => $account->id]);
          })->dispatch();

        $account->update(['last_checkpoint_id' => $batch->id]);

        SyncStatusChanged::dispatch(
            $account,
            EmailSyncStatus::Syncing->value
        );

        Log::info('[EmailSync] Started batched dual-crawler sync', [
            'account_id' => $account->id,
            'batch_id' => $batch->id
        ]);
    }

    /**
     * Continue full sync for an account (Phase 2).
     */
    public function continueSync(EmailAccount $account): void
    {
        $cursor = $account->sync_cursor ?? [];
        $folders = $cursor['folders'] ?? [];

        // Find next folder that needs syncing
        $nextFolder = null;
        $disabledFolders = $account->disabled_folders ?? [];

        foreach (EmailFolderType::syncOrder() as $folderType) {
            $folderKey = $folderType->value;

            // Skip if folder is disabled by user
            if (in_array($folderKey, $disabledFolders)) {
                continue;
            }

            $folderData = $folders[$folderKey] ?? [];

            // Skip if completed
            if (($folderData['synced'] ?? 0) >= ($folderData['total'] ?? 0) && isset($folderData['total'])) {
                continue;
            }

            $nextFolder = $folderType;
            break;
        }

        if (! $nextFolder) {
            // All folders complete
            $this->markSyncCompleted($account);

            return;
        }

        // Dispatch sync job for this folder
        SyncEmailFolderJob::dispatch($account->id, $nextFolder->value);
    }

    /**
     * Fetch new emails using the forward crawler.
     * Now works during any sync status (seeding, syncing, completed).
     */
    public function fetchNewEmails(EmailAccount $account): int
    {
        // Forward crawler can run during any active sync status
        if (! $account->canRunForwardCrawler()) {
            return 0;
        }

        if ($account->provider === 'gmail') {
            return $this->syncIncrementalUpdates($account);
        }

        // Dispatch forward crawler job
        FetchLatestEmailsJob::dispatch($account->id);

        return 0; // Actual count returned via job
    }

    /**
     * Get sync progress for an account (dual-crawler aware).
     */
    public function getSyncProgress(EmailAccount $account): array
    {
        // Calculate progress based on dual cursors
        $forwardCursor = $account->forward_uid_cursor ?? 0;
        $backfillCursor = $account->backfill_uid_cursor ?? 0;
        $backfillComplete = $account->backfill_complete ?? false;

        // Legacy cursor support
        $cursor = $account->sync_cursor ?? [];
        $folders = $cursor['folders'] ?? [];
        $phase = $cursor['phase'] ?? 'pending';

        // Calculate folder progress for legacy display
        $totalEmails = 0;
        $syncedEmails = 0;
        $folderProgress = [];

        foreach ($folders as $folder => $data) {
            $total = $data['total'] ?? 0;
            $synced = $data['synced'] ?? 0;
            $totalEmails += $total;
            $syncedEmails += $synced;

            $folderProgress[$folder] = [
                'total' => $total,
                'synced' => $synced,
                'percent' => $total > 0 ? round(($synced / $total) * 100) : 0,
            ];
        }

        $overallPercent = $account->getSyncProgressPercent();

        return [
            'status' => $account->sync_status->value,
            'phase' => $backfillComplete ? 'completed' : 'syncing',
            'folders' => $folderProgress,
            'overall_percent' => $overallPercent,
            'total_emails' => $totalEmails,
            'synced_emails' => $syncedEmails,
            // Dual-crawler specific fields
            'forward_cursor' => $forwardCursor,
            'backfill_cursor' => $backfillCursor,
            'backfill_complete' => $backfillComplete,
            'can_use_email' => $account->hasEmailsReady(),
            'last_forward_sync' => $account->last_forward_sync_at?->toIso8601String(),
            'last_backfill' => $account->last_backfill_at?->toIso8601String(),
        ];
    }

    /**
     * Mark sync as completed for an account.
     */
    public function markSyncCompleted(EmailAccount $account): void
    {
        $account->update([
            'sync_status' => EmailSyncStatus::Completed,
            'initial_sync_completed_at' => now(),
            'last_sync_at' => now(),
            'sync_error' => null,
        ]);

        EmailSyncLog::create([
            'email_account_id' => $account->id,
            'action' => EmailSyncLog::ACTION_SYNC_COMPLETED,
            'details' => ['completed_at' => now()->toIso8601String()],
        ]);

        Log::info('[EmailSync] Full sync completed', ['account_id' => $account->id]);

        SyncStatusChanged::dispatch(
            $account,
            EmailSyncStatus::Completed->value
        );
    }

    /**
     * Mark sync as failed for an account.
     */
    public function markSyncFailed(EmailAccount $account, string $error): void
    {
        $account->update([
            'sync_status' => EmailSyncStatus::Failed,
            'sync_error' => $error,
        ]);

        EmailSyncLog::logError($account->id, $error);

        Log::error('[EmailSync] Sync failed', [
            'account_id' => $account->id,
            'error' => $error,
        ]);

        SyncStatusChanged::dispatch(
            $account,
            EmailSyncStatus::Failed->value,
            $error
        );
    }

    /**
     * Get accounts that need sync.
     */
    public function getAccountsNeedingSync(): Collection
    {
        return EmailAccount::query()
            ->active()
            ->verified()
            ->needsSync()
            ->get();
    }

    /**
     * Rescue stuck sync jobs (Watchdog).
     * Checks for accounts that are supposed to be syncing but have no recent activity.
     */
    public function rescueStuckAccounts(): void
    {
        // 1. Rescue Stuck Backfills
        // Accounts that are active, verified, backfill NOT complete, but haven't updated 'last_backfill_at' in 15 mins
        $stuckBackfills = EmailAccount::query()
            ->active()
            ->verified()
            ->where('backfill_complete', false)
            ->where(function ($q) {
                $q->where('last_backfill_at', '<', now()->subMinutes(15))
                    ->orWhereNull('last_backfill_at');
            })
            // Only rescue if we actually started syncing recently (don't rescue ancient abandoned accounts? or do?)
            // Let's rely on is_active = true
            ->get();

        foreach ($stuckBackfills as $account) {
            /** @var \App\Models\EmailAccount $account */
            // Check if job is actually queued? It's hard to check Redis from here reliably without overhead.
            // We just assume if DB timestamp is old, the job is dead or stuck.

            Log::warning('[EmailSyncWatchdog] Rescuing stuck backfill', [
                'account_id' => $account->id,
                'last_backfill_at' => $account->last_backfill_at,
            ]);

            BackfillEmailsJob::dispatch($account->id);

            // Update timestamp to prevent immediate re-dispatch next minute if queue is slow
            $account->update(['last_backfill_at' => now()]);
        }

        // 2. Rescue Stuck Forward Syncs
        // Accounts that are active, verified, but haven't updated 'last_forward_sync_at' in 10 mins
        // (Forward sync runs every 2 mins usually)
        $stuckForward = EmailAccount::query()
            ->active()
            ->verified()
            ->where(function ($q) {
                $q->where('last_forward_sync_at', '<', now()->subMinutes(10))
                    ->orWhereNull('last_forward_sync_at');
            })
            ->get();

        foreach ($stuckForward as $account) {
            /** @var \App\Models\EmailAccount $account */
            Log::warning('[EmailSyncWatchdog] Rescuing stuck forward sync', [
                'account_id' => $account->id,
                'last_forward_sync_at' => $account->last_forward_sync_at,
            ]);

            FetchLatestEmailsJob::dispatch($account->id);

            $account->update(['last_forward_sync_at' => now()]);
        }
    }

    /**
     * Fetch new emails since last sync (incremental).
     */
    public function syncIncrementalUpdates(EmailAccount $account): int
    {
        $adapter = AdapterFactory::make($account);
        $messages = $adapter->fetchIncrementalUpdates($account, false);

        $count = 0;
        foreach ($messages as $emailData) {
            $this->storeEmail($account, $emailData, $emailData['folder'] ?? EmailFolderType::Inbox->value, true);
            $count++;
        }

        return $count;
    }

    /**
     * Get accounts ready for forward sync (incremental).
     * Now works during seeding, syncing, or completed status.
     */
    public function getAccountsForIncrementalSync(): Collection
    {
        $intervalMinutes = config('email.scheduler.forward_interval', 2);

        return EmailAccount::query()
            ->active()
            ->verified()
            ->whereIn('sync_status', [
                EmailSyncStatus::Seeding,
                EmailSyncStatus::Syncing,
                EmailSyncStatus::Completed,
            ])
            ->where(function ($query) use ($intervalMinutes) {
                $query->whereNull('last_forward_sync_at')
                    ->orWhere('last_forward_sync_at', '<=', now()->subMinutes($intervalMinutes));
            })
            ->get();
    }

    /**
     * Get IMAP folder name for provider and folder type.
     */
    public function getImapFolderName(string $provider, string $folderType): string
    {
        $mappings = config("email.imap_folders.{$provider}", config('email.imap_folders.custom'));

        return $mappings[$folderType] ?? strtoupper($folderType);
    }

    /**
     * Get max parallel folder limit for provider.
     */
    public function getMaxParallelFolders(string $provider): int
    {
        return config("email.max_parallel_folders.{$provider}", 2);
    }

    /**
     * Update sync cursor after a chunk is processed.
     */
    public function updateSyncCursor(
        EmailAccount $account,
        string $folder,
        int $synced,
        ?int $total = null
    ): void {
        $cursor = $account->sync_cursor ?? ['phase' => 'seed', 'folders' => []];

        $folderData = $cursor['folders'][$folder] ?? ['synced' => 0, 'total' => 0];
        $folderData['synced'] = $synced;

        if ($total !== null) {
            $folderData['total'] = $total;
        }

        $cursor['folders'][$folder] = $folderData;

        $account->update(['sync_cursor' => $cursor]);
    }

    /**
     * Transition from seed phase to full sync phase.
     */
    public function transitionToFullSync(EmailAccount $account): void
    {
        $cursor = $account->sync_cursor ?? [];
        $cursor['phase'] = 'full';

        $account->update([
            'sync_status' => EmailSyncStatus::Syncing,
            'sync_cursor' => $cursor,
        ]);

        EmailSyncLog::create([
            'email_account_id' => $account->id,
            'action' => EmailSyncLog::ACTION_SEED_COMPLETED,
            'details' => ['transitioned_at' => now()->toIso8601String()],
        ]);

        SyncStatusChanged::dispatch(
            $account,
            EmailSyncStatus::Syncing->value
        );
    }

    /**
     * Initialize a new sync cursor.
     */
    protected function initializeSyncCursor(): array
    {
        $folders = [];

        foreach (EmailFolderType::cases() as $folder) {
            $folders[$folder->value] = [
                'total' => 0,
                'synced' => 0,
                'priority' => $folder->syncPriority(),
            ];
        }

        return [
            'phase' => 'seed',
            'folders' => $folders,
        ];
    }

    /**
     * Store fetched email with transaction and deduplication.
     *
     * Uses updateOrCreate to prevent duplicates and DB::transaction for atomicity.
     * Retries up to 3 times on deadlock.
     *
     * @param  bool  $broadcast  Whether to broadcast realtime event (false for backfill)
     */
    public function storeEmail(
        EmailAccount $account,
        array $emailData,
        string $folder,
        bool $broadcast = false
    ): Email {
        $maxRetries = 3;
        $attempt = 0;

        while ($attempt < $maxRetries) {
            try {
                return DB::transaction(function () use ($account, $emailData, $folder, $broadcast) {
                    // Get the sanitization service
                    $sanitizer = app(EmailSanitizationService::class);

                    // Store original HTML in body_raw, sanitize for body_html
                    $bodyRaw = $emailData['body_html'] ?? null;
                    $bodyHtml = $bodyRaw ? $sanitizer->sanitize($bodyRaw, $account->provider ?? 'imap') : null;

                    // Use updateOrCreate to handle duplicates gracefully
                    // [Refactor] Match by message_id to allow migration from Inbox UIDs to All Mail UIDs without duplicates
                    $matchAttributes = [
                        'email_account_id' => $account->id,
                    ];

                    if (! empty($emailData['message_id'])) {
                        $matchAttributes['message_id'] = $emailData['message_id'];
                    } elseif (! empty($emailData['gmail_id'])) {
                        $matchAttributes['provider_id'] = $emailData['gmail_id'];
                    } else {
                        // Fallback compatible with old logic
                        $matchAttributes['imap_uid'] = $emailData['imap_uid'] ?? null;
                        $matchAttributes['folder'] = $folder;
                    }

                    // [Sticky Folder Logic]
                    // If the email already exists and is in a "special" folder (inbox, sent, etc),
                    // don't let it be overwritten by 'archive'.
                    $existingEmail = null;
                    if (! empty($emailData['message_id'])) {
                        $existingEmail = Email::where('email_account_id', $account->id)
                            ->where('message_id', $emailData['message_id'])
                            ->first();
                    }

                    if (! $existingEmail && ! empty($emailData['imap_uid'])) {
                        $existingEmail = Email::where('email_account_id', $account->id)
                            ->where('imap_uid', $emailData['imap_uid'])
                            ->where('folder', $folder)
                            ->first();
                    }

                    if (! $existingEmail && ! empty($emailData['gmail_id'])) {
                        $existingEmail = Email::where('email_account_id', $account->id)
                            ->where('provider_id', $emailData['gmail_id'])
                            ->first();
                    }

                    $targetFolder = $folder;
                    if ($existingEmail && $folder === EmailFolderType::Archive->value) {
                        $currentFolder = $existingEmail->folder;
                        if (in_array($currentFolder, [
                            EmailFolderType::Inbox->value,
                            EmailFolderType::Sent->value,
                            EmailFolderType::Drafts->value,
                            EmailFolderType::Trash->value,
                            EmailFolderType::Spam->value,
                        ])) {
                            $targetFolder = $currentFolder;
                        }
                    }

                    // [Security/Stability] Sanitize text fields for MySQL utf8mb4 compatibility
                    $sanitize = function (?string $text) {
                        if ($text === null) {
                            return null;
                        }

                        // Use mb_convert_encoding with UTF-8 to UTF-8 to strip invalid bytes
                        // This is more reliable across PHP versions than iconv //IGNORE
                        $res = mb_convert_encoding($text, 'UTF-8', 'UTF-8');

                        // Strip NULL bytes which MySQL rejects in strings
                        return str_replace("\0", '', $res);
                    };

                    $threadId = $emailData['thread_id'] ?? null;

                    // [Threading Heuristic] If thread_id is missing (common for standard IMAP)
                    if (! $threadId && ! empty($emailData['headers'])) {
                        $headers = $emailData['headers'];
                        $references = $headers['references'] ?? $headers['References'] ?? '';
                        $inReplyTo = $headers['in-reply-to'] ?? $headers['In-Reply-To'] ?? '';

                        // Extract all message IDs from these headers
                        preg_match_all('/<([^>]+)>/', $references.' '.$inReplyTo, $matches);
                        $parentIds = array_unique($matches[1] ?? []);

                        if (! empty($parentIds)) {
                            // Find if any of our emails match these IDs
                            $parent = Email::whereIn('message_id', $parentIds)
                                ->where('user_id', $account->user_id)
                                ->whereNotNull('thread_id')
                                ->first();

                            if ($parent) {
                                $threadId = $parent->thread_id;
                            }
                        }

                        // Fallback: If still no thread_id, use its own message_id as thread root
                        if (! $threadId) {
                            $threadId = $emailData['message_id'] ?? null;
                        }
                    }

                    // Calculate approximate size (text + headers only)
                    $sizeBytes = 0;
                    $sizeBytes += strlen($emailData['body_html'] ?? '');
                    $sizeBytes += strlen($emailData['body_plain'] ?? '');
                    $sizeBytes += strlen(json_encode($emailData['headers'] ?? []));

                    $email = Email::updateOrCreate(
                        $matchAttributes,
                        [
                            'user_id' => $account->user_id,
                            'imap_uid' => $emailData['imap_uid'] ?? null,
                            'provider_id' => $emailData['gmail_id'] ?? null,
                            'folder' => $targetFolder,
                            'thread_id' => $threadId,
                            'from_email' => $emailData['from_email'],
                            'from_name' => $sanitize($emailData['from_name'] ?? null),
                            'to' => $emailData['to'] ?? [],
                            'cc' => $emailData['cc'] ?? [],
                            'bcc' => $emailData['bcc'] ?? [],
                            'subject' => $sanitize($emailData['subject'] ?? '(No Subject)'),
                            'preview' => $sanitize($emailData['preview'] ?? ''),
                            'body_html' => $sanitize($bodyHtml),
                            'body_plain' => $sanitize($emailData['body_plain'] ?? null),
                            'body_raw' => $sanitize($bodyRaw),
                            'headers' => $emailData['headers'] ?? [],
                            'is_read' => $emailData['is_read'] ?? false,
                            'is_starred' => $emailData['is_starred'] ?? false,
                            'is_important' => $this->determineImportance($emailData['headers'] ?? []),
                            'has_attachments' => $emailData['has_attachments'] ?? false,
                            'size_bytes' => $sizeBytes,
                            'sent_at' => $emailData['sent_at'] ?? null,
                            'received_at' => $emailData['date'] ?? now(),
                            'sanitized_at' => $bodyHtml ? now() : null,
                        ]
                    );

                    // Update history_id in cursor if provided (Gmail API)
                    if (! empty($emailData['history_id'])) {
                        $cursor = $account->sync_cursor ?? [];
                        if (empty($cursor['history_id']) || $emailData['history_id'] > ($cursor['history_id'] ?? 0)) {
                            $cursor['history_id'] = $emailData['history_id'];
                            $account->sync_cursor = $cursor;
                            $account->save();
                        }
                    }

                    $isNew = $email->wasRecentlyCreated;

                    // Store attachments if they are provided in sync data
                    if (! empty($emailData['attachments'])) {
                        $placeholders = [];
                        $oldPlaceholders = $email->attachment_placeholders ?? [];
                        $existingMedia = $email->getMedia('attachments');

                        foreach ($emailData['attachments'] as $attachment) {
                            // Skip if already in Media Library (by name and approximate size)
                            $alreadyStored = $existingMedia->contains(function ($m) use ($attachment) {
                                return $m->file_name === ($attachment['name'] ?? '') &&
                                       abs($m->size - ($attachment['size'] ?? 0)) < 1024;
                            });

                            if ($alreadyStored) {
                                continue;
                            }

                            // If lazy, we gathered metadata but skipped the content
                            if (! empty($attachment['is_lazy'])) {
                                // Preserve all metadata (id, attachment_id, etc.) except the content
                                $placeholder = $attachment;
                                unset($placeholder['content']);

                                // [Stability Fix] Try to preserve the old index if this attachment was already known
                                $preservedIndex = null;
                                foreach ($oldPlaceholders as $idx => $old) {
                                    $matchByCid = ! empty($attachment['content_id']) && ! empty($old['content_id']) && $attachment['content_id'] === $old['content_id'];
                                    $matchByName = ($attachment['name'] ?? '') === ($old['name'] ?? '') && abs(($attachment['size'] ?? 0) - ($old['size'] ?? 0)) < 1024;

                                    if ($matchByCid || $matchByName) {
                                        $preservedIndex = $idx;
                                        break;
                                    }
                                }

                                if ($preservedIndex !== null) {
                                    $placeholders[$preservedIndex] = $placeholder;
                                } else {
                                    // Find next available integer index
                                    $nextIdx = 0;
                                    while (isset($placeholders[$nextIdx])) {
                                        $nextIdx++;
                                    }
                                    $placeholders[$nextIdx] = $placeholder;
                                }

                                continue;
                            }

                            try {
                                if (empty($attachment['content'])) {
                                    Log::warning('[EmailSync] Skipping attachment with empty content', [
                                        'email_id' => $email->id,
                                        'attachment' => $attachment['name'] ?? 'unknown',
                                    ]);

                                    continue;
                                }

                                $media = $email->addMediaFromString((string) $attachment['content'])
                                    ->usingFileName($attachment['name'] ?? 'attachment')
                                    ->usingName($attachment['name'] ?? 'Attachment')
                                    ->toMediaCollection('attachments');

                                // Store content_id and is_inline in custom properties
                                if (! empty($attachment['content_id'])) {
                                    $media->setCustomProperty('content_id', $attachment['content_id']);
                                }
                                if (! empty($attachment['is_inline'])) {
                                    $media->setCustomProperty('is_inline', true);
                                }
                                $media->save();
                            } catch (\Throwable $e) {
                                Log::warning('[EmailSync] Failed to store attachment', [
                                    'email_id' => $email->id,
                                    'attachment' => $attachment['name'] ?? 'unknown',
                                    'error' => $e->getMessage(),
                                ]);
                            }
                        }

                        // Update placeholders: remove old ones if we have new sync data
                        // or merge them if appropriate. For now, we trust the latest sync data.
                        $email->update(['attachment_placeholders' => $placeholders]);
                    }

                    // [Graphics Fix] Resolve inline images if we have attachments and HTML body
                    // This replaces cid: links with actual Media URLs on the server-side.
                    if ($email->has_attachments && ! empty($email->body_html)) {
                        $resolvedHtml = $sanitizer->resolveInlineImages($email);
                        if ($resolvedHtml !== $email->body_html) {
                            $email->update(['body_html' => $resolvedHtml]);
                        }
                    }

                    // Broadcast only for new emails AND if broadcast is enabled
                    // (backfill passes false to avoid spamming realtime updates)
                    if ($isNew && $broadcast) {
                        broadcast(new EmailReceived($email));
                    }

                    return $email;
                });
            } catch (\Illuminate\Database\DeadlockException $e) {
                $attempt++;
                if ($attempt >= $maxRetries) {
                    Log::error('[EmailSync] Deadlock after max retries', [
                        'email_account_id' => $account->id,
                        'imap_uid' => $emailData['imap_uid'] ?? null,
                        'attempts' => $attempt,
                    ]);
                    throw $e;
                }
                Log::warning('[EmailSync] Deadlock, retrying', [
                    'attempt' => $attempt,
                    'imap_uid' => $emailData['imap_uid'] ?? null,
                ]);
                usleep(100000 * $attempt); // Exponential backoff: 100ms, 200ms, 300ms
            }
        }

        // Should never reach here, but satisfy return type
        throw new \RuntimeException('Failed to store email after retries');
    }

    /**
     * Fetch body for an email on-demand.
     */
    public function fetchBody(Email $email): Email
    {
        // If already has body, return it
        if (! empty($email->body_html) || ! empty($email->body_plain)) {
            return $email;
        }

        $account = $email->emailAccount;
        $adapter = $this->getAdapterForAccount($account);

        try {
            $emailData = $adapter->fetchFullMessage($email);

            // Update email with fetched data
            // We use storeEmail but force update logic logic?
            // Actually storeEmail uses updateOrCreate, so calling it with the data should work
            // and merge the new body/attachments.
            // Ensure we preserve existing ID/UID matching.

            // If Gmail, $emailData has 'gmail_id'. If IMAP, 'imap_uid'.
            // storeEmail handles logic.

            return $this->storeEmail($account, $emailData, $email->folder, false); // broadcast=false

        } catch (\Throwable $e) {
            Log::error('[EmailSync] Failed to fetch body on-demand', [
                'email_id' => $email->id,
                'error' => $e->getMessage(),
            ]);
            throw $e;
        }
    }

    /**
     * Fetch the raw RFC822 message source for an email.
     */
    public function fetchRawSource(Email $email): string
    {
        $adapter = $this->getAdapterForAccount($email->emailAccount);

        return $adapter->fetchRawSource($email);
    }

    /**
     * Download a specific attachment from IMAP.
     *
     * @return \Spatie\MediaLibrary\MediaCollections\Models\Media
     */
    public function downloadAttachment(Email $email, int $placeholderIndex)
    {
        $account = $email->emailAccount;
        $adapter = $this->getAdapterForAccount($account);

        try {
            return $adapter->downloadAttachment($email, $placeholderIndex);
        } catch (\Throwable $e) {
            Log::error('[EmailSync] Failed to download attachment on-demand', [
                'email_id' => $email->id,
                'placeholder_index' => $placeholderIndex,
                'error' => $e->getMessage(),
            ]);

            // Log to audit trail for visibility
            app(AuditService::class)->log(
                action: AuditAction::SystemError,
                category: AuditCategory::System,
                context: [
                    'error_type' => 'email_sync_failure',
                    'email_id' => $email->id,
                    'account_id' => $email->email_account_id,
                    'error' => $e->getMessage(),
                ]
            );

            throw $e;
        }
    }

    /**
     * Determine importance from headers.
     */
    protected function determineImportance(array $headers): bool
    {
        // helper to get header value case-insensitively
        $get = fn ($key) => $headers[$key] ?? $headers[strtolower($key)] ?? null;

        // Check 'Importance' (High)
        $importance = $get('Importance');
        if ($importance && stripos($importance, 'high') !== false) {
            return true;
        }

        // Check 'X-Priority' (1 or 2 often means High)
        $priority = $get('X-Priority');
        if ($priority && preg_match('/^[12]/', trim($priority))) {
            return true;
        }

        // Check 'X-MSMail-Priority' (High)
        $msPriority = $get('X-MSMail-Priority');
        if ($msPriority && stripos($msPriority, 'high') !== false) {
            return true;
        }

        return false;
    }

    /**
     * Get adapter for an account.
     */
    protected function getAdapterForAccount(EmailAccount $account)
    {
        return AdapterFactory::make($account);
    }
}
