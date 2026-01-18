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

        // Start initial seed (Phase 1)
        app(\App\Services\EmailSyncService::class)->startSeed($account);

        return $account;
    }

    /**
     * Test SMTP connection for an email account.
     */
    /**
     * Test SMTP connection for an email account.
     */
    public function testConnection(EmailAccount $account): array
    {
        try {
            // Refresh token if needed for OAuth accounts
            if ($account->isOAuth() && $account->needsTokenRefresh()) {
                $this->refreshToken($account);
            }

            $host = $account->smtp_host;
            $port = $account->smtp_port;
            $encryption = $account->smtp_encryption;

            // Use implicit SSL (SMTPS) for 'ssl' encryption, usually port 465
            // TLS (STARTTLS) is handled automatically by EsmtpTransport when supported (default)
            $useTls = ($encryption === 'ssl');

            $transport = new EsmtpTransport($host, $port, $useTls);

            // Note: detailed stream timeout configuration omitted for compatibility

            if ($account->isOAuth()) {
                // Symfony's EsmtpTransport includes XOAuth2Authenticator by default.
                // It will automatically use XOAUTH2 if the server advertises it in EHLO.
                // We just need to set the username to the email and password to the access_token.
                $transport->setUsername($account->email);

                // Ensure we have a valid access token
                if (! $account->access_token) {
                    throw new \Exception('No access token available for OAuth account.');
                }
                $transport->setPassword($account->access_token);
            } else {
                $transport->setUsername($account->username ?? $account->email);
                $transport->setPassword($account->password ?? '');
            }

            // Start the transport to trigger connection and Hello/Auth handshake
            $transport->start();
            $transport->stop();

            $account->markAsVerified();

            return [
                'success' => true,
                'message' => 'Connection successful',
            ];
        } catch (\Throwable $e) {
            $error = $e->getMessage();
            $account->markAsError($error);

            Log::warning('Email account connection test failed', [
                'account_id' => $account->id,
                'error' => $error,
            ]);

            return [
                'success' => false,
                'message' => 'Connection failed: '.$error,
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
            throw new \InvalidArgumentException("Unknown provider: {$provider}");
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
                $response = Http::withToken($accessToken)
                    ->get('https://www.googleapis.com/oauth2/v2/userinfo');

                return $response->json('email');
            }

            if ($provider === 'outlook') {
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
        return match ($provider) {
            'gmail' => config('services.google.client_id'),
            'outlook' => config('services.microsoft.client_id', config('services.azure.client_id')),
            default => '',
        };
    }

    /**
     * Get client secret for provider.
     */
    protected function getClientSecret(string $provider): string
    {
        return match ($provider) {
            'gmail' => config('services.google.client_secret'),
            'outlook' => config('services.microsoft.client_secret', config('services.azure.client_secret')),
            default => '',
        };
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
