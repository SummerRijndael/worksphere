<?php

namespace App\Jobs\Middleware;

use Closure;
use Illuminate\Support\Facades\Redis;

/**
 * Rate limiting middleware for mail jobs.
 *
 * Prevents sending too many emails too quickly which can trigger
 * SMTP provider bot protection or rate limits.
 */
class RateLimitedMail
{
    /**
     * Maximum emails allowed in the time window.
     */
    protected int $maxEmails;

    /**
     * Time window in seconds.
     */
    protected int $windowSeconds;

    /**
     * Create a new middleware instance.
     *
     * @param  int  $maxEmails  Maximum emails per window (default: 2)
     * @param  int  $windowSeconds  Time window in seconds (default: 5)
     */
    public function __construct(int $maxEmails = 2, int $windowSeconds = 5)
    {
        $this->maxEmails = $maxEmails;
        $this->windowSeconds = $windowSeconds;
    }

    /**
     * Process the queued job.
     *
     * @param  mixed  $job
     * @return mixed
     */
    public function handle($job, Closure $next)
    {
        $key = 'mail_rate_limit';

        // Using Redis to track emails sent
        $currentCount = Redis::get($key) ?? 0;

        if ($currentCount >= $this->maxEmails) {
            // Rate limit exceeded - release job back to queue with delay
            $job->release($this->windowSeconds);

            return;
        }

        // Increment the counter
        Redis::incr($key);

        // Set expiry on first increment
        if ($currentCount == 0) {
            Redis::expire($key, $this->windowSeconds);
        }

        // Process the job
        return $next($job);
    }
}
