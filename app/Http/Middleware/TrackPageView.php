<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class TrackPageView
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $response = $next($request);

        // Don't track if not a successful page load (or redirect)
        if ($response->getStatusCode() >= 400) {
            return $response;
        }

        // Filter out non-HTML response types explicitly
        if ($response instanceof \Illuminate\Http\JsonResponse ||
            $response instanceof \Symfony\Component\HttpFoundation\BinaryFileResponse ||
            $response instanceof \Symfony\Component\HttpFoundation\StreamedResponse) {
            return $response;
        }

        // Only track HTML responses
        $contentType = $response->headers->get('Content-Type');
        if (! $contentType || ! str_contains($contentType, 'text/html')) {
            return $response;
        }

        // 1. Filter Bots
        $agent = new \Jenssegers\Agent\Agent;
        $agent->setUserAgent($request->userAgent());
        if ($agent->isRobot()) {
            return $response;
        }

        // 2. Filter internal/ignored IPs
        $ip = $request->ip();
        $ignoredIps = config('analytics.ignore_ips', []);

        // Simple exact match check - could be expanded to CIDR later
        if (in_array($ip, $ignoredIps)) {
            return $response;
        }
        // 3. Filter Admins / Logic
        /** @var \App\Models\User|null $user */
        $user = $request->user();

        if ($user) {
            $shouldIgnoreAdmins = config('analytics.ignore_admins', true);
            $ignoredRoles = config('analytics.ignore_roles', []);

            if ($shouldIgnoreAdmins && ($user->hasRole('administrator') || $user->hasRole('super_admin'))) {
                return $response;
            }

            if (! empty($ignoredRoles) && $user->hasAnyRole($ignoredRoles)) {
                return $response;
            }
        }

        // 4. Prepare Data
        $anonymize = config('analytics.anonymize_ips', false);
        $storedIp = $anonymize ? hash('sha256', $ip.date('Ymd')) : $ip; // Simple daily salt

        // Session ID: Use Laravel Session ID or hash(IP + UserAgent + Date)
        $sessionId = \Illuminate\Support\Facades\Session::getId();

        $data = [
            'session_id' => $sessionId,
            'user_id' => $user?->id,
            'ip_address' => $storedIp,
            'url' => $request->fullUrl(),
            'path' => $request->path(),
            'method' => $request->method(),
            'referer' => $request->header('referer'),
            'user_agent' => $request->userAgent(),
            'device_type' => $agent->isDesktop() ? 'desktop' : ($agent->isTablet() ? 'tablet' : 'mobile'),
            'browser' => $agent->browser(),
            'platform' => $agent->platform(),
            'created_at' => now(),
        ];

        // 5. Dispatch
        \App\Jobs\ProcessAnalyticsJob::dispatch($data);

        return $response;
    }
}
