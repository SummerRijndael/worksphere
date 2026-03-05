<?php

namespace App\Http\Controllers\Api;

use Akaunting\Firewall\Models\Log as FirewallLog;
use App\Http\Controllers\Controller;
use App\Models\FirewallIp;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class SecurityDashboardController extends Controller
{
    /**
     * Get security statistics.
     */
    public function stats(Request $request)
    {
        $this->authorize('viewAny', FirewallIp::class);
        $since = $this->getFilterDate($request);

        $blockedIpsCount = FirewallIp::where('blocked', 1)->count();
        $whitelistedIpsCount = FirewallIp::where('blocked', 0)->count();

        $bannedUsersCount = User::where('status', 'banned')->count();
        $suspendedUsersCount = User::where('status', 'suspended')->count();

        $incidentsPeriod = FirewallLog::where('created_at', '>=', $since)->count();

        return response()->json([
            'blocked_ips' => $blockedIpsCount,
            'banned_users' => $bannedUsersCount,
            'suspended_users' => $suspendedUsersCount,
            'whitelisted_ips' => $whitelistedIpsCount,
            'incidents_period' => $incidentsPeriod,
            // Legacy support
            'incidents_today' => FirewallLog::whereDate('created_at', today())->count(),
        ]);
    }

    /**
     * Get the start date for filtering based on request period.
     */
    protected function getFilterDate(Request $request): \Illuminate\Support\Carbon
    {
        $period = $request->query('period', '1w');

        return match ($period) {
            '24h' => now()->subDay(),
            '1w' => now()->subWeek(),
            '1m' => now()->subMonth(),
            '3m' => now()->subMonths(3),
            '6m' => now()->subMonths(6),
            '1y' => now()->subYear(),
            default => now()->subWeek(),
        };
    }

    /**
     * Get recent security activity.
     */
    public function activity(Request $request)
    {
        $this->authorize('viewAny', FirewallIp::class);

        $limit = $request->integer('limit', 20);
        $ip = $request->query('ip');

        $logs = FirewallLog::with('user')
            ->when($ip, fn ($q) => $q->where('ip', $ip))
            ->latest()
            ->paginate($limit);

        return response()->json($logs);
    }

    /**
     * Get detailed activity for a specific IP.
     */
    public function ipActivity(Request $request, string $ip)
    {
        $this->authorize('viewAny', FirewallIp::class);

        $logs = FirewallLog::where('ip', $ip)
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return response()->json($logs);
    }

    /**
     * Get blocked IPs list.
     */
    public function blockedIps(Request $request)
    {
        $this->authorize('viewAny', FirewallIp::class);

        $ips = FirewallIp::where('blocked', 1)
            ->with('user') // user who blocked it
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return response()->json($ips);
    }

    /**
     * Block an IP.
     */
    public function blockIp(Request $request)
    {
        $this->authorize('create', FirewallIp::class);

        $validated = $request->validate([
            'ip_address' => 'required|ip',
            'reason' => 'nullable|string|max:255',
            'expires_at' => 'nullable|date|after:now',
        ]);

        // Prevent blocking server's own IP or 127.0.0.1
        $serverIp = $_SERVER['SERVER_ADDR'] ?? null;
        if ($validated['ip_address'] === '127.0.0.1' || ($serverIp && $validated['ip_address'] === $serverIp)) {
            return response()->json([
                'message' => 'Cannot block the server\'s own IP address or the loopback address.',
            ], 422);
        }

        if (FirewallIp::where('ip', $validated['ip_address'])->where('blocked', 1)->exists()) {
            return response()->json(['message' => 'IP already blocked.'], 422);
        }

        // Use standard create to include extra fields
        $blockedIp = FirewallIp::create([
            'ip' => $validated['ip_address'],
            'blocked' => 1,
            'reason' => $validated['reason'] ?? null,
            'user_id' => $request->user()->id,
            'expires_at' => $validated['expires_at'] ?? null,
        ]);

        return response()->json($blockedIp, 201);
    }

    /**
     * Unblock an IP.
     */
    public function unblockIp($id)
    {
        $firewallIp = FirewallIp::findOrFail($id);
        $this->authorize('delete', $firewallIp);

        $firewallIp->delete();

        return response()->json(['message' => 'IP unblocked successfully']);
    }

    /**
     * Get banned users list.
     */
    public function bannedUsers(Request $request)
    {
        $this->authorize('viewAny', User::class);

        $users = User::whereIn('status', ['banned', 'suspended'])
            ->latest('updated_at')
            ->paginate($request->integer('per_page', 20));

        return response()->json($users);
    }

    /**
     * Get security chart data.
     */
    public function charts(Request $request)
    {
        $this->authorize('viewAny', FirewallIp::class);
        $since = $this->getFilterDate($request);

        // Determine grouping and interval based on period
        $period = $request->query('period', '1w');
        $days = match ($period) {
            '24h' => 1,
            '1w' => 7,
            '1m' => 30,
            '3m' => 90,
            '6m' => 180,
            '1y' => 365,
            default => 14
        };

        $trendData = FirewallLog::where('created_at', '>=', $since)
            ->select(DB::raw('DATE(created_at) as date'), DB::raw('count(*) as count'))
            ->groupBy('date')
            ->orderBy('date')
            ->get()
            ->pluck('count', 'date')
            ->toArray();

        $trend = [];
        for ($i = $days; $i >= 0; $i--) {
            $date = now()->subDays($i)->format('Y-m-d');
            $trend[] = [
                'date' => $date,
                'label' => now()->subDays($i)->format('M d'),
                'count' => $trendData[$date] ?? 0,
            ];
        }

        $distributionData = FirewallLog::where('created_at', '>=', $since)
            ->select('middleware', DB::raw('count(*) as count'))
            ->groupBy('middleware')
            ->get();

        $distribution = $distributionData->map(function ($item) {
            return [
                'label' => ucfirst($item->middleware),
                'count' => $item->count,
            ];
        });

        return response()->json([
            'trend' => $trend,
            'distribution' => $distribution,
        ]);
    }

    /**
     * Get suspicious activity map data.
     */
    public function mapData(Request $request)
    {
        $this->authorize('viewAny', FirewallIp::class);
        $since = $this->getFilterDate($request);

        $activities = FirewallLog::where('created_at', '>=', $since)
            ->select('ip', DB::raw('count(*) as count'))
            ->groupBy('ip')
            ->orderByDesc('count')
            ->limit(100)
            ->get()
            ->map(function ($item) {
                try {
                    $geo = geoip($item->ip);

                    return [
                        'lat' => (float) $geo->lat,
                        'lng' => (float) $geo->lon,
                        'ip' => $item->ip,
                        'location' => "{$geo->city}, {$geo->country}",
                        'intensity' => min(1, $item->count / 10),
                        'count' => $item->count,
                        'type' => 'Firewall Event',
                    ];
                } catch (\Exception $e) {
                    return null;
                }
            })
            ->filter()
            ->values();

        return response()->json($activities);
    }

    /**
     * Get whitelisted IPs list.
     */
    public function whitelistedIps(Request $request)
    {
        $this->authorize('viewAny', FirewallIp::class);

        $ips = FirewallIp::where('blocked', 0)
            ->with('user') // added by
            ->latest()
            ->paginate($request->integer('per_page', 20));

        return response()->json($ips);
    }

    /**
     * Add an IP to the whitelist.
     */
    public function whitelistIp(Request $request)
    {
        $this->authorize('create', FirewallIp::class);

        $validated = $request->validate([
            'ip_address' => 'required|ip',
            'label' => 'nullable|string|max:100',
            'reason' => 'required|string|max:255',
            'password' => 'required|string',
        ]);

        if (! \Illuminate\Support\Facades\Hash::check($validated['password'], $request->user()->password)) {
            return response()->json([
                'message' => 'The provided password does not match our records.',
                'errors' => [
                    'password' => ['The provided password does not match our records.'],
                ],
            ], 422);
        }

        if (FirewallIp::where('ip', $validated['ip_address'])->where('blocked', 0)->exists()) {
            return response()->json(['message' => 'IP already whitelisted.'], 422);
        }

        $whitelisted = FirewallIp::create([
            'ip' => $validated['ip_address'],
            'blocked' => 0,
            'label' => $validated['label'],
            'reason' => $validated['reason'],
            'user_id' => $request->user()->id,
        ]);

        app(\App\Services\AuditService::class)->log(
            \App\Enums\AuditAction::Created,
            \App\Enums\AuditCategory::Security,
            $whitelisted,
            $request->user(),
            null,
            $whitelisted->toArray(),
            ['ip' => $whitelisted->ip, 'reason' => $whitelisted->reason]
        );

        return response()->json([
            'message' => 'IP address added to whitelist successfully',
            'data' => $whitelisted,
        ]);
    }

    /**
     * Remove an IP from the whitelist.
     */
    public function unwhitelistIp($id)
    {
        $firewallIp = FirewallIp::findOrFail($id);
        $this->authorize('delete', $firewallIp);

        $firewallIp->delete();

        return response()->json([
            'message' => 'IP address removed from whitelist successfully',
        ]);
    }
}
