<?php

namespace App\Services;

use App\Models\PageView;
use Carbon\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Cache;

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
        $cacheKey = "analytics_overview_{$period}";

        return Cache::remember($cacheKey, now()->addMinutes(5), function () use ($period) {
            $startDate = $this->getStartDate($period);
            $previousStartDate = $startDate->copy()->subSeconds(now()->diffInSeconds($startDate));

            // Current Period
            $currentViews = PageView::query()->where('created_at', '>=', $startDate)->count();
            $currentUniques = PageView::query()->where('created_at', '>=', $startDate)->distinct()->count('session_id');

            // Previous Period (for % change)
            $prevViews = PageView::query()->whereBetween('created_at', [$previousStartDate, $startDate])->count();
            $prevUniques = PageView::query()->whereBetween('created_at', [$previousStartDate, $startDate])->distinct()->count('session_id');

            // Calculate Bounce Rate
            $currentBounceRate = $this->calculateBounceRate($startDate);
            $prevBounceRate = $this->calculateBounceRate($previousStartDate, $startDate);

            // Active Users (last 5 minutes)
            $activeUsers = $this->getActiveUsers();

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
                    'label' => 'Active Now',
                    'value' => number_format($activeUsers),
                    'change' => '',
                    'trend' => 'up',
                    'icon' => 'Clock',
                ],
                [
                    'id' => 4,
                    'label' => 'Bounce Rate',
                    'value' => round($currentBounceRate, 1) . '%',
                    'change' => $this->calculateChange($currentBounceRate, $prevBounceRate, true),
                    'trend' => $currentBounceRate <= $prevBounceRate ? 'up' : 'down',
                    'icon' => 'ArrowUpRight',
                ],
            ];
        });
    }

    /**
     * Get traffic chart data
     */
    public function getTrafficChart(string $period): array
    {
        $cacheKey = "analytics_chart_{$period}";

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($period) {
            $startDate = $this->getStartDate($period);
            $driver = DB::getDriverName();

            if ($driver === 'sqlite') {
                $dateFormat = $period === '24h' ? "strftime('%Y-%m-%d %H:00:00', created_at)" : "date(created_at)";
            } else {
                $dateFormat = $period === '24h' ? "DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00')" : "DATE(created_at)";
            }

            $views = PageView::query()->select([
                DB::raw($dateFormat . ' as date'),
                DB::raw('count(*) as count')
            ])
                ->where('created_at', '>=', $startDate)
                ->groupBy('date')
                ->orderBy('date')
                ->get();

            return $views->map(fn($v) => [
                'date' => $v->date,
                'count' => $v->count,
            ])->toArray();
        });
    }

    /**
     * Get top pages
     */
    public function getTopPages(string $period): array
    {
        $cacheKey = "analytics_pages_{$period}";

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($period) {
            $startDate = $this->getStartDate($period);

            return PageView::query()->select([
                'path',
                DB::raw('count(*) as views'),
                DB::raw('count(distinct session_id) as unique_visits')
            ])
                ->where('created_at', '>=', $startDate)
                ->groupBy('path')
                ->orderByDesc('views')
                ->limit(10)
                ->get()
                ->map(fn($p) => [
                    'path' => $p->path,
                    'views' => number_format($p->views),
                    'unique' => number_format($p->unique_visits),
                    'avgTime' => '-',
                ])->toArray();
        });
    }

    /**
     * Get traffic sources
     */
    public function getTrafficSources(string $period): array
    {
        $cacheKey = "analytics_sources_{$period}";

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($period) {
            $startDate = $this->getStartDate($period);
            $total = PageView::query()->where('created_at', '>=', $startDate)->count();

            if ($total === 0) {
                return [];
            }

            return PageView::query()->select([
                'referer',
                DB::raw('count(*) as visits')
            ])
                ->where('created_at', '>=', $startDate)
                ->whereNotNull('referer')
                ->groupBy('referer')
                ->orderByDesc('visits')
                ->limit(10)
                ->get()
                ->map(function ($s) use ($total) {
                    $domain = parse_url($s->referer, PHP_URL_HOST) ?? 'Direct/Unknown';

                    return [
                        'source' => $domain,
                        'visits' => $s->visits,
                        'percentage' => round(($s->visits / $total) * 100, 1),
                    ];
                })
                ->filter(function ($source) {
                    $appHost = parse_url(config('app.url'), PHP_URL_HOST);
                    return $source['source'] !== $appHost && $source['source'] !== 'localhost';
                })
                ->values()
                ->toArray();
        });
    }

    /**
     * Get count of users active in the last 5 minutes.
     */
    public function getActiveUsers(): int
    {
        return PageView::query()->where('created_at', '>=', now()->subMinutes(5))
            ->distinct()
            ->count('session_id');
    }

    private function calculateBounceRate($startDate, $endDate = null): float
    {
        $query = PageView::query()->select(['session_id', DB::raw('count(*) as pages')])
            ->where('created_at', '>=', $startDate);

        if ($endDate) {
            $query->where('created_at', '<', $endDate);
        }

        // Use a subquery to avoid loading all sessions into memory
        $stats = DB::table(function ($query) use ($startDate, $endDate) {
            $query->select('session_id')
                ->from('page_views')
                ->where('created_at', '>=', $startDate)
                ->when($endDate, fn($q) => $q->where('created_at', '<', $endDate))
                ->groupBy('session_id')
                ->havingRaw('count(*) = 1');
        }, 'bounces')->count();

        $totalSessions = PageView::query()->where('created_at', '>=', $startDate)
            ->when($endDate, fn($q) => $q->where('created_at', '<', $endDate))
            ->distinct()
            ->count('session_id');

        if ($totalSessions === 0) {
            return 0;
        }

        return ($stats / $totalSessions) * 100;
    }

    private function calculateChange($current, $prev, $inverse = false): string
    {
        if ($prev == 0) {
            return $current > 0 ? '+100%' : '0%';
        }

        $diff = $current - $prev;
        $percent = ($diff / $prev) * 100;
        $sign = $percent > 0 ? '+' : '';

        return $sign . round($percent, 1) . '%';
    }
}
