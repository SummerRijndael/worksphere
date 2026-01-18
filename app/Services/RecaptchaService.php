<?php

namespace App\Services;

use Illuminate\Support\Facades\Http;
use Illuminate\Support\Facades\Log;

class RecaptchaService
{
    protected string $secretKey;

    protected string $v2SecretKey;

    protected string $verifyUrl;

    protected float $scoreThreshold;

    protected bool $enabled;

    public function __construct()
    {
        $this->enabled = config('recaptcha.enabled', false);
        $this->secretKey = config('recaptcha.secret_key', '');
        $this->v2SecretKey = config('recaptcha.v2_secret_key', '');
        $this->verifyUrl = config('recaptcha.verify_url');
        $this->scoreThreshold = config('recaptcha.score_threshold', 0.5);
    }

    /**
     * Verify a reCAPTCHA token.
     *
     * @param  string|null  $token  The reCAPTCHA token from the frontend
     * @param  string  $action  The expected action name
     * @param  string|null  $ip  The client IP address
     * @return array{success: bool, score: float|null, error: string|null}
     */
    public function verify(?string $token, string $action = '', ?string $ip = null): array
    {
        // If reCAPTCHA is disabled, always return success
        if (! $this->enabled) {
            return [
                'success' => true,
                'score' => 1.0,
                'error' => null,
            ];
        }

        if (empty($token)) {
            return [
                'success' => false,
                'score' => null,
                'error' => 'reCAPTCHA token is required.',
            ];
        }

        if (empty($this->secretKey)) {
            Log::warning('reCAPTCHA secret key is not configured.');

            return [
                'success' => true, // Fail open if not configured
                'score' => 1.0,
                'error' => null,
            ];
        }

        try {
            $response = Http::asForm()->post($this->verifyUrl, [
                'secret' => $this->secretKey,
                'response' => $token,
                'remoteip' => $ip,
            ]);

            $result = $response->json();

            if (! $response->successful() || ! isset($result['success'])) {
                Log::error('reCAPTCHA API error', ['response' => $result]);

                return [
                    'success' => false,
                    'score' => null,
                    'error' => 'reCAPTCHA verification failed.',
                ];
            }

            if (! $result['success']) {
                $errorCodes = $result['error-codes'] ?? [];
                Log::warning('reCAPTCHA verification failed', ['errors' => $errorCodes]);

                return [
                    'success' => false,
                    'score' => null,
                    'error' => $this->getErrorMessage($errorCodes),
                ];
            }

            $score = $result['score'] ?? 0.0;
            $responseAction = $result['action'] ?? '';

            // Verify action matches (if specified)
            if (! empty($action) && $responseAction !== $action) {
                Log::warning('reCAPTCHA action mismatch', [
                    'expected' => $action,
                    'received' => $responseAction,
                ]);

                return [
                    'success' => false,
                    'score' => $score,
                    'error' => 'reCAPTCHA action mismatch.',
                ];
            }

            // Get action-specific threshold or default
            $threshold = config("recaptcha.actions.{$action}", $this->scoreThreshold);

            if ($score < $threshold) {
                Log::info('reCAPTCHA score below threshold', [
                    'score' => $score,
                    'threshold' => $threshold,
                    'action' => $action,
                ]);

                return [
                    'success' => false,
                    'score' => $score,
                    'error' => 'Suspicious activity detected. Please try again.',
                ];
            }

            return [
                'success' => true,
                'score' => $score,
                'error' => null,
            ];

        } catch (\Exception $e) {
            Log::error('reCAPTCHA verification exception', ['exception' => $e->getMessage()]);

            return [
                'success' => false,
                'score' => null,
                'error' => 'reCAPTCHA verification error.',
            ];
        }
    }

    /**
     * Verify a reCAPTCHA v2 token.
     *
     * @param  string|null  $token  The reCAPTCHA token from the frontend
     * @param  string|null  $ip  The client IP address
     * @return array{success: bool, error: string|null}
     */
    public function verifyV2(?string $token, ?string $ip = null): array
    {
        if (! $this->enabled) {
            return ['success' => true, 'error' => null];
        }

        if (empty($token)) {
            return ['success' => false, 'error' => 'reCAPTCHA v2 token is required.'];
        }

        if (empty($this->v2SecretKey)) {
            Log::warning('reCAPTCHA v2 secret key is not configured.');

            return ['success' => true, 'error' => null];
        }

        try {
            $response = Http::asForm()->post($this->verifyUrl, [
                'secret' => $this->v2SecretKey,
                'response' => $token,
                'remoteip' => $ip,
            ]);

            $result = $response->json();

            if (! $response->successful() || ! isset($result['success']) || ! $result['success']) {
                Log::warning('reCAPTCHA v2 verification failed', ['result' => $result]);

                return ['success' => false, 'error' => 'Security challenge failed.'];
            }

            return ['success' => true, 'error' => null];
        } catch (\Exception $e) {
            Log::error('reCAPTCHA v2 verification exception', ['exception' => $e->getMessage()]);

            return ['success' => false, 'error' => 'Verification error.'];
        }
    }

    /**
     * Check if reCAPTCHA is enabled.
     */
    public function isEnabled(): bool
    {
        return $this->enabled;
    }

    /**
     * Get human-readable error message from error codes.
     */
    protected function getErrorMessage(array $errorCodes): string
    {
        $messages = [
            'missing-input-secret' => 'Server configuration error.',
            'invalid-input-secret' => 'Server configuration error.',
            'missing-input-response' => 'reCAPTCHA token is required.',
            'invalid-input-response' => 'reCAPTCHA token is invalid or expired.',
            'bad-request' => 'Invalid reCAPTCHA request.',
            'timeout-or-duplicate' => 'reCAPTCHA token has expired. Please refresh and try again.',
        ];

        foreach ($errorCodes as $code) {
            if (isset($messages[$code])) {
                return $messages[$code];
            }
        }

        return 'reCAPTCHA verification failed.';
    }
}
