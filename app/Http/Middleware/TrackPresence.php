<?php

namespace App\Http\Middleware;

use App\Services\Chat\PresenceService;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Symfony\Component\HttpFoundation\Response;

class TrackPresence
{
    /**
     * Throttle interval in seconds to avoid excessive updates.
     */
    protected const THROTTLE_SECONDS = 300;

    /**
     * Paths that should not trigger presence updates.
     */
    protected array $excludedPaths = [
        'api/presence/heartbeat', // Heartbeat has its own tracking
        'broadcasting/*',
        'sanctum/*',
        '_debugbar/*',
    ];

    public function __construct(
        protected PresenceService $presenceService
    ) {}

    /**
     * Handle an incoming request.
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Only track authenticated users
        if (! Auth::check()) {
            return $response;
        }

        // Skip excluded paths
        if ($this->isExcludedPath($request->path())) {
            return $response;
        }

        $user = Auth::user();
        $throttleKey = "presence_track:{$user->id}";

        // Throttle to avoid excessive cache/DB hits
        if (Cache::has($throttleKey)) {
            return $response;
        }

        // Set throttle lock
        Cache::put($throttleKey, true, now()->addSeconds(self::THROTTLE_SECONDS));

        // Update presence asynchronously to not delay response
        try {
            $this->presenceService->heartbeat($user);

            // Update last_seen_at periodically (every 5 minutes)
            $lastSeenKey = "last_seen_update:{$user->id}";
            if (! Cache::has($lastSeenKey)) {
                $user->updateQuietly(['last_seen_at' => now()]);
                Cache::put($lastSeenKey, true, now()->addMinutes(5));
            }
        } catch (\Throwable $e) {
            // Silently fail - presence is non-critical
            \Log::debug('Presence tracking failed', ['error' => $e->getMessage()]);
        }

        return $response;
    }

    /**
     * Check if the current path should be excluded from tracking.
     */
    protected function isExcludedPath(string $path): bool
    {
        foreach ($this->excludedPaths as $pattern) {
            if (str_contains($pattern, '*')) {
                $regex = str_replace(['*', '/'], ['.*', '\/'], $pattern);
                if (preg_match("/^{$regex}$/", $path)) {
                    return true;
                }
            } elseif ($path === $pattern) {
                return true;
            }
        }

        return false;
    }
}
