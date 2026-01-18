<?php

namespace App\Services;

use App\Models\EmailAccount;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;

class EmailRateLimiter
{
    /**
     * Provider limits configuration.
     */
    protected const LIMITS = [
        'gmail' => [
            'connections_per_min' => 2,
            'requests_per_min' => 20,
            'delay_seconds' => 60,
        ],
        'outlook' => [
            'connections_per_min' => 3,
            'requests_per_min' => 30,
            'delay_seconds' => 30,
        ],
        'custom' => [
            'connections_per_min' => 5,
            'requests_per_min' => 50,
            'delay_seconds' => 10,
        ],
    ];

    /**
     * check if the account is allowed to proceed.
     * If rate limited, returns the number of seconds to wait.
     * If allowed, returns 0.
     */
    public function check(EmailAccount $account): int
    {
        $provider = $account->provider ?? 'custom';
        $config = self::LIMITS[$provider] ?? self::LIMITS['custom'];

        $key = "email_rate_limit:{$account->id}";

        // Check "lockout" key
        if (Cache::has("{$key}:lockout")) {
            return (int) (Cache::get("{$key}:lockout") - time());
        }

        return 0;
    }

    /**
     * Increment the request count and trigger lockout if needed.
     */
    public function hit(EmailAccount $account): void
    {
        $provider = $account->provider ?? 'custom';
        $config = self::LIMITS[$provider] ?? self::LIMITS['custom'];
        $key = "email_req_count:{$account->id}";

        $current = Cache::increment($key);

        // If it's the first hit, set expiration for 1 minute
        if ($current === 1) {
            Cache::expire($key, 60);
        }

        if ($current > $config['requests_per_min']) {
            $this->lockout($account, $config['delay_seconds']);
        }
    }

    /**
     * Acquire a "connection" slot. Returns true if acquired, false if limit reached.
     */
    public function acquireConnection(EmailAccount $account): bool
    {
        $provider = $account->provider ?? 'custom';
        $config = self::LIMITS[$provider] ?? self::LIMITS['custom'];
        $key = "email_conn_count:{$account->id}";

        $current = Cache::get($key, 0);

        if ($current >= $config['connections_per_min']) {
            $this->lockout($account, $config['delay_seconds']);

            return false;
        }

        Cache::increment($key);
        // Expire connections key after 60s
        if ($current == 0) {
            Cache::expire($key, 60);
        }

        return true;
    }

    /**
     * Lockout the account for a duration.
     */
    protected function lockout(EmailAccount $account, int $seconds): void
    {
        $key = "email_rate_limit:{$account->id}:lockout";
        // Only set if not already locked out
        if (! Cache::has($key)) {
            Log::warning("[EmailRateLimiter] Rate limit reached for account {$account->id} ({$account->email}). Cooling down for {$seconds}s.");
            Cache::put($key, time() + $seconds, $seconds);
        }
    }
}
