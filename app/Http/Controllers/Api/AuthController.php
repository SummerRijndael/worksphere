<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Requests\Auth\LoginRequest;
use App\Http\Requests\Auth\RegisterRequest;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Registered;
use Illuminate\Auth\Events\Verified;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\Password;
use Illuminate\Support\Str;
use Illuminate\Validation\ValidationException;
use Laravel\Socialite\Facades\Socialite;

class AuthController extends Controller
{
    /**
     * Register a new user.
     */
    public function register(RegisterRequest $request): JsonResponse
    {
        $userData = [
            'name' => $request->name,
            'email' => $request->email,
            'password' => $request->password, // Password is hashed in model mutator or usually manually here? User::create likely doesn't hash automatically unless cast/mutator exists. Standard Laravel requires Hash::make.
            'status' => 'active',
            'is_password_set' => true,
            'password_last_updated_at' => now(),
            'preferences' => [
                'appearance' => [
                    'mode' => 'system',
                    'color' => 'default',
                ],
                'notifications' => [
                    'email' => true,
                    'push' => true,
                ],
            ],
        ];
        // Hash password if not handled by model
        $userData['password'] = Hash::make($request->password);

        // Generate username if not provided
        $userData['username'] = $request->username ?? explode('@', $request->email)[0].rand(100, 999);

        // Auto-verify if email verification is disabled
        if (! config('auth.email_verification', true)) {
            $userData['email_verified_at'] = now();
        }

        $user = User::create($userData);

        // Assign default role
        $defaultRole = config('roles.default_role', 'user');
        $user->assignRole($defaultRole);

        // Dispatch Registered event - this triggers SendEmailVerificationNotification listener
        // which calls $user->sendEmailVerificationNotification() automatically
        event(new Registered($user));

        // Manual notification removed to prevent double email loop

        Auth::login($user);
        $user->recordLogin($request->ip());

        return response()->json([
            'message' => 'Registration successful. Please verify your email.',
            'data' => [
                'user' => new UserResource($user),
            ],
        ], 201);
    }

    public function verifySocialLink(Request $request): JsonResponse
    {
        $request->validate([
            'token' => 'required|string',
        ]);

        $data = \Illuminate\Support\Facades\Cache::pull("social_link_{$request->token}");

        if (! $data) {
            return response()->json(['message' => 'Invalid or expired link token.'], 400);
        }

        $user = User::find($data['user_id']);

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        // Check if this provider is already linked to this user
        if ($user->hasSocialProvider($data['provider'])) {
            return response()->json(['message' => 'This social account is already linked.'], 400);
        }

        // Create social account entry
        \App\Models\SocialAccount::create([
            'user_id' => $user->id,
            'provider' => $data['provider'],
            'provider_id' => $data['provider_id'],
            'provider_email' => $data['provider_email'] ?? null,
            'provider_avatar' => $data['provider_avatar'] ?? null,
            'provider_name' => $data['provider_name'] ?? null,
        ]);

        // Sync avatar using AvatarService
        if ($avatarUrl = $data['provider_avatar']) {
            app(\App\Contracts\AvatarContract::class)->syncFromSocial($avatarUrl, $user);
        }

        return response()->json([
            'message' => 'Account linked successfully. You can now login with '.ucfirst($data['provider']).'.',
        ]);
    }

    /**
     * Authenticate user and create session.
     */
    protected \App\Services\RecaptchaService $recaptcha;

    public function __construct(\App\Services\RecaptchaService $recaptcha)
    {
        $this->recaptcha = $recaptcha;
    }

    /**
     * Authenticate user and create session.
     */
    public function login(LoginRequest $request): JsonResponse
    {
        // ReCAPTCHA Verification
        if (config('recaptcha.enabled')) {
            $token = $request->input('recaptcha_token');
            $v2Token = $request->input('recaptcha_v2_token');

            if ($v2Token) {
                // Verify V2 challenge
                $verification = $this->recaptcha->verifyV2($v2Token, $request->ip());
                if (! $verification['success']) {
                    throw ValidationException::withMessages([
                        'message' => $verification['error'] ?? 'Security challenge failed.',
                    ]);
                }
            } elseif ($token) {
                // Verify V3 score
                $verification = $this->recaptcha->verify($token, 'login');
                if (! $verification['success']) {
                    // If verification failed but we have a score, it means the score was too low.
                    // We should trigger the V2 challenge regardless of the specific threshold used by the service.
                    if (isset($verification['score'])) {
                        return response()->json([
                            'message' => 'Security check required.',
                            'requires_challenge' => true,
                        ], 422);
                    }

                    throw ValidationException::withMessages([
                        'message' => $verification['error'] ?? 'Security check failed.',
                    ]);
                }
            }
            // If enabled but no token provided, we might want to enforce it,
            // but `LoginView` only sends it if `recaptchaEnabled` is true on frontend.
            // We'll assume frontend sends it if config is enabled.
        }

        $request->authenticate();

        $user = Auth::user();

        if (! $user->canLogin()) {
            Auth::logout();
            $request->session()->invalidate();

            $reason = 'Account disabled';

            if ($user->status === 'suspended' && $user->suspended_until?->isFuture()) {
                $reason = 'Account suspended until '.$user->suspended_until->format('M d, Y H:i');
            } elseif ($user->status_reason) {
                $reason = $user->status_reason;
            } else {
                $statusConfig = config('roles.statuses.'.$user->status, []);
                $reason = $statusConfig['label'] ?? 'Account disabled';
            }

            throw ValidationException::withMessages([
                'email' => [$reason],
            ]);
        }

        // Check if 2FA is enabled and confirmed
        // Check if 2FA is enabled and confirmed
        $has2FA = $user->two_factor_secret && ! is_null($user->two_factor_confirmed_at);
        $hasSms2FA = $user->two_factor_sms_enabled && ! is_null($user->two_factor_sms_confirmed_at) && $user->phone;
        $hasEmail2FA = $user->two_factor_email_enabled;

        if ($has2FA || $hasSms2FA || $hasEmail2FA) {
            // Store user ID in session for 2FA challenge (don't logout to preserve CSRF)
            $request->session()->put('login.id', $user->id);
            $request->session()->put('login.remember', $request->boolean('remember'));

            // Log out without regenerating session to preserve CSRF token
            Auth::guard('web')->logout();

            // Get available methods
            $methods = [];
            if ($has2FA) {
                $methods[] = 'totp';
            }
            if ($hasSms2FA) {
                $methods[] = 'sms';
            }
            if ($hasEmail2FA) {
                $methods[] = 'email';
            }

            return response()->json([
                'data' => [
                    'requires_2fa' => true,
                    'methods' => $methods,
                ],
            ]);
        }

        $request->session()->regenerate();
        $user->recordLogin($request->ip());

        return response()->json([
            'message' => 'Login successful.',
            'data' => [
                'user' => new UserResource($user),
            ],
        ]);
    }

    /**
     * Get authenticated user.
     */
    public function user(Request $request): JsonResponse
    {
        return response()->json([
            'data' => new UserResource($request->user()->load('teams')),
        ]);
    }

    /**
     * Logout user and invalidate session.
     */
    public function logout(Request $request): JsonResponse
    {
        Auth::guard('web')->logout();

        $request->session()->invalidate();
        $request->session()->regenerateToken();

        return response()->json([
            'data' => [
                'message' => 'Logged out successfully.',
            ],
        ]);
    }

    /**
     * Send password reset link.
     */
    public function forgotPassword(Request $request): JsonResponse
    {
        $request->validate([
            'email' => ['required', 'email'],
        ]);

        $status = Password::sendResetLink(
            $request->only('email')
        );

        if ($status === Password::RESET_LINK_SENT) {
            return response()->json([
                'data' => [
                    'message' => 'Password reset link sent to your email.',
                ],
            ]);
        }

        throw ValidationException::withMessages([
            'email' => [__($status)],
        ]);
    }

    /**
     * Reset password with token.
     */
    public function resetPassword(Request $request): JsonResponse
    {
        $request->validate([
            'token' => ['required'],
            'email' => ['required', 'email'],
            'password' => ['required', 'confirmed', 'min:8'],
        ]);

        $status = Password::reset(
            $request->only('email', 'password', 'password_confirmation', 'token'),
            function (User $user, string $password): void {
                $user->forceFill([
                    'password' => Hash::make($password),
                    'remember_token' => Str::random(60),
                ])->save();

                event(new PasswordReset($user));
            }
        );

        if ($status === Password::PASSWORD_RESET) {
            return response()->json([
                'data' => [
                    'message' => 'Password has been reset successfully.',
                ],
            ]);
        }

        throw ValidationException::withMessages([
            'email' => [__($status)],
        ]);
    }

    /**
     * Send email verification notification.
     */
    public function sendVerificationEmail(Request $request): JsonResponse
    {
        if ($request->user()->hasVerifiedEmail()) {
            return response()->json([
                'data' => [
                    'message' => 'Email already verified.',
                ],
            ]);
        }

        $request->user()->sendEmailVerificationNotification();

        return response()->json([
            'data' => [
                'message' => 'Verification link sent.',
            ],
        ]);
    }

    /**
     * Verify email with signed URL.
     */
    public function verifyEmail(Request $request, int $id, string $hash): JsonResponse
    {
        $user = User::findOrFail($id);

        if (! hash_equals((string) $hash, sha1($user->getEmailForVerification()))) {
            return response()->json([
                'data' => [
                    'message' => 'Invalid verification link.',
                ],
            ], 400);
        }

        if ($user->hasVerifiedEmail()) {
            return response()->json([
                'data' => [
                    'message' => 'Email already verified.',
                ],
            ]);
        }

        if ($user->markEmailAsVerified()) {
            event(new Verified($user));
        }

        return response()->json([
            'data' => [
                'message' => 'Email verified successfully.',
            ],
        ]);
    }

    /**
     * Redirect to social provider.
     */
    public function socialRedirect(string $provider): JsonResponse
    {
        $this->validateProvider($provider);

        $url = Socialite::driver($provider)
            ->stateless()
            ->redirect()
            ->getTargetUrl();

        return response()->json([
            'url' => $url,
        ]);
    }

    /**
     * Handle social provider callback.
     */
    /**
     * Handle social provider callback (API).
     */
    public function socialCallback(Request $request, string $provider)
    {
        $result = $this->handleSocialCallbackLogic($provider);

        $frontendUrl = config('app.frontend_url', config('app.url'));

        if ($result['status'] === 'error') {
            return redirect($frontendUrl.'/auth/login?error='.urlencode($result['message']));
        }

        if ($result['status'] === 'verification_required') {
            return redirect($frontendUrl.'/auth/login?verification_required=1&message='.urlencode($result['message']));
        }

        $user = $result['user'];

        if (! $user->canLogin()) {
            $statusConfig = config('roles.statuses.'.$user->status, []);
            $reason = $user->status_reason ?? ($statusConfig['label'] ?? 'Account disabled');

            return redirect($frontendUrl.'/auth/login?error='.urlencode($reason));
        }

        // Check if 2FA is enabled and confirmed (Logic copied from login method)
        $has2FA = $user->two_factor_secret && ! is_null($user->two_factor_confirmed_at);
        $hasSms2FA = $user->two_factor_sms_enabled && ! is_null($user->two_factor_sms_confirmed_at) && $user->phone;
        $hasEmail2FA = $user->two_factor_email_enabled;

        \Illuminate\Support\Facades\Log::info('Social Login 2FA Check:', [
            'user_id' => $user->id,
            'has2FA' => $has2FA,
            'hasSms2FA' => $hasSms2FA,
            'sms_enabled' => $user->two_factor_sms_enabled,
            'sms_confirmed' => $user->two_factor_sms_confirmed_at,
            'phone' => $user->phone,
            'hasEmail2FA' => $hasEmail2FA,
        ]);

        if ($has2FA || $hasSms2FA || $hasEmail2FA) {
            // Store user ID in session for 2FA challenge
            $request->session()->put('login.id', $user->id);

            // Log out any existing session to be safe
            Auth::guard('web')->logout();

            // Redirect to login page with 2FA action
            return redirect($frontendUrl.'/auth/login?action=2fa');
        }

        Auth::login($user);
        $user->recordLogin($request->ip());

        return redirect($frontendUrl.'/dashboard');
    }

    /**
     * Handle social provider callback (Web).
     */
    public function webSocialCallback(Request $request, string $provider)
    {
        $result = $this->handleSocialCallbackLogic($provider);
        $frontendUrl = config('app.frontend_url', config('app.url'));

        if ($result['status'] === 'error') {
            return redirect($frontendUrl.'/auth/login?error='.urlencode($result['message']));
        }

        if ($result['status'] === 'verification_required') {
            return redirect($frontendUrl.'/auth/login?verification_required=1&message='.urlencode($result['message']));
        }

        $user = $result['user'];

        if (! $user->canLogin()) {
            $statusConfig = config('roles.statuses.'.$user->status, []);
            $reason = $user->status_reason ?? ($statusConfig['label'] ?? 'Account disabled');

            return redirect($frontendUrl.'/auth/login?error='.urlencode($reason));
        }

        // Check if 2FA is enabled and confirmed (Logic copied from login method)
        $has2FA = $user->two_factor_secret && ! is_null($user->two_factor_confirmed_at);
        $hasSms2FA = $user->two_factor_sms_enabled && ! is_null($user->two_factor_sms_confirmed_at) && $user->phone;
        $hasEmail2FA = $user->two_factor_email_enabled;

        \Illuminate\Support\Facades\Log::info('Web Social Login 2FA Check:', [
            'user_id' => $user->id,
            'has2FA' => $has2FA,
            'hasSms2FA' => $hasSms2FA,
            'sms_enabled' => $user->two_factor_sms_enabled,
            'sms_confirmed' => $user->two_factor_sms_confirmed_at,
            'phone' => $user->phone,
            'hasEmail2FA' => $hasEmail2FA,
        ]);

        if ($has2FA || $hasSms2FA || $hasEmail2FA) {
            // Store user ID in session for 2FA challenge
            $request->session()->put('login.id', $user->id);

            // Log out any existing session to be safe
            Auth::guard('web')->logout();

            // Redirect to login page with 2FA action
            return redirect($frontendUrl.'/auth/login?action=2fa');
        }

        Auth::login($user);
        $user->recordLogin($request->ip());

        return redirect($frontendUrl.'/dashboard');
    }

    /**
     * Shared logic for social login.
     */
    protected function handleSocialCallbackLogic(string $provider): array
    {
        $this->validateProvider($provider);

        try {
            $socialUser = Socialite::driver($provider)->stateless()->user();
        } catch (\Exception $e) {
            return ['status' => 'error', 'message' => 'Unable to authenticate with '.$provider.'.'];
        }

        // First, check the new social_accounts table
        $socialAccount = \App\Models\SocialAccount::where('provider', $provider)
            ->where('provider_id', $socialUser->getId())
            ->first();

        if ($socialAccount) {
            $user = $socialAccount->user;

            // Update provider data if changed
            $socialAccount->update([
                'provider_email' => $socialUser->getEmail(),
                'provider_avatar' => $socialUser->getAvatar(),
                'provider_name' => $socialUser->getName(),
            ]);

            if (! $user->canLogin()) {
                $statusConfig = config('roles.statuses.'.$user->status, []);
                $reason = $user->status_reason ?? ($statusConfig['label'] ?? 'Account disabled');

                return ['status' => 'error', 'message' => $reason, 'code' => 403];
            }

            return ['status' => 'success', 'user' => $user];
        }

        // Legacy: Check users.provider column for backward compatibility
        $user = User::where('provider', $provider)
            ->where('provider_id', $socialUser->getId())
            ->first();

        if ($user) {
            // Migrate legacy provider to social_accounts table
            \App\Models\SocialAccount::create([
                'user_id' => $user->id,
                'provider' => $provider,
                'provider_id' => $socialUser->getId(),
                'provider_email' => $socialUser->getEmail(),
                'provider_avatar' => $socialUser->getAvatar(),
                'provider_name' => $socialUser->getName(),
            ]);

            if (! $user->canLogin()) {
                $statusConfig = config('roles.statuses.'.$user->status, []);
                $reason = $user->status_reason ?? ($statusConfig['label'] ?? 'Account disabled');

                return ['status' => 'error', 'message' => $reason, 'code' => 403];
            }

            return ['status' => 'success', 'user' => $user];
        }

        // No existing social account found - check if email exists
        $existingUser = User::where('email', $socialUser->getEmail())->first();

        if ($existingUser) {
            // SECURITY: Send verification email for account linking
            $token = \Illuminate\Support\Str::random(64);
            \Illuminate\Support\Facades\Cache::put("social_link_{$token}", [
                'user_id' => $existingUser->id,
                'provider' => $provider,
                'provider_id' => $socialUser->getId(),
                'provider_email' => $socialUser->getEmail(),
                'provider_avatar' => $socialUser->getAvatar(),
                'provider_name' => $socialUser->getName(),
            ], now()->addMinutes(60));

            $existingUser->notify(new \App\Notifications\SocialAccountLinkNotification($provider, $token));

            return [
                'status' => 'verification_required',
                'message' => 'An account with this email already exists. We have sent you an email to verify and link this social account.',
            ];
        }

        // Create new user with social account
        $user = User::create([
            'name' => $socialUser->getName() ?? $socialUser->getNickname(),
            'email' => $socialUser->getEmail(),
            'username' => $socialUser->getNickname() ?? explode('@', $socialUser->getEmail())[0].rand(100, 999),
            'email_verified_at' => null, // Do not auto-verify, require email verification
            'password' => \Illuminate\Support\Facades\Hash::make(\Illuminate\Support\Str::random(32)),
            'status' => 'active',
            'is_password_set' => false,
            'preferences' => [
                'appearance' => [
                    'mode' => 'system',
                    'color' => 'default',
                ],
                'notifications' => [
                    'email' => true,
                    'push' => true,
                ],
            ],
        ]);

        // Create social account entry
        \App\Models\SocialAccount::create([
            'user_id' => $user->id,
            'provider' => $provider,
            'provider_id' => $socialUser->getId(),
            'provider_email' => $socialUser->getEmail(),
            'provider_avatar' => $socialUser->getAvatar(),
            'provider_name' => $socialUser->getName(),
        ]);

        // Sync avatar using AvatarService
        if ($avatarUrl = $socialUser->getAvatar()) {
            app(\App\Contracts\AvatarContract::class)->syncFromSocial($avatarUrl, $user);
        }

        $defaultRole = config('roles.default_role', 'user');
        $user->assignRole($defaultRole);

        $token = Password::createToken($user);
        $resetUrl = url(config('app.url').'/reset-password?token='.$token.'&email='.urlencode($user->email));

        $user->notify(new \App\Notifications\WelcomeEmailNotification(true, $resetUrl, 'Set Password & Access Dashboard'));

        return ['status' => 'success', 'user' => $user];
    }

    /**
     * Validate social provider.
     */
    protected function validateProvider(string $provider): void
    {
        // Check global social login setting
        // Assuming settings are loaded into config, or we access via service/model.
        // For now, relying on config if populated, defaulting to true to not break if missing.
        if (! config('auth.social_login_enabled', true)) {
            abort(404, 'Social login is disabled.');
        }

        $allowed = ['google', 'github', 'facebook'];

        if (! in_array($provider, $allowed)) {
            abort(404, 'Provider not supported.');
        }
    }

    /**
     * Get public authentication config.
     */
    public function config(): JsonResponse
    {
        return response()->json([
            'social_login_enabled' => config('auth.social_login_enabled', true),
            'recaptcha_enabled' => config('recaptcha.enabled', false),
            'recaptcha_site_key' => config('recaptcha.site_key'),
        ]);
    }

    /**
     * Get user hint by public_id for returning user flow.
     * Returns only non-sensitive data (masked email, first name, avatar).
     */
    public function userHint(string $public_id): JsonResponse
    {
        $user = User::where('public_id', $public_id)->first();

        if (! $user) {
            return response()->json(['message' => 'User not found.'], 404);
        }

        // Mask the email for display
        $email = $user->email;
        $maskedEmail = null;
        if ($email) {
            [$local, $domain] = explode('@', $email);
            if (strlen($local) <= 2) {
                $maskedEmail = $local[0].'***@'.$domain;
            } else {
                $maskedEmail = $local[0].'***'.$local[strlen($local) - 1].'@'.$domain;
            }
        }

        return response()->json([
            'name' => explode(' ', $user->name)[0] ?? 'User', // First name only
            'masked_email' => $maskedEmail,
            'avatar_url' => $user->avatar_url,
            'initials' => $user->initials,
        ]);
    }
}
