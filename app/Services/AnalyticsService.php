<?php

namespace App\Services;

use App\Models\PageView;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;

class AnalyticsService
{
    /**
     * Get start date based on period string
     */
    protected function getStartDate(string $period): Carbon
    {
        return match ($period) {
            '24h' => now()->subHours(24),
            '7d' => now()->subDays(7),
            '30d' => now()->subDays(30),
            '90d' => now()->subDays(90),
            'year' => now()->subYear(),
            default => now()->subDays(7),
        };
    }

    /**
     * Get overview statistics (Views, Uniques, Avg Session, Bounce Rate)
     */
    public function getOverviewStats(string $period): array
    {
        $startDate = $this->getStartDate($period);
        $previousStartDate = $startDate->copy()->subSeconds(now()->diffInSeconds($startDate));

        // Current Period
        $currentViews = PageView::where('created_at', '>=', $startDate)->count();
        $currentUniques = PageView::where('created_at', '>=', $startDate)->distinct('session_id')->count('session_id');

        // Previous Period (for % change)
        $prevViews = PageView::whereBetween('created_at', [$previousStartDate, $startDate])->count();
        $prevUniques = PageView::whereBetween('created_at', [$previousStartDate, $startDate])->distinct('session_id')->count('session_id');

        // Calculate Bounce Rate (Single page sessions / Total sessions)
        // This is an approximation. Real bounce rate needs session grouping.
        // For MVP: Sessions with count(id) = 1
        $currentBounceRate = $this->calculateBounceRate($startDate);
        $prevBounceRate = $this->calculateBounceRate($previousStartDate, $startDate);

        return [
            [
                'id' => 1,
                'label' => 'Total Views',
                'value' => number_format($currentViews),
                'change' => $this->calculateChange($currentViews, $prevViews),
                'trend' => $currentViews >= $prevViews ? 'up' : 'down',
                'icon' => 'Eye',
            ],
            [
                'id' => 2,
                'label' => 'Unique Visitors',
                'value' => number_format($currentUniques),
                'change' => $this->calculateChange($currentUniques, $prevUniques),
                'trend' => $currentUniques >= $prevUniques ? 'up' : 'down',
                'icon' => 'Users',
            ],
            [
                'id' => 3,
                'label' => 'Bounce Rate',
                'value' => round($currentBounceRate, 1).'%',
                'change' => $this->calculateChange($currentBounceRate, $prevBounceRate, true), // Lower is better? Context dependent, but usually lower bounce is good.
                'trend' => $currentBounceRate <= $prevBounceRate ? 'up' : 'down', // Visual 'up' (green) if improved (lower)
                'icon' => 'ArrowUpRight',
            ],
            // Avg session duration requires distinct session tracking, omitting for MVP or simple avg
        ];
    }

    /**
     * Get traffic chart data
     */
    public function getTrafficChart(string $period): array
    {
        $startDate = $this->getStartDate($period);
        $dateFormat = $period === '24h' ? '%H:00' : '%Y-%m-%d';
        $groupBy = $period === '24h' ? 'HOUR(created_at)' : 'DATE(created_at)';

        // SQLite/MySQL compatibility
        // Assuming MySQL/MariaDB for production, but should handle SQLite for local if needed.
        // For simplicity using Eloquent with raw selection suitable for standard SQL or processing in PHP.

        $views = PageView::select(
            DB::raw('DATE_FORMAT(created_at, "'.($period === '24h' ? '%Y-%m-%d %H:00:00' : '%Y-%m-%d').'") as date'),
            DB::raw('count(*) as count')
        )
            ->where('created_at', '>=', $startDate)
            ->groupBy('date')
            ->orderBy('date')
            ->get();

        // Fill in missing dates? (Ideally yes, but UI library often handles sparse data or we fill in JS)
        return $views->map(fn ($v) => [
            'date' => $v->date,
            'count' => $v->count,
        ])->toArray();
    }

    /**
     * Get top pages
     */
    public function getTopPages(string $period): array
    {
        $startDate = $this->getStartDate($period);

        return PageView::select('path', DB::raw('count(*) as views'), DB::raw('count(distinct session_id) as unique_visits'))
            ->where('created_at', '>=', $startDate)
            ->groupBy('path')
            ->orderByDesc('views')
            ->limit(10)
            ->get()
            ->map(fn ($p) => [
                'path' => $p->path,
                'views' => number_format($p->views),
                'unique' => number_format($p->unique_visits),
                'avgTime' => '-', // Calculated time requires complex session analysis
            ])->toArray();
    }

    /**
     * Get traffic sources
     */
    public function getTrafficSources(string $period): array
    {
        $startDate = $this->getStartDate($period);
        $total = PageView::where('created_at', '>=', $startDate)->count();

        if ($total === 0) {
            return [];
        }

        return PageView::select('referer', DB::raw('count(*) as visits'))
            ->where('created_at', '>=', $startDate)
            ->whereNotNull('referer')
            ->groupBy('referer')
            ->orderByDesc('visits')
            ->limit(5)
            ->get()
            ->map(function ($s) use ($total) {
                // Parse domain from referer
                $domain = parse_url($s->referer, PHP_URL_HOST) ?? 'Direct/Unknown';

                return [
                    'source' => $domain,
                    'visits' => $s->visits,
                    'percentage' => round(($s->visits / $total) * 100, 1),
                ];
            })
            ->filter(function ($source) {
                // Filter out internal traffic/self-referrals
                $appHost = parse_url(config('app.url'), PHP_URL_HOST);

                return $source['source'] !== $appHost && $source['source'] !== 'localhost';
            })
            ->values() // Re-index array
            ->toArray();
    }

    private function calculateBounceRate($startDate, $endDate = null): float
    {
        $query = PageView::select('session_id', DB::raw('count(*) as pages'))
            ->where('created_at', '>=', $startDate);

        if ($endDate) {
            $query->where('created_at', '<', $endDate);
        }

        $sessions = $query->groupBy('session_id')->get();
        if ($sessions->isEmpty()) {
            return 0;
        }

        $bounces = $sessions->filter(fn ($s) => $s->pages === 1)->count();

        return ($bounces / $sessions->count()) * 100;
    }

    private function calculateChange($current, $prev, $inverse = false): string
    {
        if ($prev == 0) {
            return $current > 0 ? '+100%' : '0%';
        }

        $diff = $current - $prev;
        $percent = ($diff / $prev) * 100;
        $sign = $percent > 0 ? '+' : '';

        return $sign.round($percent, 1).'%';
    }
}
