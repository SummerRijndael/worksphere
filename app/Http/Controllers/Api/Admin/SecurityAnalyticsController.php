<?php

namespace App\Http\Controllers\Api\Admin;

use App\Enums\AuditCategory;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\FirewallIp;
use App\Models\SuspiciousActivity;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SecurityAnalyticsController extends Controller
{
    /**
     * Get security dashboard data.
     */
    public function dashboard(Request $request): JsonResponse
    {
        $since = $this->getFilterDate($request);

        return response()->json([
            'data' => [
                'top_offenders' => $this->getTopOffenders($since),
                'active_blocks' => $this->getActiveBlocks(),
                'security_logs' => AuditLogResource::collection($this->getSecurityLogs($since)),
            ],
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
     * Get top offensive IP addresses and Users.
     */
    protected function getTopOffenders(\Illuminate\Support\Carbon $since): array
    {
        $topIps = SuspiciousActivity::select('ip_address', 'country_code', 'country_name', 'city')
            ->selectRaw('SUM(count) as total_attempts')
            ->where('last_observed_at', '>=', $since)
            ->groupBy('ip_address', 'country_code', 'country_name', 'city')
            ->orderByDesc('total_attempts')
            ->limit(10)
            ->get();

        $topUsers = AuditLog::select('user_id', 'user_name', 'user_email')
            ->selectRaw('COUNT(*) as count')
            ->whereNotNull('user_id')
            ->where('category', AuditCategory::Security)
            ->where('created_at', '>=', $since)
            ->groupBy('user_id', 'user_name', 'user_email')
            ->orderByDesc('count')
            ->limit(10)
            ->get();

        return [
            'ips' => $topIps,
            'users' => $topUsers,
        ];
    }

    /**
     * Get currently blocked IP addresses with countdown.
     */
    protected function getActiveBlocks(): array
    {
        return FirewallIp::where(function ($q) {
            $q->whereNull('expires_at')
                ->orWhere('expires_at', '>', now());
        })
            ->where('blocked', true)
            ->latest()
            ->get()
            ->map(function ($block) {
                $expiresAt = $block->expires_at;

                return [
                    'id' => $block->id,
                    'ip' => $block->ip,
                    'reason' => $block->reason ?? 'Manual Block',
                    'expires_at' => $expiresAt ? $expiresAt->toIso8601String() : null,
                    'remaining_seconds' => $expiresAt ? now()->diffInSeconds($expiresAt, false) : -1,
                    'created_at' => $block->created_at->toIso8601String(),
                ];
            })
            ->toArray();
    }

    /**
     * Get the latest security-related audit logs.
     */
    protected function getSecurityLogs(\Illuminate\Support\Carbon $since)
    {
        return AuditLog::query()
            ->whereIn('category', [AuditCategory::Security, AuditCategory::Authentication])
            ->whereNotIn('action', [\App\Enums\AuditAction::Login, \App\Enums\AuditAction::Logout])
            ->where('created_at', '>=', $since)
            ->with(['user'])
            ->latest()
            ->limit(50)
            ->get();
    }
}
