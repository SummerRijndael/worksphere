<?php

namespace App\Services;

use App\Contracts\EmailSyncServiceContract;
use App\Enums\EmailFolderType;
use App\Enums\EmailSyncStatus;
use App\Events\Email\EmailReceived;
use App\Events\Email\SyncStatusChanged;
use App\Jobs\FetchNewEmailsJob;
use App\Jobs\SeedEmailAccountJob;
use App\Jobs\SyncEmailFolderJob;
use App\Models\Email;
use App\Models\EmailAccount;
use App\Models\EmailSyncLog;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Support\Facades\Log;

class EmailSyncService implements EmailSyncServiceContract
{
    /**
     * Start initial seed for an account (Phase 1).
     */
    public function startSeed(EmailAccount $account): void
    {
        // Update status
        $account->update([
            'sync_status' => EmailSyncStatus::Seeding,
            'sync_cursor' => $this->initializeSyncCursor(),
            'sync_error' => null,
        ]);

        // Log seed start
        EmailSyncLog::logSeedStarted(
            $account->id,
            array_map(fn ($f) => $f->value, EmailFolderType::priorityFolders())
        );

        // Dispatch seed job (handles parallel fetch of priority folders)
        SeedEmailAccountJob::dispatch($account->id);

        SyncStatusChanged::dispatch(
            $account,
            EmailSyncStatus::Seeding->value
        );
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
        foreach (EmailFolderType::syncOrder() as $folderType) {
            $folderKey = $folderType->value;
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
     * Fetch new emails for a fully synced account (incremental).
     */
    public function fetchNewEmails(EmailAccount $account): int
    {
        if ($account->sync_status !== EmailSyncStatus::Completed) {
            return 0;
        }

        // Dispatch incremental fetch job
        FetchNewEmailsJob::dispatch($account->id);

        return 0; // Actual count returned via job
    }

    /**
     * Get sync progress for an account.
     */
    public function getSyncProgress(EmailAccount $account): array
    {
        $cursor = $account->sync_cursor ?? [];
        $folders = $cursor['folders'] ?? [];
        $phase = $cursor['phase'] ?? 'pending';

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

        $overallPercent = $totalEmails > 0 ? round(($syncedEmails / $totalEmails) * 100) : 0;

        return [
            'status' => $account->sync_status->value,
            'phase' => $phase,
            'folders' => $folderProgress,
            'overall_percent' => $overallPercent,
            'total_emails' => $totalEmails,
            'synced_emails' => $syncedEmails,
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
     * Get accounts ready for incremental sync.
     */
    public function getAccountsForIncrementalSync(): Collection
    {
        $intervalMinutes = config('email.scheduler.incremental_interval', 5);

        return EmailAccount::query()
            ->active()
            ->verified()
            ->syncCompleted()
            ->where(function ($query) use ($intervalMinutes) {
                $query->whereNull('last_sync_at')
                    ->orWhere('last_sync_at', '<=', now()->subMinutes($intervalMinutes));
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
     * Store fetched email from IMAP.
     */
    public function storeEmailFromImap(
        EmailAccount $account,
        array $emailData,
        string $folder
    ): Email {
        // Get the sanitization service
        $sanitizer = app(EmailSanitizationService::class);

        // Store original HTML in body_raw, sanitize for body_html
        $bodyRaw = $emailData['body_html'] ?? null;
        $bodyHtml = $bodyRaw ? $sanitizer->sanitize($bodyRaw, $account->provider ?? 'imap') : null;

        $email = Email::create([
            'email_account_id' => $account->id,
            'user_id' => $account->user_id,
            'message_id' => $emailData['message_id'] ?? null,
            'thread_id' => $emailData['thread_id'] ?? null,
            'folder' => $folder,
            'from_email' => $emailData['from_email'],
            'from_name' => $emailData['from_name'] ?? null,
            'to' => $emailData['to'] ?? [],
            'cc' => $emailData['cc'] ?? [],
            'bcc' => $emailData['bcc'] ?? [],
            'subject' => $emailData['subject'] ?? '(No Subject)',
            'preview' => $emailData['preview'] ?? '',
            'body_html' => $bodyHtml,
            'body_plain' => $emailData['body_plain'] ?? null,
            'body_raw' => $bodyRaw,
            'headers' => $emailData['headers'] ?? [],
            'is_read' => $emailData['is_read'] ?? false,
            'is_starred' => $emailData['is_starred'] ?? false,
            'has_attachments' => $emailData['has_attachments'] ?? false,
            'imap_uid' => $emailData['imap_uid'] ?? null,
            'received_at' => $emailData['date'] ?? now(),
            'sanitized_at' => $bodyHtml ? now() : null,
        ]);

        // Store attachments with content_id for inline image support
        if (! empty($emailData['attachments'])) {
            foreach ($emailData['attachments'] as $attachment) {
                try {
                    $media = $email->addMediaFromString($attachment['content'])
                        ->usingFileName($attachment['name'] ?? 'attachment')
                        ->usingName($attachment['name'] ?? 'Attachment')
                        ->toMediaCollection('attachments');

                    // Store content_id in custom properties for inline image matching
                    if (! empty($attachment['content_id'])) {
                        $media->setCustomProperty('content_id', $attachment['content_id']);
                        $media->save();
                    }
                } catch (\Throwable $e) {
                    Log::warning('[EmailSync] Failed to store attachment', [
                        'email_id' => $email->id,
                        'attachment' => $attachment['name'] ?? 'unknown',
                        'error' => $e->getMessage(),
                    ]);
                }
            }
        }

        // Broadcast new email
        broadcast(new EmailReceived($email));

        return $email;
    }
}
