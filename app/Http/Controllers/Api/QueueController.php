<?php

namespace App\Http\Controllers\Api;

use App\Events\QueueStatsUpdated;
use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Queue;
use Illuminate\Support\Facades\Redis;

class QueueController extends Controller
{
    /**
     * Get queue statistics.
     */
    public function stats()
    {
        // Count failed jobs from database
        $failedCount = DB::table('failed_jobs')->count();

        // Count pending jobs using the default queue connection
        $connection = config('queue.default');
        $queueName = config("queue.connections.{$connection}.queue", 'default');
        $pendingCount = Queue::connection($connection)->size($queueName);

        return response()->json([
            'success' => true,
            'data' => [
                'failed' => $failedCount,
                'pending' => $pendingCount,
                'connection' => $connection,
                'queue' => $queueName,
            ],
        ]);
    }

    /**
     * Get list of pending jobs.
     */
    public function pending(Request $request)
    {
        $perPage = $request->input('per_page', 10);
        $page = $request->input('page', 1);
        $connection = config('queue.default');

        if ($connection === 'redis') {
            // Discover active queues from Redis
            $prefix = config('database.redis.options.prefix', '');

            // Use Redis facade directly to avoid linter issues with Queue contract
            // This assumes the queue uses the default redis connection or we can get it from config
            $redisConnection = config('queue.connections.redis.connection', 'default');
            $keys = Redis::connection($redisConnection)->keys('queues:*');

            $queues = collect($keys)->map(function ($key) use ($prefix) {
                return str_replace($prefix.'queues:', '', $key);
            })->reject(function ($name) {
                return $name === 'failed'; // Exclude failed list
            })->values()->all();

            if (empty($queues)) {
                $queues = ['default'];
            }

            $allJobs = collect();
            $total = 0;

            foreach ($queues as $qName) {
                $qName = trim($qName);
                /** @var \Illuminate\Queue\RedisQueue $queue */
                $queue = Queue::connection($connection);

                try {
                    // Start/End logic applies to the aggregate, which is hard with multiple queues.
                    // For now, let's fetch first N from each or simply just the first valid one if we want simple debugging.
                    // BETTER APPROACH: Just fetch from the first non-empty queue, or aggregate limits.
                    // To keep it simple and performant for "Pending Jobs" list:
                    // We will just show jobs from the first queue that has them, or merge 5 from each.

                    $qSize = $queue->size($qName);
                    if ($qSize > 0) {
                        $rawJobs = $queue->getConnection()->lrange('queues:'.$qName, 0, $perPage);
                        $mapped = collect($rawJobs)->map(function ($jobJson) use ($qName) {
                            $payload = json_decode($jobJson, true);

                            return [
                                'id' => $payload['uuid'] ?? $payload['id'] ?? uniqid(),
                                'queue' => $qName,
                                'command' => $payload['displayName'] ?? 'Unknown',
                                'attempts' => $payload['attempts'] ?? 0,
                                'available_at' => isset($payload['data']['command']) ? 'Now' : 'Delayed',
                                'created_at' => isset($payload['pushedAt']) ? date('Y-m-d H:i:s', (int) $payload['pushedAt']) : 'N/A',
                            ];
                        });
                        $allJobs = $allJobs->merge($mapped);
                        $total += $qSize;
                    }
                } catch (\Throwable $e) {
                    continue;
                }
            }

            // Slice to page/perPage after merge (not efficient for huge queues but fine for maintenance view)
            $jobs = $allJobs->slice(($page - 1) * $perPage, $perPage)->values();

            return response()->json([
                'success' => true,
                'data' => [
                    'current_page' => (int) $page,
                    'data' => $jobs,
                    'total' => $total,
                    'last_page' => ceil($total / $perPage),
                ],
            ]);
        }

        // Only works with database queue driver
        if ($connection !== 'database') {
            return response()->json([
                'success' => true,
                'data' => [
                    'current_page' => 1,
                    'data' => [],
                    'total' => 0,
                    'last_page' => 1,
                ],
                'message' => 'Pending job listing only available for database queue driver',
            ]);
        }

        $jobs = DB::table('jobs')
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        $items = $jobs->getCollection()->transform(function ($job) {
            $payload = json_decode($job->payload, true);

            return [
                'id' => $job->id,
                'queue' => $job->queue,
                'command' => $payload['displayName'] ?? 'Unknown',
                'attempts' => $job->attempts,
                'available_at' => date('Y-m-d H:i:s', $job->available_at),
                'created_at' => date('Y-m-d H:i:s', $job->created_at),
            ];
        });

        $jobs->setCollection($items);

        return response()->json([
            'success' => true,
            'data' => $jobs,
        ]);
    }

    /**
     * Get list of completed jobs (Via Horizon).
     */
    public function completed(Request $request)
    {
        // Check if Horizon is installed/bound
        if (! class_exists(\Laravel\Horizon\Contracts\JobRepository::class) || ! app()->bound(\Laravel\Horizon\Contracts\JobRepository::class)) {
            return response()->json([
                'success' => true,
                'data' => [],
                'message' => 'Horizon not installed or inactive',
            ]);
        }

        $repo = app(\Laravel\Horizon\Contracts\JobRepository::class);
        $recent = $repo->getRecent(); // Returns collection of recent jobs (completed)

        \Log::info('QueueController::completed - Recent jobs count: '.count($recent));

        // Transform for frontend
        $data = collect($recent)->map(function ($job) {
            return [
                'id' => $job->id,
                'name' => $job->name,
                'queue' => $job->queue,
                'status' => $job->status,
                'completed_at' => $job->completed_at, // Return timestamp directly for frontend formatting
                'runtime' => $job->time ? number_format($job->time * 1000, 2).'ms' : 'N/A',
            ];
        })->values();

        return response()->json([
            'success' => true,
            'data' => $data,
        ]);
    }

    /**
     * Get list of failed jobs.
     */
    public function failed(Request $request)
    {
        // Try to use Horizon if available
        if (class_exists(\Laravel\Horizon\Contracts\JobRepository::class) && app()->bound(\Laravel\Horizon\Contracts\JobRepository::class)) {
            $repo = app(\Laravel\Horizon\Contracts\JobRepository::class);
            $failed = $repo->getFailed(); // Returns collection of failed jobs

            // Manual pagination or just return recent 50 (Horizon usually returns recent 50 by default in getFailed)
            // To match pagination interface strictly would require more work, but for now returning all recent failed is better than empty.

            $data = collect($failed)->map(function ($job) {
                return [
                    'id' => $job->id,
                    'uuid' => $job->id, // Horizon uses uuid as id
                    'connection' => $job->connection,
                    'queue' => $job->queue,
                    'payload' => ['displayName' => $job->name], // Simplified payload for frontend
                    'exception' => $job->exception,
                    'failed_at' => $job->failed_at ? date('Y-m-d H:i:s', $job->failed_at) : null,
                ];
            })->values();

            // Wrap in paginator structure
            return response()->json([
                'success' => true,
                'data' => [
                    'current_page' => 1,
                    'data' => $data,
                    'total' => count($data),
                    'last_page' => 1,
                ],
            ]);
        }

        $perPage = $request->input('per_page', 10);

        $jobs = DB::table('failed_jobs')
            ->orderBy('failed_at', 'desc')
            ->paginate($perPage);

        $items = $jobs->getCollection()->transform(function ($job) {
            $job->payload = json_decode($job->payload);

            return $job;
        });

        $jobs->setCollection($items);

        return response()->json([
            'success' => true,
            'data' => $jobs,
        ]);
    }

    /**
     * Retry a specific failed job.
     */
    public function retry($id)
    {
        Artisan::call('queue:retry', ['id' => [$id]]);
        $this->broadcastStats();

        return response()->json(['success' => true, 'message' => 'Job retry initiated']);
    }

    /**
     * Delete a specific failed job.
     */
    public function forget($id)
    {
        Artisan::call('queue:forget', ['id' => $id]);
        $this->broadcastStats();

        return response()->json(['success' => true, 'message' => 'Job removed from failed list']);
    }

    /**
     * Delete all failed jobs.
     */
    public function flush()
    {
        Artisan::call('queue:flush');
        $this->broadcastStats();

        return response()->json(['success' => true, 'message' => 'All failed jobs flushed']);
    }

    /**
     * Retry all failed jobs.
     */
    public function retryAll()
    {
        Artisan::call('queue:retry', ['id' => ['all']]);
        $this->broadcastStats();

        return response()->json(['success' => true, 'message' => 'All failed jobs retry initiated']);
    }

    /**
     * Broadcast current queue stats.
     */
    private function broadcastStats(): void
    {
        $failedCount = DB::table('failed_jobs')->count();
        $connection = config('queue.default');
        $queueName = config("queue.connections.{$connection}.queue", 'default');
        $pendingCount = Queue::connection($connection)->size($queueName);

        QueueStatsUpdated::dispatch([
            'failed' => $failedCount,
            'pending' => $pendingCount,
            'connection' => $connection,
            'queue' => $queueName,
        ]);
    }
}
