<?php

namespace App\Services;

use App\Contracts\AppReviewServiceContract;
use App\Models\AuditLog;
use App\Models\LegalAgreementLog;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class UserSentimentService
{
    public function __construct(
        protected AppReviewServiceContract $reviewService
    ) {}

    /**
     * Get user sentiment and vibe metrics.
     */
    public function getSentimentMetrics(): array
    {
        return $this->reviewService->getSentimentStats();
    }

    /**
     * Get engagement and churn metrics.
     */
    public function getEngagementStats(string $period = '30d'): array
    {
        $startDate = $this->getStartDate($period);

        return [
            'total_users' => User::count(),
            'active_users' => User::where('last_login_at', '>=', now()->subDays(30))->count(),
            'new_users' => User::where('created_at', '>=', $startDate)->count(),
            'active_teams' => Team::whereHas('members')->count(),
            'churn_rate' => $this->calculateChurnRate($startDate),
            'retention_rate' => $this->calculateRetentionRate(),
            'feature_usage' => $this->getFeatureUsageDistribution($startDate),

            // Business "Vibe" Entities
            'services_count' => Ticket::where('created_at', '>=', $startDate)->count(),
            'contracts_count' => LegalAgreementLog::where('created_at', '>=', $startDate)->count(),
            'policies_count' => LegalAgreementLog::distinct('document_type')->count(),
        ];
    }

    protected function getVibeStatus(float $rating): string
    {
        if ($rating >= 4.5) {
            return 'Excellent';
        }
        if ($rating >= 3.5) {
            return 'Positive';
        }
        if ($rating >= 2.5) {
            return 'Mixed';
        }

        return 'Needs Attention';
    }

    /**
     * Churn rate: % of users who were active in the previous period but not in the current one.
     */
    protected function calculateChurnRate(Carbon $startDate): float
    {
        $total = User::count();
        if ($total === 0) {
            return 0;
        }

        $inactive = User::where(function ($q) {
            $q->where('last_login_at', '<', now()->subDays(30))
                ->orWhereNull('last_login_at');
        })
            ->count();

        return round(($inactive / $total) * 100, 1);
    }

    /**
     * Retention rate: % of users who have meaningful activity in the last 30 days.
     */
    protected function calculateRetentionRate(): float
    {
        $total = User::count();
        if ($total === 0) {
            return 0;
        }

        // Users with recent activity in audit logs
        $retained = User::whereHas('auditLogs', function ($q) {
            $q->where('created_at', '>=', now()->subDays(30));
        }, '>=', 3)
            ->count();

        return round(($retained / $total) * 100, 1);
    }

    protected function getFeatureUsageDistribution(Carbon $startDate): array
    {
        return AuditLog::where('created_at', '>=', $startDate)
            ->select('event_type', DB::raw('count(*) as count'))
            ->groupBy('event_type')
            ->orderByDesc('count')
            ->limit(10)
            ->get()
            ->toArray();
    }

    protected function getStartDate(string $period): Carbon
    {
        return match ($period) {
            '24h' => now()->subDay(),
            '7d' => now()->subDays(7),
            '90d' => now()->subDays(90),
            'year' => now()->subYear(),
            default => now()->subDays(30),
        };
    }
}
