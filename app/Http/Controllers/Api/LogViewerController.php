<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\File;

class LogViewerController extends Controller
{
    /**
     * List all log files.
     */
    public function index()
    {
        $logPath = storage_path('app/private/sys/logs');
        $files = File::glob($logPath.'/*.log');
        
        // Also include avatar_errors.log if not already caught by glob
        $avatarLog = $logPath.'/avatar_errors.log';
        if (File::exists($avatarLog) && !in_array($avatarLog, $files)) {
            $files[] = $avatarLog;
        }

        $logs = [];
        foreach ($files as $file) {
            $logs[] = [
                'name' => basename($file),
                'size' => File::size($file),
                'updated_at' => date('Y-m-d H:i:s', File::lastModified($file)),
            ];
        }

        // Sort by updated_at desc
        usort($logs, function ($a, $b) {
            return $b['updated_at'] <=> $a['updated_at'];
        });

        return response()->json(['data' => $logs]);
    }

    /**
     * Show content of a specific log file with filtering.
     */
    public function show(Request $request)
    {
        $filename = $request->input('file');
        if (! $filename) {
            return response()->json(['message' => 'File parameter is required'], 400);
        }

        $path = storage_path('app/private/sys/logs/'.$filename);

        if (! File::exists($path)) {
            return response()->json(['message' => 'Log file not found'], 404);
        }

        // Read file
        // For very large files, this might be memory intensive.
        // We generally recommend tailing or reading last N bytes, but for full view we'll read all or limit.
        // Let's implement a size limit or pagination logic if needed.
        // For now, simpler approach: Read full file, parse into lines.

        $content = File::get($path);

        // Parse Laravel Log format
        // [2023-10-27 10:00:00] local.ERROR: Message {"context":...}
        // Pattern: /^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] (\w+)\.(\w+): (.*)/

        $lines = explode("\n", $content);
        $parsedLogs = [];
        $currentLog = null;

        // Statistics counters
        $stats = [
            'total' => 0,
            'errors' => 0,
            'warnings' => 0,
            'envs' => [],
        ];

        foreach ($lines as $line) {
            if (empty(trim($line))) {
                continue;
            }

            if (preg_match('/^\[(\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2})\] (\w+)\.(\w+): (.*)/', $line, $matches)) {
                // Save previous log if exists
                if ($currentLog) {
                    $parsedLogs[] = $currentLog;

                    // Update stats
                    $stats['total']++;
                    $level = $currentLog['level'];
                    if (in_array($level, ['error', 'critical', 'alert', 'emergency'])) {
                        $stats['errors']++;
                    } elseif (in_array($level, ['warning', 'notice'])) {
                        $stats['warnings']++;
                    }
                    $stats['envs'][$currentLog['env']] = ($stats['envs'][$currentLog['env']] ?? 0) + 1;
                }

                $currentLog = [
                    'timestamp' => $matches[1],
                    'env' => $matches[2],
                    'level' => strtolower($matches[3]),
                    'message' => $matches[4],
                    'stack_trace' => '', // Will append subsequent lines here
                ];
            } else {
                // Append to previous log's stack trace or message
                if ($currentLog) {
                    $currentLog['stack_trace'] .= $line."\n";
                }
            }
        }
        if ($currentLog) {
            $parsedLogs[] = $currentLog;

            // Update stats for last log
            $stats['total']++;
            $level = $currentLog['level'];
            if (in_array($level, ['error', 'critical', 'alert', 'emergency'])) {
                $stats['errors']++;
            } elseif (in_array($level, ['warning', 'notice'])) {
                $stats['warnings']++;
            }
            $stats['envs'][$currentLog['env']] = ($stats['envs'][$currentLog['env']] ?? 0) + 1;
        }

        // Filtering
        if ($level = $request->input('level')) {
            $parsedLogs = array_filter($parsedLogs, function ($log) use ($level) {
                return $log['level'] === strtolower($level);
            });
        }

        if ($search = $request->input('search')) {
            $parsedLogs = array_filter($parsedLogs, function ($log) use ($search) {
                return stripos($log['message'], $search) !== false || stripos($log['stack_trace'], $search) !== false;
            });
        }

        // Sorting
        $sortBy = $request->input('sort_by', 'timestamp');
        $sortDirection = $request->input('sort_direction', 'desc');

        usort($parsedLogs, function ($a, $b) use ($sortBy, $sortDirection) {
            $valA = $a[$sortBy] ?? '';
            $valB = $b[$sortBy] ?? '';

            if ($sortBy === 'timestamp') {
                return $sortDirection === 'asc'
                    ? strcmp($valA, $valB)
                    : strcmp($valB, $valA);
            }

            // Case-insensitive string comparison for other fields
            return $sortDirection === 'asc'
                ? strcasecmp($valA, $valB)
                : strcasecmp($valB, $valA);
        });

        // Pagination manually
        $page = $request->input('page', 1);
        $perPage = $request->input('per_page', 50);
        $offset = ($page - 1) * $perPage;

        $items = array_slice($parsedLogs, $offset, $perPage);
        $total = count($parsedLogs);

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => (int) $page,
                'per_page' => (int) $perPage,
                'total' => $total,
                'last_page' => ceil($total / $perPage),
                'statistics' => $stats,
            ],
        ]);
    }

    /**
     * Download log file.
     */
    public function download(Request $request)
    {
        $filename = $request->input('file');
        if (! $filename) {
            abort(400, 'File parameter is required');
        }

        $path = storage_path('app/private/sys/logs/'.$filename);
        if (! File::exists($path)) {
            abort(404);
        }

        return response()->download($path);
    }
}
