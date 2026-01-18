<?php

namespace App\Services\EmailAdapters;

use App\Models\EmailAccount;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\Client;
use Webklex\PHPIMAP\Folder;

/**
 * Adapter for custom IMAP servers (GoDaddy, etc.) with password authentication.
 */
class CustomImapAdapter extends BaseEmailAdapter
{
    public function getProvider(): string
    {
        return 'custom';
    }

    /**
     * Create IMAP client with password authentication.
     */
    public function createClient(EmailAccount $account): Client
    {
        $config = $this->buildBaseConfig($account);
        $config['password'] = $account->password;

        Log::debug('[CustomImapAdapter] Creating client', [
            'account_id' => $account->id,
            'email' => $account->email,
            'host' => $account->imap_host,
        ]);

        return $this->clientManager->make($config);
    }

    /**
     * Custom IMAP folder mapping (standard names).
     */
    public function getFolderMapping(): array
    {
        return config('email.imap_folders.custom', [
            'inbox' => 'INBOX',
            'sent' => 'Sent',
            'drafts' => 'Drafts',
            'trash' => 'Trash',
            'spam' => 'Spam',
            'archive' => 'Archive',
        ]);
    }

    public function supportsOAuth(): bool
    {
        return false;
    }

    public function getMaxParallelFolders(): int
    {
        return config('email.max_parallel_folders.custom', 3);
    }

    /**
     * Custom IMAP servers often have issues with overview() returning empty UIDs.
     * Go directly to the fallback query which works reliably on GoDaddy etc.
     */
    public function fetchLatestMessages(Folder $folder, int $count): \Illuminate\Support\Collection
    {
        // For custom IMAP, directly use the limit query which is most reliable
        try {
            Log::debug('[CustomImapAdapter] Fetching messages with limit query', [
                'folder' => $folder->path,
                'count' => $count,
            ]);

            return $folder->query()->limit($count)->get();
        } catch (\Throwable $e) {
            Log::warning('[CustomImapAdapter] Query with limit failed, trying parent method', [
                'folder' => $folder->path,
                'error' => $e->getMessage(),
            ]);

            // Fall back to parent implementation
            return parent::fetchLatestMessages($folder, $count);
        }
    }
}
