<?php

namespace App\Console\Commands;

use App\Events\SystemMetricsUpdated;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class StreamSystemMetrics extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'monitor:stream';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Stream system metrics to frontend via WebSockets';

    /**
     * Execute the console command.
     */
    public function handle(): void
    {
        $this->info('Starting system metrics stream...');

        // Run for slightly less than 60 seconds to allow overlap with scheduler
        $endTime = time() + 58;

        while (time() < $endTime) {
            try {
                $metrics = $this->getMetrics();

                SystemMetricsUpdated::dispatch($metrics);

                // Sleep for 5 seconds
                sleep(5);
            } catch (\Exception $e) {
                Log::error('Error streaming metrics: '.$e->getMessage());
                sleep(5);
            }
        }
    }

    /**
     * Get system metrics.
     *
     * @return array<string, mixed>
     */
    protected function getMetrics(): array
    {
        $cpuStats = $this->getCpuLoad();

        return [
            'cpu_load' => $cpuStats['total'],
            'cpu_cores' => $cpuStats['cores'],
            'memory' => $this->getMemoryUsage(),
            'timestamp' => now()->toIso8601String(),
        ];
    }

    /**
     * Previous CPU stats for delta calculation.
     */
    protected array $prevStats = [];

    /**
     * Get CPU load percentage using /proc/stat.
     */
    protected function getCpuLoad(): array
    {
        if (! file_exists('/proc/stat')) {
            $load = sys_getloadavg();

            return [
                'total' => (int) ($load[0] * 100),
                'cores' => [],
            ];
        }

        $coreStats = file_get_contents('/proc/stat');
        $lines = explode("\n", $coreStats);

        $results = [
            'total' => 0,
            'cores' => [],
        ];

        $currentStats = [];

        foreach ($lines as $line) {
            if (str_starts_with($line, 'cpu')) {
                $parts = preg_split('/\s+/', trim($line));
                $label = $parts[0];

                // user + nice + system + idle + iowait + irq + softirq + steal
                $total = $parts[1] + $parts[2] + $parts[3] + $parts[4] + $parts[5] + $parts[6] + $parts[7] + $parts[8];
                $idle = $parts[4];

                $currentStats[$label] = ['total' => $total, 'idle' => $idle];

                if (isset($this->prevStats[$label])) {
                    $diffTotal = $total - $this->prevStats[$label]['total'];
                    $diffIdle = $idle - $this->prevStats[$label]['idle'];

                    if ($diffTotal > 0) {
                        $usage = (($diffTotal - $diffIdle) / $diffTotal) * 100;
                        $val = (int) round($usage);
                    } else {
                        $val = 0;
                    }

                    if ($label === 'cpu') {
                        $results['total'] = $val;
                    } else {
                        // cpu0, cpu1, etc.
                        $results['cores'][] = $val;
                    }
                }
            }
        }

        if (empty($this->prevStats)) {
            $this->prevStats = $currentStats;
            usleep(100000); // 100ms

            return $this->getCpuLoad();
        }

        $this->prevStats = $currentStats;

        return $results;
    }

    /**
     * Get memory usage stats.
     *
     * @return array<string, mixed>
     */
    protected function getMemoryUsage(): array
    {
        // Default values
        $total = 0;
        $free = 0;

        // Try reading /proc/meminfo on Linux
        if (file_exists('/proc/meminfo')) {
            $data = explode("\n", file_get_contents('/proc/meminfo'));
            foreach ($data as $line) {
                $parts = preg_split('/\s+/', $line);
                if (empty($parts[0])) {
                    continue;
                }

                if ($parts[0] === 'MemTotal:') {
                    $total = $parts[1] * 1024;
                } // KB to Bytes
                if ($parts[0] === 'MemAvailable:') {
                    $free = $parts[1] * 1024;
                }
            }
        }

        if ($total === 0) {
            // Fallback for non-Linux or read error
            return [
                'used' => memory_get_usage(true),
                'total' => 512 * 1024 * 1024, // Assumed 512MB
                'percent' => 0,
            ];
        }

        $used = $total - $free;
        $percent = $total > 0 ? round(($used / $total) * 100) : 0;

        return [
            'used' => $this->formatBytes($used),
            'total' => $this->formatBytes($total),
            'percent' => $percent,
            'raw_used' => $used,
        ];
    }

    /**
     * Format bytes to human readable string.
     */
    protected function formatBytes(int $bytes, int $precision = 2): string
    {
        $units = ['B', 'KB', 'MB', 'GB', 'TB'];

        $bytes = max($bytes, 0);
        $pow = floor(($bytes ? log($bytes) : 0) / log(1024));
        $pow = min($pow, count($units) - 1);

        $bytes /= pow(1024, $pow);

        return round($bytes, $precision).' '.$units[$pow];
    }
}
