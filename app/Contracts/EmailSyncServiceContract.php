<?php

namespace App\Contracts;

use App\Models\EmailAccount;

interface EmailSyncServiceContract
{
    /**
     * Start initial seed for an account (Phase 1 - parallel 50 emails per folder).
     */
    public function startSeed(EmailAccount $account): void;

    /**
     * Continue full sync for an account (Phase 2 - sequential completion).
     */
    public function continueSync(EmailAccount $account): void;

    /**
     * Fetch new emails for a fully synced account (incremental).
     */
    public function fetchNewEmails(EmailAccount $account): int;

    /**
     * Get sync progress for an account.
     *
     * @return array{phase: string, folders: array, overall_percent: int}
     */
    public function getSyncProgress(EmailAccount $account): array;

    /**
     * Mark sync as completed for an account.
     */
    public function markSyncCompleted(EmailAccount $account): void;

    /**
     * Mark sync as failed for an account.
     */
    public function markSyncFailed(EmailAccount $account, string $error): void;

    /**
     * Get accounts that need sync (pending, seeding, or syncing).
     *
     * @return \Illuminate\Database\Eloquent\Collection<EmailAccount>
     */
    public function getAccountsNeedingSync(): \Illuminate\Database\Eloquent\Collection;

    /**
     * Get accounts ready for incremental sync.
     *
     * @return \Illuminate\Database\Eloquent\Collection<EmailAccount>
     */
    public function getAccountsForIncrementalSync(): \Illuminate\Database\Eloquent\Collection;

    /**
     * Get IMAP folder name for provider and folder type.
     */
    public function getImapFolderName(string $provider, string $folderType): string;

    /**
     * Get max parallel folder limit for provider.
     */
    public function getMaxParallelFolders(string $provider): int;
}
