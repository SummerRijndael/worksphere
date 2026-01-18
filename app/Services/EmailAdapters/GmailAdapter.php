<?php

namespace App\Services\EmailAdapters;

use App\Models\EmailAccount;
use App\Services\EmailAccountService;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\Client;

/**
 * Gmail-specific adapter with OAuth support and Gmail folder naming.
 */
class GmailAdapter extends BaseEmailAdapter
{
    public function getProvider(): string
    {
        return 'gmail';
    }

    /**
     * Create Gmail IMAP client with OAuth authentication.
     */
    public function createClient(EmailAccount $account): Client
    {
        // Always refresh token before connecting
        $this->refreshTokenIfNeeded($account);

        $config = $this->buildBaseConfig($account);
        $config['authentication'] = 'oauth';
        $config['password'] = $account->access_token;

        Log::debug('[GmailAdapter] Creating client', [
            'account_id' => $account->id,
            'email' => $account->email,
            'has_token' => ! empty($account->access_token),
        ]);

        return $this->clientManager->make($config);
    }

    /**
     * Gmail folder mapping.
     */
    public function getFolderMapping(): array
    {
        return config('email.imap_folders.gmail', [
            'inbox' => 'INBOX',
            'sent' => '[Gmail]/Sent Mail',
            'drafts' => '[Gmail]/Drafts',
            'trash' => '[Gmail]/Trash',
            'spam' => '[Gmail]/Spam',
            'archive' => '[Gmail]/All Mail',
        ]);
    }

    public function supportsOAuth(): bool
    {
        return true;
    }

    /**
     * Refresh Gmail OAuth token if expired or expiring soon.
     */
    public function refreshTokenIfNeeded(EmailAccount $account): bool
    {
        if (! $account->needsTokenRefresh()) {
            return true;
        }

        Log::info('[GmailAdapter] Refreshing OAuth token', [
            'account_id' => $account->id,
            'expires_at' => $account->token_expires_at,
        ]);

        try {
            $service = app(EmailAccountService::class);
            $result = $service->refreshToken($account);

            if ($result) {
                $account->refresh(); // Reload model with new token
                Log::info('[GmailAdapter] Token refreshed successfully', [
                    'account_id' => $account->id,
                    'new_expires_at' => $account->token_expires_at,
                ]);
            } else {
                Log::error('[GmailAdapter] Token refresh failed', [
                    'account_id' => $account->id,
                ]);
            }

            return $result;
        } catch (\Throwable $e) {
            Log::error('[GmailAdapter] Token refresh exception', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function getMaxParallelFolders(): int
    {
        // Gmail is stricter about concurrent connections
        return config('email.max_parallel_folders.gmail', 2);
    }

    /**
     * Gmail doesn't support LIMIT queries, so we must fetch by UID.
     */
    public function fetchLatestMessages(\Webklex\PHPIMAP\Folder $folder, int $count): \Illuminate\Support\Collection
    {
        // Gmail works well with overview() - use UIDs from there
        $uids = $this->fetchLatestUids($folder, $count);

        if (empty($uids)) {
            Log::warning('[GmailAdapter] No UIDs from overview, folder may be empty', [
                'folder' => $folder->path,
            ]);

            return collect();
        }

        Log::debug('[GmailAdapter] Fetching messages by UID', [
            'folder' => $folder->path,
            'uid_count' => count($uids),
        ]);

        // Fetch messages one by one by UID
        $messages = collect();
        foreach ($uids as $uid) {
            try {
                $msg = $folder->query()->getMessageByUid($uid);
                if ($msg) {
                    $messages->push($msg);
                }
            } catch (\Throwable $e) {
                Log::debug("[GmailAdapter] Failed to fetch UID {$uid}", [
                    'error' => $e->getMessage(),
                ]);
            }
        }

        return $messages;
    }
}
