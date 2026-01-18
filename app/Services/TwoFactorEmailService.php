<?php

namespace App\Services;

use App\Models\User;
use App\Notifications\TwoFactorCodeNotification;
use Illuminate\Support\Facades\Cache;

class TwoFactorEmailService
{
    /**
     * Generate and send a verification code via email.
     */
    public function sendVerificationCode(User $user): bool
    {
        // Generate 6-digit code
        $code = $this->generateCode();

        // Store code in cache (expires in 10 minutes)
        $cacheKey = $this->getCacheKey($user);
        Cache::put($cacheKey, [
            'code' => $code,
            'attempts' => 0,
        ], now()->addMinutes(10));

        // Send notification
        $user->notify(new TwoFactorCodeNotification($code));

        return true;
    }

    /**
     * Verify the code submitted by the user.
     */
    public function verifyCode(User $user, string $code): bool
    {
        $cacheKey = $this->getCacheKey($user);
        $stored = Cache::get($cacheKey);

        if (! $stored) {
            return false;
        }

        // Check attempts (max 5)
        if ($stored['attempts'] >= 5) {
            Cache::forget($cacheKey);

            return false;
        }

        // Increment attempts
        Cache::put($cacheKey, [
            'code' => $stored['code'],
            'attempts' => $stored['attempts'] + 1,
        ], now()->addMinutes(10));

        if ($stored['code'] === $code) {
            Cache::forget($cacheKey);

            return true;
        }

        return false;
    }

    /**
     * Generate a random 6-digit code.
     */
    protected function generateCode(): string
    {
        return str_pad((string) random_int(0, 999999), 6, '0', STR_PAD_LEFT);
    }

    /**
     * Get the cache key for storing the verification code.
     */
    protected function getCacheKey(User $user): string
    {
        return "2fa_email_code:{$user->id}";
    }
}
