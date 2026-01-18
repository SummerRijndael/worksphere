<?php

namespace App\Contracts;

use App\Models\EmailAccount;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Folder;

/**
 * Interface for provider-specific email operations.
 *
 * Each email provider (Gmail, Outlook, custom IMAP) may have different:
 * - Authentication methods (OAuth vs password)
 * - Folder naming conventions
 * - IMAP command support
 * - Response formats
 */
interface EmailProviderAdapter
{
    /**
     * Get the provider identifier.
     */
    public function getProvider(): string;

    /**
     * Create and configure an IMAP client for the account.
     */
    public function createClient(EmailAccount $account): Client;

    /**
     * Get IMAP folder name for a logical folder type.
     *
     * @param  string  $folderType  One of: inbox, sent, drafts, trash, spam, archive
     */
    public function getFolderName(string $folderType): string;

    /**
     * Get all folder mappings.
     *
     * @return array<string, string> Map of folder type => IMAP folder name
     */
    public function getFolderMapping(): array;

    /**
     * Extract UID from an overview item.
     *
     * Different providers may return overview items as objects or arrays.
     *
     * @param  mixed  $item  Overview item (object or array)
     * @return int|null The UID or null if not found
     */
    public function extractUidFromOverview(mixed $item): ?int;

    /**
     * Refresh OAuth token if needed.
     *
     * @return bool True if token was refreshed or not needed, false on failure
     */
    public function refreshTokenIfNeeded(EmailAccount $account): bool;

    /**
     * Check if this provider uses OAuth authentication.
     */
    public function supportsOAuth(): bool;

    /**
     * Get the maximum number of parallel folder syncs allowed.
     */
    public function getMaxParallelFolders(): int;

    /**
     * Fetch UIDs for the latest N messages in a folder.
     *
     * @param  Folder  $folder  The IMAP folder
     * @param  int  $count  Number of UIDs to fetch
     * @return array<int> Array of UIDs (newest first)
     */
    public function fetchLatestUids(Folder $folder, int $count): array;

    /**
     * Fetch UIDs for a range of messages (for full sync).
     *
     * @param  Folder  $folder  The IMAP folder
     * @param  int  $start  Start sequence number (1-based)
     * @param  int  $end  End sequence number
     * @return array<int> Array of UIDs
     */
    public function fetchUidRange(Folder $folder, int $start, int $end): array;

    /**
     * Fetch the latest N messages from a folder.
     *
     * This is the preferred method as it avoids a second query after getting UIDs.
     *
     * @param  Folder  $folder  The IMAP folder
     * @param  int  $count  Number of messages to fetch
     * @return \Illuminate\Support\Collection Collection of messages
     */
    public function fetchLatestMessages(Folder $folder, int $count): \Illuminate\Support\Collection;
}
