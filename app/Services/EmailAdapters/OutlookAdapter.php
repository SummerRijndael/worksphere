<?php

namespace App\Services\EmailAdapters;

use App\Models\EmailAccount;
use App\Services\EmailAccountService;
use Illuminate\Support\Facades\Log;
use Webklex\PHPIMAP\Client;

/**
 * Outlook/Microsoft 365 adapter with OAuth support.
 */
class OutlookAdapter extends BaseEmailAdapter
{
    public function getProvider(): string
    {
        return 'outlook';
    }

    /**
     * Create Outlook IMAP client with OAuth authentication.
     */
    public function createClient(EmailAccount $account): Client
    {
        // Always refresh token before connecting
        $this->refreshTokenIfNeeded($account);

        $config = $this->buildBaseConfig($account);
        $config['authentication'] = 'oauth';
        $config['password'] = $account->access_token;

        Log::debug('[OutlookAdapter] Creating client', [
            'account_id' => $account->id,
            'email' => $account->email,
            'has_token' => ! empty($account->access_token),
        ]);

        return $this->clientManager->make($config);
    }

    /**
     * Outlook folder mapping.
     */
    public function getFolderMapping(): array
    {
        return config('email.imap_folders.outlook', [
            'inbox' => 'INBOX',
            'sent' => 'Sent Items',
            'drafts' => 'Drafts',
            'trash' => 'Deleted Items',
            'spam' => 'Junk Email',
            'archive' => 'Archive',
        ]);
    }

    public function supportsOAuth(): bool
    {
        return true;
    }

    /**
     * Refresh Outlook OAuth token if expired or expiring soon.
     */
    public function refreshTokenIfNeeded(EmailAccount $account): bool
    {
        if (! $account->needsTokenRefresh()) {
            return true;
        }

        Log::info('[OutlookAdapter] Refreshing OAuth token', [
            'account_id' => $account->id,
            'expires_at' => $account->token_expires_at,
        ]);

        try {
            $service = app(EmailAccountService::class);
            $result = $service->refreshToken($account);

            if ($result) {
                $account->refresh();
                Log::info('[OutlookAdapter] Token refreshed successfully', [
                    'account_id' => $account->id,
                    'new_expires_at' => $account->token_expires_at,
                ]);
            } else {
                Log::error('[OutlookAdapter] Token refresh failed', [
                    'account_id' => $account->id,
                ]);
            }

            return $result;
        } catch (\Throwable $e) {
            Log::error('[OutlookAdapter] Token refresh exception', [
                'account_id' => $account->id,
                'error' => $e->getMessage(),
            ]);

            return false;
        }
    }

    public function getMaxParallelFolders(): int
    {
        return config('email.max_parallel_folders.outlook', 2);
    }
}
