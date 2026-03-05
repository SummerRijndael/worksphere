<?php

namespace App\Services;

use App\Jobs\ProcessAnalyticsJob;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Session;
use Jenssegers\Agent\Agent;

class AnalyticsTracker
{
    /**
     * @var \App\Contracts\AnalyticsDriver[]
     */
    protected array $drivers = [];

    public function __construct()
    {
        $this->registerDrivers();
    }

    /**
     * Register available analytics drivers.
     */
    protected function registerDrivers(): void
    {
        $this->drivers = [
            app(\App\Services\Analytics\Drivers\InternalDriver::class),
            app(\App\Services\Analytics\Drivers\GoogleAnalyticsDriver::class),
        ];
    }

    /**
     * Track a page view from a request.
     */
    public function trackRequest(Request $request): void
    {
        $agent = new Agent;
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

        $this->dispatchToDrivers($data);
    }

    /**
     * Track a page view from manual data (SPA tracking).
     */
    public function trackManual(Request $request, array $attributes): void
    {
        $agent = new Agent;
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

        $this->dispatchToDrivers($data);
    }

    /**
     * Dispatch tracking data to all enabled drivers.
     */
    protected function dispatchToDrivers(array $data): void
    {
        foreach ($this->drivers as $driver) {
            if ($driver->isEnabled()) {
                // If it's the internal driver, we use the job for async processing
                // For others, it depends on their implementation
                if ($driver instanceof \App\Services\Analytics\Drivers\InternalDriver) {
                    ProcessAnalyticsJob::dispatch($data);
                } else {
                    $driver->track($data);
                }
            }
        }
    }


    /**
     * Prepare data for analytics.
     */
    protected function prepareData(Request $request, Agent $agent, $user = null, array $overrides = []): array
    {
        $ip = $request->ip();
        $anonymize = config('analytics.anonymize_ips', false);
        $storedIp = $anonymize ? hash('sha256', $ip.date('Ymd')) : $ip;

        $location = geoip($ip);

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
            'country' => $location->country,
            'city' => $location->city,
            'iso_code' => $location->iso_code,
            'lat' => $location->lat,
            'lon' => $location->lon,
            'fingerprint' => $request->input('fingerprint'), // Capture fingerprint from request if present
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

        if (! empty($ignoredRoles) && $user->hasAnyRole($ignoredRoles)) {
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
