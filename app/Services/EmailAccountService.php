<?php

namespace App\Services;

use App\Models\EmailAccount;
use App\Models\User;
use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;
use Symfony\Component\Mailer\Transport\Smtp\EsmtpTransport;

class EmailAccountService
{
    /**
     * OAuth configuration for providers.
     */
    protected array $oauthConfig = [
        'gmail' => [
            'auth_url' => 'https://accounts.google.com/o/oauth2/v2/auth',
            'token_url' => 'https://oauth2.googleapis.com/token',
            'scopes' => [
                'https://mail.google.com/',
                'https://www.googleapis.com/auth/gmail.send',
                'https://www.googleapis.com/auth/userinfo.email',
            ],
        ],
        'outlook' => [
            'auth_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/authorize',
            'token_url' => 'https://login.microsoftonline.com/common/oauth2/v2.0/token',
            'scopes' => [
                'https://outlook.office.com/IMAP.AccessAsUser.All',
                'https://outlook.office.com/SMTP.Send',
                'offline_access',
                'openid',
                'email',
            ],
        ],
    ];

    /**
     * Create a new email account.
     */
    public function create(array $data, ?User $user = null): EmailAccount
    {
        $provider = $data['provider'] ?? 'custom';
        $providerConfig = EmailAccount::PROVIDERS[$provider] ?? [];

        // Apply provider defaults for Gmail/Outlook
        if ($provider !== 'custom') {
            $data = array_merge([
                'imap_host' => $providerConfig['imap_host'] ?? null,
                'imap_port' => $providerConfig['imap_port'] ?? 993,
                'imap_encryption' => $providerConfig['imap_encryption'] ?? 'ssl',
                'smtp_host' => $providerConfig['smtp_host'] ?? null,
                'smtp_port' => $providerConfig['smtp_port'] ?? 587,
                'smtp_encryption' => $providerConfig['smtp_encryption'] ?? 'tls',
            ], $data);
        }

        if ($user && ! isset($data['user_id'])) {
            $data['user_id'] = $user->id;
        }

        $account = EmailAccount::create($data);

        // Skip sync for SMTP-only accounts
        if (! $account->isSmtpOnly()) {
            // Start initial seed (Phase 1)
            app(\App\Services\EmailSyncService::class)->startSeed($account);
        }

        return $account;
    }

    public function testConnection(EmailAccount $account): array
    {
        // For Gmail, we use the API to verify connectivity
        if ($account->provider === 'gmail') {
            try {
                $gmailApi = app(\App\Services\GmailApiService::class);
                $gmailApi->listLabels($account); // This triggers token refresh and basic API check

                $account->markAsVerified();

                return [
                    'success' => true,
                    'message' => 'Gmail API connection successful',
                ];
            } catch (\Throwable $e) {
                $error = $e->getMessage();
                $account->markAsError($error);

                return [
                    'success' => false,
                    'message' => 'Gmail API: '.$error,
                ];
            }
        }

        // 1. Test SMTP
        $smtpResult = $this->testSmtpConnection($account);
        if (! $smtpResult['success']) {
            return [
                'success' => false,
                'message' => 'SMTP: '.$smtpResult['message'],
            ];
        }

        // 2. Test IMAP (Skip if SMTP-only)
        if (! $account->isSmtpOnly()) {
            $imapResult = $this->testImapConnection($account);
            if (! $imapResult['success']) {
                return [
                    'success' => false,
                    'message' => 'IMAP: '.$imapResult['message'],
                ];
            }
        }

        $account->markAsVerified();

        return [
            'success' => true,
            'message' => $account->isSmtpOnly() ? 'SMTP connection successful' : 'Connection successful (SMTP and IMAP)',
        ];
    }

    /**
     * Ensure the host is safe to connect to (SSRF protection).
     */
    protected function ensureHostIsSafe(?string $host): void
    {
        if ($host === null || $host === '') {
            return;
        }

        // 1. Check if it's an IP address
        if (filter_var($host, FILTER_VALIDATE_IP)) {
            if ($this->isBlockedIp($host)) {
                throw new \Exception('Access to private IP addresses is not allowed.');
            }

            return;
        }

        // 2. Resolve hostname (IPv4)
        $ips = gethostbynamel($host);
        if ($ips === false) {
            // Check for IPv6 records too if IPv4 failed
            $ipv6 = dns_get_record($host, DNS_AAAA);
            if (! $ipv6) {
                return; // Resolution failed, let connection attempt fail naturally
            }
            $ips = array_column($ipv6, 'ipv6');
        }

        // 3. Check all resolved IPs
        foreach ($ips as $ip) {
            if ($this->isBlockedIp($ip)) {
                throw new \Exception('Host resolves to a private IP address. Access denied.');
            }
        }
    }

    /**
     * Check if an IP address should be blocked.
     */
    protected function isBlockedIp(string $ip): bool
    {
        // 1. Basic format validation
        if (! filter_var($ip, FILTER_VALIDATE_IP)) {
            return true;
        }

        // 2. Check for IPv4-mapped IPv6 address (e.g. ::ffff:127.0.0.1)
        // This is crucial because FILTER_FLAG_NO_PRIV_RANGE on IPv6 does NOT catch these mapping to private IPv4 space
        if (filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_IPV6)) {
            $packed = @inet_pton($ip);
            if ($packed === false) {
                return true;
            }

            // Check for ::ffff:0:0/96 prefix (IPv4-mapped)
            // 80 bits of zeros (10 bytes) followed by 16 bits of ones (2 bytes) = 12 bytes
            $mappedPrefix = str_repeat("\x00", 10)."\xff\xff";

            if (str_starts_with($packed, $mappedPrefix)) {
                // It is an IPv4-mapped address. Extract the IPv4 part (last 4 bytes)
                $ipv4 = inet_ntop(substr($packed, -4));

                // Validate the extracted IPv4 address against private/reserved ranges
                if (! filter_var($ipv4, FILTER_VALIDATE_IP, FILTER_FLAG_IPV4 | FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
                    return true;
                }

                return false;
            }
        }

        // 3. Standard check for private/reserved ranges (IPv4 or IPv6)
        if (! filter_var($ip, FILTER_VALIDATE_IP, FILTER_FLAG_NO_PRIV_RANGE | FILTER_FLAG_NO_RES_RANGE)) {
            return true;
        }

        return false;
    }

    /**
     * Test SMTP connection for an email account.
     */
    public function testSmtpConnection(EmailAccount $account): array
    {
        try {
            // Refresh token if needed for OAuth accounts
            if ($account->isOAuth() && $account->needsTokenRefresh()) {
                $this->refreshToken($account);
            }

            $this->ensureHostIsSafe($account->smtp_host);

            $host = $account->smtp_host;
            $port = $account->smtp_port;
            $encryption = $account->smtp_encryption;

            // Use implicit SSL (SMTPS) for 'ssl' encryption, usually port 465
            // TLS (STARTTLS) is handled automatically by EsmtpTransport when supported (default)
            $useTls = ($encryption === 'ssl');

            $transport = new EsmtpTransport($host, $port, $useTls);

            if ($account->isOAuth()) {
                $transport->setUsername($account->email);
                if (! $account->access_token) {
                    throw new \Exception('No access token available for OAuth account.');
                }
                $transport->setPassword($account->access_token);
            } else {
                $transport->setUsername($account->username ?? $account->email);
                $transport->setPassword($account->password ?? '');
            }

            $transport->start();
            $transport->stop();

            return [
                'success' => true,
                'message' => 'SMTP connection successful',
            ];
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            $account->markAsError($error);

            Log::warning('SMTP connection test failed', [
                'account_id' => $account->id,
                'error' => $error,
            ]);

            return [
                'success' => false,
                'message' => $error,
            ];
        }
    }

    /**
     * Test IMAP connection for an email account.
     */
    public function testImapConnection(EmailAccount $account): array
    {
        try {
            // Refresh token if needed for OAuth accounts
            if ($account->isOAuth() && $account->needsTokenRefresh()) {
                $this->refreshToken($account);
            }

            $this->ensureHostIsSafe($account->imap_host);

            $adapter = \App\Services\EmailAdapters\AdapterFactory::make($account);
            $client = $adapter->createClient($account);

            $client->connect();

            // Try to list folders to ensure account is fully accessible
            $client->getFolders(false);

            $client->disconnect();

            return [
                'success' => true,
                'message' => 'IMAP connection successful',
            ];
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            $account->markAsError($error);

            Log::warning('IMAP connection test failed', [
                'account_id' => $account->id,
                'error' => $error,
            ]);

            return [
                'success' => false,
                'message' => $error,
            ];
        }
    }

    /**
     * Generate OAuth authorization URL.
     */
    public function getOAuthUrl(string $provider, string $state): string
    {
        $config = $this->oauthConfig[$provider] ?? null;
        if (! $config) {
            throw new \InvalidArgumentException('Unknown provider: '.htmlspecialchars($provider));
        }

        $params = [
            'client_id' => $this->getClientId($provider),
            'redirect_uri' => $this->getRedirectUri($provider),
            'response_type' => 'code',
            'scope' => implode(' ', $config['scopes']),
            'state' => $state,
            'access_type' => 'offline',
            'prompt' => 'consent',
        ];

        return $config['auth_url'].'?'.http_build_query($params);
    }

    /**
     * Exchange authorization code for tokens.
     */
    public function exchangeCodeForTokens(string $provider, string $code): array
    {
        $config = $this->oauthConfig[$provider] ?? null;
        if (! $config) {
            throw new \InvalidArgumentException("Unknown provider: {$provider}");
        }

        /** @var \Illuminate\Http\Client\Response $response */
        $response = Http::asForm()->post($config['token_url'], [
            'client_id' => $this->getClientId($provider),
            'client_secret' => $this->getClientSecret($provider),
            'redirect_uri' => $this->getRedirectUri($provider),
            'grant_type' => 'authorization_code',
            'code' => $code,
        ]);

        if (! $response->successful()) {
            throw new \RuntimeException('Failed to exchange code for tokens: '.$response->body());
        }

        $data = $response->json();

        return [
            'access_token' => $data['access_token'],
            'refresh_token' => $data['refresh_token'] ?? null,
            'expires_in' => $data['expires_in'] ?? 3600,
        ];
    }

    /**
     * Refresh OAuth access token.
     */
    public function refreshToken(EmailAccount $account): bool
    {
        if (! $account->refresh_token) {
            return false;
        }

        $config = $this->oauthConfig[$account->provider] ?? null;
        if (! $config) {
            return false;
        }

        try {
            /** @var \Illuminate\Http\Client\Response $response */
            $response = Http::asForm()->post($config['token_url'], [
                'client_id' => $this->getClientId($account->provider),
                'client_secret' => $this->getClientSecret($account->provider),
                'refresh_token' => $account->refresh_token,
                'grant_type' => 'refresh_token',
            ]);

            if (! $response->successful()) {
                $this->handleRefreshFailure($account, $response->body());

                return false;
            }

            $data = $response->json();

            $account->update([
                'access_token' => $data['access_token'],
                'token_expires_at' => now()->addSeconds($data['expires_in'] ?? 3600),
                'refresh_token' => $data['refresh_token'] ?? $account->refresh_token,
                'consecutive_failures' => 0,
                'needs_reauth' => false,
            ]);

            return true;
        } catch (\Throwable $e) {
            $this->handleRefreshFailure($account, $e->getMessage());

            return false;
        }
    }

    /**
     * Handle token refresh failure with circuit breaker.
     */
    protected function handleRefreshFailure(EmailAccount $account, string $error): void
    {
        $failures = $account->consecutive_failures + 1;
        $updates = ['consecutive_failures' => $failures];

        // Circuit breaker: 3 consecutive failures triggers re-auth requirement
        if ($failures >= 3) {
            $updates['needs_reauth'] = true;
            $updates['sync_status'] = \App\Enums\EmailSyncStatus::Failed;
            $updates['sync_error'] = 'Authentication failed. Please reconnect your account.';

            // Broadcast event
            broadcast(new \App\Events\Email\SyncStatusChanged(
                $account,
                'needs_reauth',
                'Authentication failed. Please reconnect your account.'
            ));
        }

        $account->update($updates);

        Log::error('Token refresh failed', [
            'account_id' => $account->id,
            'failures' => $failures,
            'error' => $error,
        ]);
    }

    /**
     * Get user's email from OAuth provider.
     */
    public function getUserEmail(string $provider, string $accessToken): ?string
    {
        try {
            if ($provider === 'gmail') {
                /** @var \Illuminate\Http\Client\Response $response */
                $response = Http::withToken($accessToken)
                    ->get('https://www.googleapis.com/oauth2/v2/userinfo');

                return $response->json('email');
            }

            if ($provider === 'outlook') {
                /** @var \Illuminate\Http\Client\Response $response */
                $response = Http::withToken($accessToken)
                    ->get('https://graph.microsoft.com/v1.0/me');

                return $response->json('mail') ?? $response->json('userPrincipalName');
            }
        } catch (\Throwable $e) {
            Log::error('Failed to get user email', ['error' => $e->getMessage()]);
        }

        return null;
    }

    /**
     * Get client ID for provider.
     */
    protected function getClientId(string $provider): string
    {
        return (string) match ($provider) {
            'gmail' => config('services.google.client_id'),
            'outlook' => config('services.microsoft.client_id', config('services.azure.client_id')),
            default => '',
        } ?? '';
    }

    /**
     * Get client secret for provider.
     */
    protected function getClientSecret(string $provider): string
    {
        return (string) match ($provider) {
            'gmail' => config('services.google.client_secret'),
            'outlook' => config('services.microsoft.client_secret', config('services.azure.client_secret')),
            default => '',
        } ?? '';
    }

    /**
     * Get OAuth redirect URI for provider.
     */
    protected function getRedirectUri(string $provider): string
    {
        return url("/api/email-accounts/oauth/{$provider}/callback");
    }

    /**
     * Get OAuth provider instance for PHPMailer.
     */
    protected function getOAuthProvider(EmailAccount $account): mixed
    {
        // This would need league/oauth2-client providers
        // For now, return null - OAuth via tokens directly
        return null;
    }
}
