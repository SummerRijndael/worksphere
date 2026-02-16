<?php

namespace App\Console\Commands;

use App\Models\AnalyticsDailyStat;
use App\Models\PageView;
use Carbon\Carbon;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class ArchiveAnalytics extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'analytics:archive {--days=90 : Days to retain raw data}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Archive daily analytics and prune raw logs';

    /**
     * Execute the console command.
     */
    public function handle()
    {
        $daysToRetain = (int) $this->option('days');
        $today = now()->startOfDay();

        $this->info("Starting analytics archiving process...");

        // 1. Find days that have raw data but no archive entry
        // We look for any day before TODAY
        $datesToArchive = PageView::query()
            ->selectRaw('DATE(created_at) as date')
            ->where('created_at', '<', $today)
            ->distinct()
            ->pluck('date')
            ->filter(function ($date) {
                return ! AnalyticsDailyStat::where('date', $date)->exists();
            });

        $count = $datesToArchive->count();
        if ($count === 0) {
            $this->info("No new days to archive.");
        } else {
            $this->info("Found {$count} days to archive.");
            $bar = $this->output->createProgressBar($count);
            $bar->start();

            foreach ($datesToArchive as $dateString) {
                $this->archiveDate($dateString);
                $bar->advance();
            }

            $bar->finish();
            $this->newLine();
        }

        // 2. Prune old data
        $pruneDate = now()->subDays($daysToRetain);
        $this->info("Pruning raw data older than {$daysToRetain} days ({$pruneDate->toDateString()})...");

        $deleted = PageView::where('created_at', '<', $pruneDate)->delete();
        $this->info("Deleted {$deleted} raw page views.");
    }

    private function archiveDate(string $dateString)
    {
        $start = Carbon::parse($dateString)->startOfDay();
        $end = Carbon::parse($dateString)->endOfDay();

        // Calculate Stats
        $views = PageView::whereBetween('created_at', [$start, $end]);
        
        $totalViews = $views->count();
        if ($totalViews === 0) return;

        $uniqueVisitors = $views->distinct('session_id')->count('session_id');

        // Avg Duration
        // SQLite vs MySQL logic for diff
        $driver = DB::connection()->getDriverName();
        $select = $driver === 'sqlite' 
            ? '(strftime(\'%s\', MAX(created_at)) - strftime(\'%s\', MIN(created_at))) as duration'
            : 'TIME_TO_SEC(TIMEDIFF(MAX(created_at), MIN(created_at))) as duration';

        $durations = PageView::select('session_id', DB::raw($select))
            ->whereBetween('created_at', [$start, $end])
            ->groupBy('session_id')
            ->havingRaw('count(*) > 1')
            ->get()
            ->pluck('duration');
        
        $avgDuration = $durations->isEmpty() ? 0 : $durations->avg();

        // Bounce Rate
        $bounces = DB::table(function ($query) use ($start, $end) {
            $query->select('session_id')
                ->from('page_views')
                ->whereBetween('created_at', [$start, $end])
                ->groupBy('session_id')
                ->havingRaw('count(*) = 1');
        }, 'bounces')->count();
        
        $totalSessions = $views->distinct('session_id')->count('session_id');
        $bounceRate = $totalSessions > 0 ? ($bounces / $totalSessions) * 100 : 0;

        // JSON Stats (Top 20)
        $deviceStats = $this->getRankedStats($start, $end, 'device_type');
        $browserStats = $this->getRankedStats($start, $end, 'browser');
        $pageStats = $this->getRankedStats($start, $end, 'path');
        $refererStats = $this->getRankedStats($start, $end, 'referer'); // Needs cleaning logic if desired

        AnalyticsDailyStat::create([
            'date' => $start->toDateString(),
            'total_views' => $totalViews,
            'unique_visitors' => $uniqueVisitors,
            'avg_session_duration' => round($avgDuration, 2),
            'bounce_rate' => round($bounceRate, 2),
            'device_stats' => $deviceStats,
            'browser_stats' => $browserStats,
            'page_stats' => $pageStats,
            'referer_stats' => $refererStats,
        ]);
    }

    private function getRankedStats($start, $end, $column, $limit = 20)
    {
        return PageView::select($column, DB::raw('count(*) as count'))
            ->whereBetween('created_at', [$start, $end])
            ->whereNotNull($column)
            ->groupBy($column)
            ->orderByDesc('count')
            ->limit($limit)
            ->get()
            ->mapWithKeys(fn ($item) => [$item->$column => $item->count])
            ->toArray();
    }
}
