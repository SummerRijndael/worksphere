<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Log;
use Laragear\WebAuthn\Assertion\Validator\AssertionValidation;
use Laragear\WebAuthn\Assertion\Validator\AssertionValidator;
use Laragear\WebAuthn\Http\Requests\AssertedRequest;
use Laragear\WebAuthn\Http\Requests\AssertionRequest;
use Laragear\WebAuthn\Http\Requests\AttestationRequest;
use Laragear\WebAuthn\Http\Requests\AttestedRequest;

class PasskeyController extends Controller
{
    /**
     * Get user's registered passkeys.
     */
    public function index(Request $request): JsonResponse
    {
        $passkeys = $request->user()->webAuthnCredentials()
            ->select(['id', 'alias', 'created_at', 'updated_at'])
            ->get()
            ->map(fn ($credential) => [
                'id' => $credential->id,
                'name' => $credential->alias ?? 'Passkey',
                'created_at' => $credential->created_at?->toISOString(),
                'last_used' => $credential->updated_at?->toISOString(),
            ]);

        return response()->json($passkeys);
    }

    /**
     * Get registration options for creating a new passkey.
     */
    public function registerOptions(AttestationRequest $request): JsonResponse
    {
        return response()->json(
            $request->toCreate()
        );
    }

    /**
     * Store a new passkey after browser registration.
     */
    public function store(AttestedRequest $request): JsonResponse
    {
        $data = [];

        // Update alias if provided
        if ($request->has('name')) {
            $data['alias'] = $request->input('name');
        }

        $credentialId = $request->save($data);

        $credential = $request->user()->webAuthnCredentials()->findOrFail($credentialId);

        return response()->json([
            'message' => 'Passkey registered successfully.',
            'passkey' => [
                'id' => $credential->id,
                'name' => $credential->alias ?? 'Passkey',
                'created_at' => $credential->created_at?->toISOString(),
            ],
        ], 201);
    }

    /**
     * Delete a passkey.
     */
    public function destroy(Request $request, string $id): JsonResponse
    {
        $credential = $request->user()->webAuthnCredentials()->findOrFail($id);
        $credential->delete();

        return response()->json([
            'message' => 'Passkey deleted successfully.',
        ]);
    }

    /**
     * Update passkey name.
     */
    public function update(Request $request, string $id): JsonResponse
    {
        $request->validate([
            'name' => 'required|string|max:255',
        ]);

        $credential = $request->user()->webAuthnCredentials()->findOrFail($id);
        $credential->update(['alias' => $request->input('name')]);

        return response()->json([
            'message' => 'Passkey updated successfully.',
        ]);
    }

    // ==================== Guest Authentication ====================

    /**
     * Get login options for passkey authentication.
     */
    public function loginOptions(AssertionRequest $request): JsonResponse
    {
        // For "userless" passkey login
        return response()->json(
            $request->toVerify()
        );
    }

    /**
     * Authenticate with a passkey.
     */
    public function login(AssertedRequest $request): JsonResponse
    {
        try {
            $validation = AssertionValidation::fromRequest($request);
            $result = app(AssertionValidator::class)->send($validation)->thenReturn();

            $user = $result->user;

            if (! $user) {
                // Fallback: If relation failed to load but ID exists (common in some envs)
                $cred = $result->credential;
                if ($cred && $cred->authenticatable_id) {
                    $user = \App\Models\User::find($cred->authenticatable_id);
                }

                if (! $user) {
                    throw new \Exception('User not found in assertion.');
                }
            }

            if (! $user->canLogin()) {
                Auth::logout();
                $request->session()->invalidate();

                $statusConfig = config('roles.statuses.'.$user->status, []);
                $reason = $user->status_reason ?? ($statusConfig['label'] ?? 'Account disabled');

                Log::warning('Passkey login denied: User cannot login', ['user_id' => $user->id, 'status' => $user->status]);

                return response()->json([
                    'message' => $reason,
                ], 403);
            }

            // Check if 2FA is enabled and confirmed
            $has2FA = $user->two_factor_secret && ! is_null($user->two_factor_confirmed_at);
            $hasSms2FA = $user->two_factor_sms_enabled && ! is_null($user->two_factor_sms_confirmed_at) && $user->phone;
            $hasEmail2FA = $user->two_factor_email_enabled;

            if ($has2FA || $hasSms2FA || $hasEmail2FA) {
                // Store user ID in session for 2FA challenge
                $request->session()->put('login.id', $user->id);
                $request->session()->put('login.remember', true); // Passkeys imply "something you have", often treated as persistent

                // Log out any existing session to be safe
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

            Auth::login($user);
            $request->session()->regenerate();
            $user->recordLogin($request->ip());

            return response()->json([
                'message' => 'Login successful.',
                'data' => [
                    'user' => new UserResource($user),
                ],
            ]);
        } catch (\Throwable $e) {
            Log::error('Passkey login failed', [
                'error' => $e->getMessage(),
                'trace' => $e->getTraceAsString(),
            ]);

            return response()->json([
                'message' => 'Passkey authentication failed.',
                'debug' => config('app.debug') ? $e->getMessage() : null,
            ], 401);
        }
    }
}
