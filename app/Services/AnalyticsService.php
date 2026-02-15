<?php

namespace App\Services;

use App\Models\PageView;
use Carbon\Carbon;
use Illuminate\Support\Facades\Cache;
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

            // Avg Session Duration
            $currentDuration = $this->calculateAvgSessionDuration($startDate);
            $prevDuration = $this->calculateAvgSessionDuration($previousStartDate, $startDate);

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
                    'label' => 'Avg Session',
                    'value' => $this->formatDuration($currentDuration),
                    'change' => $this->calculateChange($currentDuration, $prevDuration),
                    'trend' => $currentDuration >= $prevDuration ? 'up' : 'down',
                    'icon' => 'Clock',
                ],
                [
                    'id' => 4,
                    'label' => 'Bounce Rate',
                    'value' => round($currentBounceRate, 1).'%',
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
                $dateFormat = $period === '24h' ? "strftime('%Y-%m-%d %H:00:00', created_at)" : 'date(created_at)';
            } else {
                $dateFormat = $period === '24h' ? "DATE_FORMAT(created_at, '%Y-%m-%d %H:00:00')" : 'DATE(created_at)';
            }

            $views = PageView::query()->select([
                DB::raw($dateFormat.' as date'),
                DB::raw('count(*) as count'),
            ])
                ->where('created_at', '>=', $startDate)
                ->groupBy('date')
                ->orderBy('date')
                ->get();

            return $views->map(fn ($v) => [
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
            $stayTimes = $this->getAverageStayTimes($period);

            return PageView::query()->select([
                'path',
                DB::raw('count(*) as views'),
                DB::raw('count(distinct session_id) as unique_visits'),
            ])
                ->where('created_at', '>=', $startDate)
                ->groupBy('path')
                ->orderByDesc('views')
                ->limit(10)
                ->get()
                ->map(fn ($p) => [
                    'path' => $p->path,
                    'views' => number_format($p->views),
                    'unique' => number_format($p->unique_visits),
                    'avgTime' => isset($stayTimes[$p->path]) ? $this->formatDuration($stayTimes[$p->path]) : '-',
                ])->toArray();
        });
    }

    /**
     * Calculate average stay time per page
     */
    protected function getAverageStayTimes(string $period): array
    {
        $startDate = $this->getStartDate($period);

        // Get all page views for the period, grouped by session and ordered by time
        $views = PageView::query()
            ->where('created_at', '>=', $startDate)
            ->orderBy('session_id')
            ->orderBy('created_at')
            ->get(['session_id', 'path', 'created_at']);

        $stayTimes = []; // [path => [total_seconds, count]]

        $grouped = $views->groupBy('session_id');

        foreach ($grouped as $sessionId => $sessionViews) {
            for ($i = 0; $i < count($sessionViews) - 1; $i++) {
                $current = $sessionViews[$i];
                $next = $sessionViews[$i + 1];

                $duration = $next->created_at->diffInSeconds($current->created_at);

                // Sanity check: if > 30 mins, probably not a continuous stay
                if ($duration > 1800) {
                    continue;
                }

                if (! isset($stayTimes[$current->path])) {
                    $stayTimes[$current->path] = ['total' => 0, 'count' => 0];
                }

                $stayTimes[$current->path]['total'] += $duration;
                $stayTimes[$current->path]['count'] += 1;
            }
        }

        $results = [];
        foreach ($stayTimes as $path => $data) {
            $results[$path] = $data['count'] > 0 ? $data['total'] / $data['count'] : 0;
        }

        return $results;
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
                DB::raw('count(*) as visits'),
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
                ->when($endDate, fn ($q) => $q->where('created_at', '<', $endDate))
                ->groupBy('session_id')
                ->havingRaw('count(*) = 1');
        }, 'bounces')->count();

        $totalSessions = PageView::query()->where('created_at', '>=', $startDate)
            ->when($endDate, fn ($q) => $q->where('created_at', '<', $endDate))
            ->distinct()
            ->count('session_id');

        if ($totalSessions === 0) {
            return 0;
        }

        return ($stats / $totalSessions) * 100;
    }

    public function getDemographics(string $period): array
    {
        $cacheKey = "analytics_demographics_{$period}";

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($period) {
            $startDate = $this->getStartDate($period);

            // Devices
            $devices = PageView::query()
                ->select('device_type', DB::raw('count(*) as count'))
                ->where('created_at', '>=', $startDate)
                ->groupBy('device_type')
                ->get()
                ->mapWithKeys(fn ($item) => [$item->device_type => $item->count])
                ->toArray();

            // Browsers
            $browsers = PageView::query()
                ->select('browser', DB::raw('count(*) as count'))
                ->where('created_at', '>=', $startDate)
                ->groupBy('browser')
                ->orderByDesc('count')
                ->limit(5)
                ->get()
                ->map(fn ($item) => ['label' => $item->browser, 'value' => $item->count])
                ->toArray();

            // OS
            $os = PageView::query()
                ->select('platform', DB::raw('count(*) as count'))
                ->where('created_at', '>=', $startDate)
                ->groupBy('platform')
                ->orderByDesc('count')
                ->limit(5)
                ->get()
                ->map(fn ($item) => ['label' => $item->platform, 'value' => $item->count])
                ->toArray();

            return [
                'devices' => $devices,
                'browsers' => $browsers,
                'os' => $os,
            ];
        });
    }

    public function getGeoStats(string $period): array
    {
        $cacheKey = "analytics_geo_{$period}";

        return Cache::remember($cacheKey, now()->addMinutes(10), function () use ($period) {
            $startDate = $this->getStartDate($period);

            return PageView::query()
                ->select('country', 'city', 'iso_code', 'lat', 'lon', 'device_type', DB::raw('count(*) as count'))
                ->where('created_at', '>=', $startDate)
                ->whereNotNull('iso_code')
                ->groupBy('country', 'city', 'iso_code', 'lat', 'lon', 'device_type')
                ->orderByDesc('count')
                ->limit(200)
                ->get()
                ->toArray();
        });
    }

    private function calculateAvgSessionDuration($startDate, $endDate = null): float
    {
        // Get session durations: max(created_at) - min(created_at) per session
        // We only consider sessions with > 1 page view for duration

        $driver = DB::connection()->getDriverName();
        $select = $driver === 'sqlite'
            ? '(strftime(\'%s\', MAX(created_at)) - strftime(\'%s\', MIN(created_at))) as duration'
            : 'TIME_TO_SEC(TIMEDIFF(MAX(created_at), MIN(created_at))) as duration';

        $query = PageView::query()
            ->select('session_id', DB::raw($select))
            ->where('created_at', '>=', $startDate)
            ->groupBy('session_id')
            ->havingRaw('count(*) > 1');

        if ($endDate) {
            $query->where('created_at', '<', $endDate);
        }

        $durations = $query->get()->pluck('duration');

        if ($durations->isEmpty()) {
            return 0;
        }

        return $durations->avg();
    }

    private function formatDuration(float $seconds): string
    {
        if ($seconds < 60) {
            return round($seconds).'s';
        }

        $minutes = floor($seconds / 60);
        $remainingSeconds = round($seconds % 60);

        return $minutes.'m '.$remainingSeconds.'s';
    }

    private function calculateChange($current, $prev, $inverse = false): string
    {
        if ($prev == 0) {
            return $current > 0 ? '+100%' : '0%';
        }

        $diff = $current - $prev;
        $percent = ($diff / $prev) * 100;
        $sign = $percent > 0 ? '+' : '';

        // For bounce rate, lower is better (so if it went down, show green/up logic in UI, but here just raw change)
        // actually UI logic handles color based on 'inverse' flag maybe?
        // Logic in View: stat.trend === 'up' ? 'text-green-500' : 'text-red-500'
        // If bounce rate goes up, it is bad.
        // Current logic in One: 'trend' => $currentBounceRate <= $prevBounceRate ? 'up' : 'down'
        // So we just return the raw string here.

        return $sign.round($percent, 1).'%';
    }
}
