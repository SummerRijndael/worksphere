<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Notifications\TwoFactorCodeNotification;
use App\Services\TwoFactorSmsService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Hash;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Validation\ValidationException;
use Laravel\Fortify\Actions\ConfirmTwoFactorAuthentication;
use Laravel\Fortify\Actions\DisableTwoFactorAuthentication;
use Laravel\Fortify\Actions\EnableTwoFactorAuthentication;
use Laravel\Fortify\Actions\GenerateNewRecoveryCodes;

class TwoFactorController extends Controller
{
    protected $smsService;

    public function __construct(TwoFactorSmsService $smsService)
    {
        $this->smsService = $smsService;
    }

    /**
     * Get current 2FA status.
     *
     * Schema separation:
     * - TOTP: two_factor_secret, two_factor_confirmed_at, two_factor_recovery_codes
     * - SMS: phone, two_factor_sms_enabled, two_factor_sms_confirmed_at
     * - Email: two_factor_email_enabled (passive fallback)
     */
    public function status(Request $request): JsonResponse
    {
        $user = $request->user();

        $enabledMethods = [];
        $primaryMethods = []; // TOTP and SMS are primary methods

        // TOTP status
        $totpEnabled = $user->two_factor_secret && $user->two_factor_confirmed_at !== null;
        $totpPending = $user->two_factor_secret && $user->two_factor_confirmed_at === null;

        if ($totpEnabled) {
            $enabledMethods[] = 'totp';
            $primaryMethods[] = 'totp';
        }

        // SMS status - uses its own confirmation timestamp
        $smsEnabled = $user->two_factor_sms_enabled && $user->two_factor_sms_confirmed_at !== null;
        $smsPending = $user->two_factor_sms_enabled && $user->two_factor_sms_confirmed_at === null;

        if ($smsEnabled) {
            $enabledMethods[] = 'sms';
            $primaryMethods[] = 'sms';
        }

        // Email status
        if ($user->two_factor_email_enabled) {
            $enabledMethods[] = 'email';
        }

        // Email is a passive fallback if any primary method is enabled
        $emailIsFallback = ! empty($primaryMethods) && $user->two_factor_email_enabled;

        return response()->json([
            'enabled' => ! empty($enabledMethods),
            'confirmed' => ! empty($enabledMethods), // Deprecated
            // TOTP specific
            'totp_enabled' => $totpEnabled,
            'totp_pending' => $totpPending,
            // SMS specific
            'sms_enabled' => $smsEnabled,
            'sms_pending' => $smsPending,
            // Combined methods
            'method' => $enabledMethods[0] ?? null,
            'enabled_methods' => $enabledMethods,
            'primary_methods' => $primaryMethods,
            'email_is_fallback' => $emailIsFallback,
            'phone' => $user->phone ? $this->maskPhone($user->phone) : null,
        ]);
    }

    /**
     * Enable TOTP 2FA.
     */
    public function enableTotp(
        Request $request,
        EnableTwoFactorAuthentication $enable
    ): JsonResponse {
        try {
            \Illuminate\Support\Facades\Log::info('Enabling TOTP for user: '.$request->user()->id);
            $enable($request->user(), true);

            $user = $request->user()->fresh();

            if (! $user->two_factor_secret) {
                \Illuminate\Support\Facades\Log::error('Two factor secret is missing after enable call');

                return response()->json(['message' => 'Failed to generate secret'], 500);
            }

            return response()->json([
                'qr_code' => $user->twoFactorQrCodeSvg(),
                'secret' => decrypt($user->two_factor_secret),
            ]);
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('TOTP Enable Error: '.$e->getMessage());
            \Illuminate\Support\Facades\Log::error($e->getTraceAsString());

            return response()->json(['message' => 'Server Error: '.$e->getMessage()], 500);
        }
    }

    /**
     * Confirm TOTP 2FA.
     */
    public function confirmTotp(
        Request $request,
        ConfirmTwoFactorAuthentication $confirm
    ): JsonResponse {
        $request->validate([
            'code' => ['required', 'string'],
        ]);

        $confirm($request->user(), $request->code);

        // Auto-enable email 2FA as passive fallback when any primary method is enabled
        if (! $request->user()->two_factor_email_enabled) {
            $request->user()->update(['two_factor_email_enabled' => true]);
        }

        return response()->json([
            'message' => 'Two-factor authentication enabled.',
        ]);
    }

    /**
     * Disable TOTP 2FA.
     */
    public function disable(
        Request $request,
        DisableTwoFactorAuthentication $disable
    ): JsonResponse {
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        if (! Hash::check($request->password, $request->user()->password)) {
            throw ValidationException::withMessages([
                'password' => ['The provided password is incorrect.'],
            ]);
        }

        $disable($request->user());

        return response()->json([
            'message' => 'Authenticator App disabled.',
        ]);
    }

    /**
     * Get recovery codes.
     */
    public function recoveryCodes(Request $request): JsonResponse
    {
        return response()->json(
            json_decode(decrypt($request->user()->two_factor_recovery_codes), true)
        );
    }

    /**
     * Regenerate recovery codes.
     */
    public function regenerateRecoveryCodes(
        Request $request,
        GenerateNewRecoveryCodes $generate
    ): JsonResponse {
        $generate($request->user());

        return response()->json(
            json_decode(decrypt($request->user()->two_factor_recovery_codes), true)
        );
    }

    /**
     * Enable SMS 2FA.
     */
    public function enableSms(Request $request): JsonResponse
    {
        $request->validate([
            'phone' => ['required', 'string', 'regex:/^\+?[1-9]\d{1,14}$/'],
        ]);

        $user = $request->user();

        // Store phone number
        $user->update([
            'phone' => $request->phone,
        ]);

        return response()->json([
            'message' => 'Phone number saved. Verification code will be sent.',
        ]);
    }

    /**
     * Send SMS verification code.
     */
    public function sendSmsCode(Request $request): JsonResponse
    {
        $user = $request->user();

        if (! $user->phone) {
            throw ValidationException::withMessages([
                'phone' => ['No phone number on file.'],
            ]);
        }

        // Rate Limiting: 1 per 60s
        $key = '2fa-setup-sms:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 1)) {
            $seconds = RateLimiter::availableIn($key);

            return response()->json([
                'message' => "Please wait {$seconds} seconds before requesting another code.",
                'retry_after' => $seconds,
            ], 429);
        }

        $this->smsService->sendVerificationCode($user);
        RateLimiter::hit($key, 60);

        return response()->json([
            'message' => 'Verification code sent.',
        ]);
    }

    /**
     * Verify SMS code and enable.
     */
    public function verifySmsCode(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['required', 'string', 'size:6'],
        ]);

        $user = $request->user();

        if (! $this->smsService->verifyCode($user, $request->code)) {
            throw ValidationException::withMessages([
                'code' => ['Invalid verification code.'],
            ]);
        }

        $user->update([
            'two_factor_sms_enabled' => true,
            'two_factor_sms_confirmed_at' => now(), // Explicit SMS 2FA confirmation
            'phone_verified_at' => now(), // General phone verification
        ]);

        // Auto-enable email 2FA as passive fallback when any primary method is enabled
        if (! $user->two_factor_email_enabled) {
            $user->update(['two_factor_email_enabled' => true]);
        }

        return response()->json([
            'message' => 'SMS two-factor authentication enabled.',
        ]);
    }

    /**
     * Disable SMS 2FA.
     */
    public function disableSms(Request $request): JsonResponse
    {
        $request->user()->update([
            'two_factor_sms_enabled' => false,
            'two_factor_sms_confirmed_at' => null,
        ]);

        return response()->json([
            'message' => 'SMS two-factor authentication disabled.',
        ]);
    }

    /**
     * Enable Email 2FA fallback.
     */
    public function enableEmail(Request $request): JsonResponse
    {
        $request->user()->update([
            'two_factor_email_enabled' => true,
        ]);

        return response()->json([
            'message' => 'Email two-factor authentication enabled.',
        ]);
    }

    /**
     * Disable Email 2FA.
     */
    public function disableEmail(Request $request): JsonResponse
    {
        $request->user()->update([
            'two_factor_email_enabled' => false,
        ]);

        return response()->json([
            'message' => 'Email two-factor authentication disabled.',
        ]);
    }

    /**
     * Get available 2FA methods for challenge.
     */
    public function challengeMethods(Request $request): JsonResponse
    {
        // Get the user from the session (during 2FA challenge)
        $userId = $request->session()->get('login.id');

        if (! $userId) {
            return response()->json(['methods' => []], 403);
        }

        $user = User::find($userId);

        if (! $user) {
            return response()->json(['methods' => []], 403);
        }

        $methods = [];

        if ($user->two_factor_secret && $user->two_factor_confirmed_at) {
            $methods[] = 'totp';
        }

        if ($user->two_factor_sms_enabled && $user->two_factor_sms_confirmed_at) {
            $methods[] = 'sms';
        }

        if ($user->two_factor_email_enabled) {
            $methods[] = 'email';
        }

        return response()->json([
            'methods' => $methods,
            'phone' => $user->phone ? $this->maskPhone($user->phone) : null,
            'email' => $this->maskEmail($user->email),
        ]);
    }

    /**
     * Send challenge code (SMS or Email).
     */
    public function sendChallengeCode(Request $request): JsonResponse
    {
        $request->validate([
            'method' => ['required', 'string', 'in:sms,email'],
        ]);

        $userId = $request->session()->get('login.id');

        if (! $userId) {
            return response()->json(['message' => 'Session expired or invalid'], 401);
        }

        $user = User::find($userId);

        if (! $user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        // Rate Limiting: 1 per 60s
        $key = '2fa-send:'.$user->id;
        if (RateLimiter::tooManyAttempts($key, 1)) {
            $seconds = RateLimiter::availableIn($key);

            return response()->json(['message' => "Please wait {$seconds} seconds."], 429);
        }

        if ($request->method === 'sms') {
            if (! $user->phone) {
                return response()->json(['message' => 'No phone number available'], 400);
            }

            if ($this->smsService->sendVerificationCode($user)) {
                RateLimiter::hit($key, 60);

                return response()->json(['message' => 'SMS code sent']);
            }

            return response()->json(['message' => 'Failed to send SMS'], 500);
        }

        if ($request->method === 'email') {
            if (! $user->email) {
                return response()->json(['message' => 'No email available'], 400);
            }

            // Generate and cache code (10 mins)
            $code = (string) random_int(100000, 999999);
            Cache::put('two_factor_code:email:'.$user->id, $code, now()->addMinutes(10));

            $user->notify(new TwoFactorCodeNotification($code));

            RateLimiter::hit($key, 60);

            return response()->json(['message' => 'Email code sent']);
        }

        return response()->json(['message' => 'Invalid method'], 400);
    }

    /**
     * Verify challenge code.
     */
    public function verifyChallenge(Request $request): JsonResponse
    {
        $request->validate([
            'code' => ['nullable', 'string'],
            'method' => ['nullable', 'string', 'in:sms,email,totp'],
            'recovery_code' => ['nullable', 'string'],
        ]);

        $userId = $request->session()->get('login.id');
        if (! $userId) {
            return response()->json(['message' => 'Session expired or invalid'], 401);
        }
        $user = User::find($userId);

        if (! $user) {
            return response()->json(['message' => 'User not found'], 404);
        }

        if ($request->recovery_code) {
            // Decrypt recovery codes
            $valid = false;
            $codes = $user->two_factor_recovery_codes ? json_decode(decrypt($user->two_factor_recovery_codes), true) : [];

            if (($key = array_search($request->recovery_code, $codes)) !== false) {
                unset($codes[$key]);
                $user->forceFill([
                    'two_factor_recovery_codes' => encrypt(json_encode(array_values($codes))),
                ])->save();
                $valid = true;
            }

            if (! $valid) {
                throw ValidationException::withMessages(['recovery_code' => ['Invalid recovery code']]);
            }
        } else {
            // Verify Code based on Method
            $valid = false;
            $method = $request->input('method');

            if ($method === 'totp' || ! $method) {
                if (! $user->two_factor_secret) {
                    throw ValidationException::withMessages(['code' => ['Two factor authentication is not enabled.']]);
                }
                $valid = app(\Laravel\Fortify\Contracts\TwoFactorAuthenticationProvider::class)->verify(
                    decrypt($user->two_factor_secret), $request->code
                );
            } elseif ($method === 'sms') {
                $valid = $this->smsService->verifyCode($user, $request->code);
            } elseif ($method === 'email') {
                $cachedCode = Cache::get('two_factor_code:email:'.$user->id);
                if ($cachedCode && $cachedCode === $request->code) {
                    $valid = true;
                    Cache::forget('two_factor_code:email:'.$user->id);
                }
            }

            if (! $valid) {
                 \Illuminate\Support\Facades\Log::warning('2FA Verify Failed', ['user_id' => $user->id]);
                throw ValidationException::withMessages(['code' => ['Invalid verification code']]);
            }
        }

        \Illuminate\Support\Facades\Log::info('2FA Verify Success', ['user_id' => $user->id]);

        // Login
        Auth::login($user, $request->session()->get('login.remember', false));
        $request->session()->forget('login.id');
        $request->session()->regenerate();

        \Illuminate\Support\Facades\Log::info('2FA Session Regenerated', ['new_session_id' => $request->session()->getId()]);

        // Return user data directly to avoid race condition with subsequent fetchUser call
        $user->load(['roles', 'permissions', 'teams']);

        return response()->json([
            'message' => 'Two-factor authentication verified',
            'redirect' => '/dashboard',
            'user' => new \App\Http\Resources\UserResource($user),
        ]);
    }

    protected function maskPhone(string $phone): string
    {
        return substr($phone, 0, 3).'****'.substr($phone, -4);
    }

    protected function maskEmail(string $email): string
    {
        $parts = explode('@', $email);
        $name = $parts[0];
        $domain = $parts[1];

        $maskedName = substr($name, 0, 1).'****'.substr($name, -1);

        return $maskedName.'@'.$domain;
    }

    public function confirmPassword(Request $request): JsonResponse
    {
        $request->validate([
            'password' => ['required', 'string'],
        ]);

        $user = $request->user();

        if (! Hash::check($request->password, $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['The provided password is incorrect.'],
            ]);
        }

        return response()->json(['message' => 'Password confirmed']);
    }
}
