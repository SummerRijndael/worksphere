<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\EmailAccount;
use App\Services\EmailAccountService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\RedirectResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;

class EmailOAuthController extends Controller
{
    public function __construct(
        protected EmailAccountService $emailAccountService
    ) {}

    /**
     * Redirect to OAuth provider for authorization.
     */
    public function redirect(Request $request, string $provider): JsonResponse|RedirectResponse
    {
        if (! in_array($provider, ['gmail', 'outlook'])) {
            return response()->json([
                'message' => 'Unsupported provider.',
            ], 400);
        }

        // Generate state token for CSRF protection
        $state = Str::random(40);

        // Store state in session with user info
        session([
            'email_oauth_state' => $state,
            'email_oauth_user_id' => $request->user()->id,
            'email_oauth_team_id' => $request->input('team_id'),
        ]);

        $url = $this->emailAccountService->getOAuthUrl($provider, $state);

        // Return URL for SPA to redirect
        return response()->json([
            'redirect_url' => $url,
        ]);
    }

    /**
     * Handle OAuth callback from provider.
     */
    public function callback(Request $request, string $provider): RedirectResponse
    {
        $frontendUrl = config('app.frontend_url', config('app.url'));

        try {
            // Verify state
            $sessionState = session('email_oauth_state');
            $requestState = $request->input('state');

            if (! $sessionState || $sessionState !== $requestState) {
                return redirect($frontendUrl.'/email/settings?error=invalid_state');
            }

            // Check for errors from provider
            if ($request->has('error')) {
                $error = $request->input('error_description', $request->input('error'));
                Log::warning('OAuth error from provider', [
                    'provider' => $provider,
                    'error' => $error,
                ]);

                return redirect($frontendUrl.'/email/settings?error='.urlencode($error));
            }

            // Exchange code for tokens
            $code = $request->input('code');
            if (! $code) {
                return redirect($frontendUrl.'/email/settings?error=no_code');
            }

            $tokens = $this->emailAccountService->exchangeCodeForTokens($provider, $code);

            // Get user email from provider
            $email = $this->emailAccountService->getUserEmail($provider, $tokens['access_token']);
            if (! $email) {
                return redirect($frontendUrl.'/email/settings?error=could_not_get_email');
            }

            // Get user and team from session
            $userId = session('email_oauth_user_id');
            $teamId = session('email_oauth_team_id');

            // Clear session data
            session()->forget(['email_oauth_state', 'email_oauth_user_id', 'email_oauth_team_id']);

            // Check if account already exists
            $existingAccount = EmailAccount::where('email', $email)
                ->where('provider', $provider)
                ->where(function ($q) use ($userId, $teamId) {
                    $q->where('user_id', $userId);
                    if ($teamId) {
                        $q->orWhere('team_id', $teamId);
                    }
                })
                ->first();

            if ($existingAccount) {
                // Update existing account with new tokens
                $existingAccount->update([
                    'access_token' => $tokens['access_token'],
                    'refresh_token' => $tokens['refresh_token'],
                    'token_expires_at' => now()->addSeconds($tokens['expires_in']),
                    'is_verified' => true,
                    'last_error' => null,
                ]);

                return redirect($frontendUrl.'/email/settings?email_connected=updated');
            }

            // Create new account
            $providerConfig = EmailAccount::PROVIDERS[$provider];

            $account = EmailAccount::create([
                'user_id' => $teamId ? null : $userId,
                'team_id' => $teamId,
                'name' => ucfirst($provider).' - '.$email,
                'email' => $email,
                'provider' => $provider,
                'auth_type' => 'oauth',
                'imap_host' => $providerConfig['imap_host'],
                'imap_port' => $providerConfig['imap_port'],
                'imap_encryption' => $providerConfig['imap_encryption'],
                'smtp_host' => $providerConfig['smtp_host'],
                'smtp_port' => $providerConfig['smtp_port'],
                'smtp_encryption' => $providerConfig['smtp_encryption'],
                'access_token' => $tokens['access_token'],
                'refresh_token' => $tokens['refresh_token'],
                'token_expires_at' => now()->addSeconds($tokens['expires_in']),
                'is_verified' => true,
            ]);

            // Start initial seed (Phase 1)
            app(\App\Services\EmailSyncService::class)->startSeed($account);

            return redirect($frontendUrl.'/email/settings?email_connected=success');

        } catch (\Throwable $e) {
            Log::error('OAuth callback error', [
                'provider' => $provider,
                'error' => $e->getMessage(),
            ]);

            return redirect($frontendUrl.'/email/settings?error='.urlencode('Connection failed. Please try again.'));
        }
    }

    /**
     * Force refresh OAuth tokens for an account.
     */
    public function refresh(Request $request, EmailAccount $emailAccount): JsonResponse
    {
        $this->authorize('update', $emailAccount);

        if (! $emailAccount->isOAuth()) {
            return response()->json([
                'message' => 'This account does not use OAuth.',
            ], 400);
        }

        $success = $this->emailAccountService->refreshToken($emailAccount);

        if ($success) {
            return response()->json([
                'message' => 'Token refreshed successfully.',
            ]);
        }

        return response()->json([
            'message' => 'Failed to refresh token. Please reconnect the account.',
        ], 400);
    }
}
