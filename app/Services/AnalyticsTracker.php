<?php

namespace App\Services;

use App\Jobs\ProcessAnalyticsJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Jenssegers\Agent\Agent;

class AnalyticsTracker
{
    /**
     * Track a page view from a request.
     */
    public function trackRequest(Request $request): void
    {
        $agent = new Agent();
        $agent->setUserAgent($request->userAgent());

        if ($agent->isRobot()) {
            return;
        }

        $ip = $request->ip();
        if ($this->shouldIgnoreIp($ip)) {
            return;
        }

        /** @var \App\Models\User|null $user */
        $user = $request->user();
        if ($user && $this->shouldIgnoreUser($user)) {
            return;
        }

        $data = $this->prepareData($request, $agent, $user);

        ProcessAnalyticsJob::dispatch($data);
    }

    /**
     * Track a page view from manual data (SPA tracking).
     */
    public function trackManual(Request $request, array $attributes): void
    {
        $agent = new Agent();
        $agent->setUserAgent($request->userAgent());

        if ($agent->isRobot()) {
            return;
        }

        $ip = $request->ip();
        if ($this->shouldIgnoreIp($ip)) {
            return;
        }

        /** @var \App\Models\User|null $user */
        $user = $request->user();
        if ($user && $this->shouldIgnoreUser($user)) {
            return;
        }

        $data = $this->prepareData($request, $agent, $user, $attributes);

        ProcessAnalyticsJob::dispatch($data);
    }

    /**
     * Prepare data for analytics.
     */
    protected function prepareData(Request $request, Agent $agent, $user = null, array $overrides = []): array
    {
        $ip = $request->ip();
        $anonymize = config('analytics.anonymize_ips', false);
        $storedIp = $anonymize ? hash('sha256', $ip . date('Ymd')) : $ip;

        return array_merge([
            'session_id' => Session::getId(),
            'user_id' => $user?->id,
            'ip_address' => $storedIp,
            'url' => $request->fullUrl(),
            'path' => $request->path(),
            'method' => $request->method(),
            'referer' => $request->header('referer'),
            'user_agent' => $request->userAgent(),
            'device_type' => $this->getDeviceType($agent),
            'browser' => $agent->browser(),
            'platform' => $agent->platform(),
            'created_at' => now(),
        ], $overrides);
    }

    /**
     * Determine if an IP should be ignored.
     */
    protected function shouldIgnoreIp(string $ip): bool
    {
        $ignoredIps = config('analytics.ignore_ips', []);
        return in_array($ip, $ignoredIps);
    }

    /**
     * Determine if a user should be ignored.
     */
    protected function shouldIgnoreUser($user): bool
    {
        $shouldIgnoreAdmins = config('analytics.ignore_admins', true);
        $ignoredRoles = config('analytics.ignore_roles', []);

        if ($shouldIgnoreAdmins && ($user->hasRole('administrator') || $user->hasRole('super_admin'))) {
            return true;
        }

        if (!empty($ignoredRoles) && $user->hasAnyRole($ignoredRoles)) {
            return true;
        }

        return false;
    }

    /**
     * Get device type from agent.
     */
    protected function getDeviceType(Agent $agent): string
    {
        if ($agent->isDesktop()) {
            return 'desktop';
        }

        if ($agent->isTablet()) {
            return 'tablet';
        }

        return 'mobile';
    }
}
