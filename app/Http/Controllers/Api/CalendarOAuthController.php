<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\SocialAccount;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;
use Laravel\Socialite\Facades\Socialite;

class CalendarOAuthController extends Controller
{
    /**
     * Redirect to Google to authorize Calendar access.
     */
    public function redirect(Request $request): JsonResponse
    {
        // Must use 'google' driver
        // Request specific scopes for Calendar
        /** @var \Laravel\Socialite\Two\GoogleProvider $driver */
        $driver = Socialite::driver('google');

        Log::info('Google Calendar: Redirecting user to Google for permissions', [
            'user_id' => $request->user()->id,
            'scopes' => ['https://www.googleapis.com/auth/calendar.events'],
        ]);

        // Redirect back to the Calendar or Settings page (wherever the FE component lives)
        // Ideally this should be dynamic or config-based, but for this specific flow we want to land on the FE to handle the code.
        // Assuming the component is at /calendar or /settings. The user mentioned "Calendar Page".
        // Let's use the referer if available, or fall back to '/calendar'.

        $frontendCallback = $request->input('redirect_to', config('app.frontend_url').'/calendar');
        // Note: Google Console must whitelist this URI. If it's just 'localhost:8000/calendar', it might need adding.
        // If we can't add it dynamically, we must rely on a fixed backend route that redirects to frontend?
        // No, Socialite's ->with(['redirect_uri' => ...]) overrides the one sent to Google.

        // HOWEVER: The redirect_uri sent to Google MUST match one in the Google Console.
        // If the console only has ".../auth/google/callback", we are stuck with that unless we add more.
        // Assumption: We can use the generic callback but we need to intercept it?
        // OR: We assume the user has configured their Google App to allow the Frontend URL.
        // Given this is a local environment, let's try to set it to logical location: http://127.0.0.1:8000/settings
        // Why settings? Because likely that's where the connection button is. The user mentioned both.
        // Let's try to stick to what is likely configured or what the user effectively wants.

        // Strategy: Force redirect_uri to be the one that handles the callback logic.
        // IF the backend is receiving the callback (as per logs), it means Google is sending it there.
        // If we want Frontend to handle it, we must tell Google to send it to Frontend.
        // This requires 'redirect_uri' param in the authorization URL.
        // Component is in Calendar/Index.vue, so we MUST redirect to /calendar

        $frontendRedirectUrl = config('services.google.redirect_calendar');

        $url = $driver
            ->scopes(['https://www.googleapis.com/auth/calendar.events'])
            ->with(['access_type' => 'offline', 'prompt' => 'consent'])
            ->redirectUrl($frontendRedirectUrl) // Explicitly override
            ->stateless()
            ->redirect()
            ->getTargetUrl();

        Log::info('DEBUG: CalendarOAuthController::redirect - EXITING', ['url' => $url]);

        return response()->json([
            'url' => $url,
        ]);
    }

    /**
     * Handle the callback from Google.
     */
    public function callback(Request $request)
    {
        $frontendUrl = config('app.frontend_url', config('app.url'));

        try {
            // Stateless retrieval
            $socialUser = Socialite::driver('google')->stateless()->user();

            // Get the current authenticated user (this must be an authenticated request?
            // Often callbacks lose auth state if using stateless/session mismatch.
            // For an API interaction triggered from SPA, we usually expect the user to be logged in
            // BUT if the callback comes from Google directly to the backend, the Bearer token isn't present in the URL query params.
            // SOLUTION: Usually we use a 'state' parameter to pass a one-time token or ID.
            // Socialite Stateless handles CSRF state, but not "User Identity".
            // Since we are "Linking" an account, we need to know WHO to link it to.
            // Standard Pattern: The callback hits the Frontend SPA first? No, Socialite is Backend-to-Backend mostly.
            // If API mode: The frontend receives the code, sends to backend.
            // The method used here: backend redirect -> backend callback.
            // PROBLEM: In `callback`, `auth()->id()` might be null API side if session is not shared.
            // However, Sanctum/Session might persist if using cookies.
            // Safest: Use a cache key/token mechanism similar to EmailOAuthController logic with session/cache.

            // Re-using EmailOAuthController logic:
            // "Store state in session with user info"...
            // If this is a rigid API separation, sessions might not fly.
            // Let's assume standard Laravel Session usage works here as per existing controllers.
            // If not, we might need a frontend proxy callback (Frontend receives code -> POST /api/calendar/connect {code})

            // FOR NOW: Let's assume we can rely on Laravel Session for the short duration of the handshake,
            // or the Frontend will catch the callback and send the CODE to us via an authenticated POST.
            // Let's implement the "Exchange Code" pattern via POST is safer for SPAs.
            // BUT `redirect` above returns a URL.
            // Option 1: Backend handles full redirect loop. (Requires Session cookies)
            // Option 2: Frontend handles callback, grabs code, posts to `connect`.

            // I will implement `connect` method accepting a `code`.
            // The `redirect` method generates the URL. The frontend follows it.
            // Google redirects back to Frontend Route `/settings/calendar/callback?code=...`
            // Frontend calls `POST /api/calendar/oauth/connect` with `code`.

            // Wait, Socialite `user()` method handles the code exchange automatically if it detects it in request.
            // I'll stick to the "Backend Callback" standard approach in Laravel, assuming cookie session or `state` parameter usage.
            // Actually, the `EmailOAuthController` used `session()`. I'll try to follow "Frontend receives code -> POST backend" for better SPA compatibility.

            // Change of plan: `connect` method.

        } catch (\Exception $e) {
            Log::error('Google Calendar Sync Error: '.$e->getMessage());

            return redirect($frontendUrl.'/settings?error=sync_failed');
        }
    }

    /**
     * Exchange authorization code for tokens (SPA flow).
     */
    public function connect(Request $request): JsonResponse
    {
        Log::info('DEBUG: CalendarOAuthController::connect - ENTERING');
        $request->validate(['code' => 'required|string']);

        try {
            $user = $request->user();

            Log::info('DEBUG: Google Calendar: Connect request received', [
                'user_id' => $user->id,
                'has_code' => $request->has('code'),
                'code_snippet' => substr($request->code, 0, 10).'...',
            ]);

            // Manually exchange code using Socialite or Google Client
            // Socialite doesn't easily support "exchange code provided in payload" without hacking the request.
            // Proper way: Configure Google_Client

            /** @var \Laravel\Socialite\Two\GoogleProvider $driver */
            $driver = Socialite::driver('google');

            Log::info('DEBUG: Google Calendar: Exchanging code for token...');
            // IMPORTANT: statless() must be called, and redirectUrl matches original request
            $frontendRedirectUrl = config('services.google.redirect_calendar');

            $response = $driver->stateless()
                ->redirectUrl($frontendRedirectUrl) // Must match the redirect_uri used in 'redirect'
                ->getAccessTokenResponse($request->code);

            Log::info('DEBUG: Google Calendar: Token received.', ['expires_in' => $response['expires_in'] ?? 'unknown']);

            $accessToken = $response['access_token'];
            $refreshToken = $response['refresh_token'] ?? null;
            $expiresIn = $response['expires_in'];
            $scopes = $response['scope'] ?? ''; // Google returns space separated string

            // We also need the Google User ID to key the record
            // We can get user details via the token
            Log::info('DEBUG: Google Calendar: Fetching user details from Google...');
            $googleUser = $driver->userFromToken($accessToken);
            Log::info('DEBUG: Google Calendar: Google User details fetched.', ['email' => $googleUser->getEmail(), 'id' => $googleUser->getId()]);

            // Check if account already linked
            $socialAccount = SocialAccount::updateOrCreate(
                [
                    'user_id' => $user->id,
                    'provider' => 'google',
                    'provider_id' => $googleUser->getId(),
                ],
                [
                    'provider_email' => $googleUser->getEmail(),
                    'provider_name' => $googleUser->getName(),
                    'provider_avatar' => $googleUser->getAvatar(),
                    'access_token' => $accessToken,
                    'refresh_token' => $refreshToken, // Might be null on subsequent connects if not forced
                    'token_expires_at' => now()->addSeconds($expiresIn),
                    'scopes' => explode(' ', $scopes),
                ]
            );

            Log::info('DEBUG: Google Calendar: SocialAccount updated/created in DB.', ['social_account_id' => $socialAccount->id]);

            // Dispatch job to watch calendar for changes (Inbound Sync)
            Log::info('DEBUG: Google Calendar: Dispatching WatchGoogleCalendarJob...');
            \App\Jobs\WatchGoogleCalendarJob::dispatch($user);
            Log::info('DEBUG: Google Calendar: WatchGoogleCalendarJob dispatched.');

            Log::info('DEBUG: CalendarOAuthController::connect - EXITING SUCCESS');

            return response()->json([
                'message' => 'Google Calendar connected successfully.',
                'account' => $socialAccount,
            ]);

        } catch (\Exception $e) {
            Log::error('DEBUG: Calendar Connect Error: '.$e->getMessage(), ['trace' => $e->getTraceAsString()]);
            Log::info('DEBUG: CalendarOAuthController::connect - EXITING FAILURE');

            return response()->json(['message' => 'Failed to connect Google Calendar.'], 400);
        }
    }

    /**
     * Handle incoming Google Calendar Push Notifications (Webhooks).
     */
    public function webhook(Request $request)
    {
        // Google sends headers:
        // X-Goog-Channel-ID, X-Goog-Resource-ID, X-Goog-Resource-State, X-Goog-Changed

        $channelId = $request->header('X-Goog-Channel-ID');
        $resourceState = $request->header('X-Goog-Resource-State');

        Log::info('Google Calendar Webhook Received', [
            'headers' => $request->headers->all(),
            'channel_id' => $channelId,
            'state' => $resourceState,
            'resource_id' => $request->header('X-Goog-Resource-ID'),
        ]);

        if ($resourceState === 'sync') {
            return response('OK', 200);
        }

        if ($resourceState === 'exists') {
            // Dispatch job to process the change
            // We need to map Channel ID back to a User/Calendar
            // Typically we store channel_id -> user_id in DB (maybe in social_accounts table)
            // For now, logging payload.
            \App\Jobs\HandleGoogleWebhookJob::dispatch($channelId);
        }

        return response('OK', 200);
    }

    /**
     * Disconnect Google Calendar (Remove Scope).
     */
    public function disconnect(Request $request): JsonResponse
    {
        $user = $request->user();
        $calendarScope = 'https://www.googleapis.com/auth/calendar.events';

        $account = SocialAccount::where('user_id', $user->id)
            ->where('provider', 'google')
            ->whereJsonContains('scopes', $calendarScope)
            ->first();

        if (! $account) {
            return response()->json(['message' => 'No connected calendar found.'], 404);
        }

        // Remove the calendar scope
        $scopes = $account->scopes ?? [];
        $scopes = array_values(array_filter($scopes, fn ($s) => $s !== $calendarScope));

        $account->scopes = $scopes;
        $account->save();

        // Optional: If scopes are empty, we could delete the account,
        // but for now let's keep it as the user might be using it for login.

        Log::info('Google Calendar disconnected for user.', ['user_id' => $user->id]);

        return response()->json(['message' => 'Google Calendar disconnected.']);
    }
}
