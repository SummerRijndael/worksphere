<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditAction;
use App\Enums\AuditCategory;
use App\Http\Controllers\Controller;
use App\Services\AuditService;
use App\Services\MaintenanceService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\ValidationException;

class MaintenanceController extends Controller
{
    public function __construct(
        protected MaintenanceService $maintenanceService,
        protected AuditService $auditService,
        protected \App\Services\System\StorageMonitorService $storageService
    ) {}

    // ... (keep existing methods)

    /**
     * Get system information.
     */
    public function systemInfo(): JsonResponse
    {
        $info = $this->maintenanceService->getSystemInfo();

        return response()->json([
            'data' => $info,
        ]);
    }

    /**
     * Get maintenance mode status.
     */
    public function status(): JsonResponse
    {
        $isDown = $this->maintenanceService->isMaintenanceMode();
        $info = $this->maintenanceService->getMaintenanceInfo();

        return response()->json([
            'data' => [
                'enabled' => $isDown,
                'reason' => $info['reason'] ?? null,
                'secret' => $info['secret'] ?? null,
                'started_at' => $info['started_at'] ?? null,
            ],
        ]);
    }

    /**
     * Enable maintenance mode (requires password confirmation and reason).
     */
    public function enable(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'password' => 'required|string',
            'reason' => 'required|string|min:3|max:500',
            'secret' => 'nullable|string|min:6|max:50|alpha_dash',
        ]);

        // Verify user's password
        $user = $request->user();
        if (! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['The provided password is incorrect.'],
            ]);
        }

        // Check if already in maintenance mode
        if ($this->maintenanceService->isMaintenanceMode()) {
            return response()->json([
                'message' => 'Application is already in maintenance mode.',
            ], 422);
        }

        $this->maintenanceService->enableMaintenanceMode(
            reason: $validated['reason'],
            secret: $validated['secret'] ?? null
        );

        // Log the action
        $this->auditService->log(
            action: AuditAction::MaintenanceEnabled,
            category: AuditCategory::System,
            user: $user,
            context: [
                'reason' => $validated['reason'],
                'has_secret' => ! empty($validated['secret']),
            ]
        );

        return response()->json([
            'message' => 'Maintenance mode enabled successfully.',
            'data' => [
                'enabled' => true,
                'reason' => $validated['reason'],
                'secret' => $validated['secret'] ?? null,
            ],
        ]);
    }

    /**
     * Disable maintenance mode.
     */
    public function disable(Request $request): JsonResponse
    {
        if (! $this->maintenanceService->isMaintenanceMode()) {
            return response()->json([
                'message' => 'Application is not in maintenance mode.',
            ], 422);
        }

        $this->maintenanceService->disableMaintenanceMode();

        // Log the action
        $this->auditService->log(
            action: AuditAction::MaintenanceDisabled,
            category: AuditCategory::System,
            user: $request->user()
        );

        return response()->json([
            'message' => 'Maintenance mode disabled successfully.',
            'data' => [
                'enabled' => false,
            ],
        ]);
    }

    /**
     * Clear application cache.
     */
    public function clearCache(Request $request): JsonResponse
    {
        $result = $this->maintenanceService->clearApplicationCache();

        $this->auditService->log(
            action: AuditAction::CacheCleared,
            category: AuditCategory::System,
            user: $request->user(),
            context: ['type' => 'application']
        );

        return response()->json($result);
    }

    /**
     * Clear view cache.
     */
    public function clearViews(Request $request): JsonResponse
    {
        $result = $this->maintenanceService->clearViewCache();

        $this->auditService->log(
            action: AuditAction::CacheCleared,
            category: AuditCategory::System,
            user: $request->user(),
            context: ['type' => 'views']
        );

        return response()->json($result);
    }

    /**
     * Clear all sessions.
     */
    public function clearSessions(Request $request): JsonResponse
    {
        // Require password confirmation for this dangerous action
        $validated = $request->validate([
            'password' => 'required|string',
        ]);

        $user = $request->user();
        if (! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['The provided password is incorrect.'],
            ]);
        }

        $result = $this->maintenanceService->clearAllSessions();

        $this->auditService->log(
            action: AuditAction::SessionsCleared,
            category: AuditCategory::Security,
            user: $user
        );

        return response()->json($result);
    }

    /**
     * Clear old log files.
     */
    public function clearLogs(Request $request): JsonResponse
    {
        $daysOld = $request->integer('days_old', 30);
        $result = $this->maintenanceService->clearOldLogs($daysOld);

        $this->auditService->log(
            action: AuditAction::LogsCleared,
            category: AuditCategory::System,
            user: $request->user(),
            context: [
                'days_old' => $daysOld,
                'deleted_count' => $result['deleted_count'] ?? 0,
            ]
        );

        return response()->json($result);
    }

    /**
     * Get scheduled tasks.
     */
    public function scheduledTasks(): JsonResponse
    {
        $tasks = $this->maintenanceService->getScheduledTasks();

        return response()->json([
            'data' => $tasks,
        ]);
    }

    /**
     * Run a scheduled task manually.
     */
    public function runTask(Request $request, string $task): JsonResponse
    {
        $result = $this->maintenanceService->runScheduledTask($task);

        if ($result['success']) {
            $this->auditService->log(
                action: AuditAction::ScheduledTaskRun,
                category: AuditCategory::System,
                user: $request->user(),
                context: ['task' => $task]
            );
        }

        return response()->json($result, $result['success'] ? 200 : 500);
    }

    /**
     * Get completed jobs.
     */
    public function queueCompleted(Request $request): JsonResponse
    {
        $limit = $request->integer('limit', 50);
        $jobs = $this->maintenanceService->getCompletedJobs($limit);

        return response()->json([
            'data' => $jobs,
        ]);
    }

    /**
     * Get storage utilization statistics.
     */
    public function storageStats(Request $request): JsonResponse
    {
        $type = $request->input('type');

        if ($type === 'local') {
            return response()->json([
                'data' => [
                    'local' => $this->storageService->getLocalUsage(),
                ],
            ]);
        }

        if ($type === 's3') {
            return response()->json([
                'data' => [
                    's3' => $this->storageService->getS3Usage(),
                ],
            ]);
        }

        $stats = $this->storageService->getStorageStats();

        return response()->json([
            'data' => $stats,
        ]);
    }

    /**
     * Get PHP Configuration Information.
     */
    public function phpInfo(): JsonResponse
    {
        return response()->json([
            'data' => $this->maintenanceService->getPhpInfo(),
        ]);
    }

    /**
     * Get Database Table Health.
     */
    public function databaseHealth(Request $request): JsonResponse
    {
        $page = $request->integer('page', 1);
        $perPage = $request->integer('per_page', 10);

        return response()->json([
            'data' => $this->maintenanceService->getDatabaseHealth($page, $perPage),
        ]);
    }

    /**
     * Get recent logs.
     */
    public function logs(Request $request): JsonResponse
    {
        $lines = $request->integer('lines', 100);

        return response()->json([
            'data' => $this->maintenanceService->getLogs($lines),
        ]);
    }

    /**
     * Get list of backups.
     */
    public function backups(Request $request): JsonResponse
    {
        $page = $request->integer('page', 1);
        $perPage = $request->integer('per_page', 20);

        return response()->json([
            'data' => $this->maintenanceService->getBackups($page, $perPage),
        ]);
    }

    /**
     * Create a backup.
     */
    public function createBackup(Request $request): JsonResponse
    {
        $option = $request->input('option', 'both');
        $result = $this->maintenanceService->createBackup($option);

        return response()->json($result, $result['success'] ? 200 : 500);
    }

    /**
     * Delete a backup.
     */
    public function deleteBackup(Request $request): JsonResponse
    {
        $path = $request->input('path');
        $this->maintenanceService->deleteBackup($path);

        return response()->json(['message' => 'Backup deleted successfully.']);
    }

    /**
     * Bulk Delete Backups.
     */
    public function bulkDelete(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'paths' => 'required|array|min:1',
            'paths.*' => 'required|string',
        ]);

        $result = $this->maintenanceService->bulkDeleteBackups($validated['paths']);

        return response()->json([
            'message' => "{$result['deleted']} backups deleted successfully.",
        ]);
    }

    /**
     * Download a backup (Secure Flow).
     */
    public function secureDownload(Request $request)
    {
        $validated = $request->validate([
            'password' => 'required|string',
            'reason' => 'required|string|min:3|max:500',
            'paths' => 'required|array|min:1',
            'paths.*' => 'required|string',
        ]);

        $user = $request->user();
        if (! Hash::check($validated['password'], $user->password)) {
            throw ValidationException::withMessages([
                'password' => ['The provided password is incorrect.'],
            ]);
        }

        try {
            $result = $this->maintenanceService->processSecureDownload($user, $validated['paths'], $validated['reason']);

        } catch (\Throwable $e) {
            return response()->json(['message' => 'Secure download failed: '.$e->getMessage()], 500);
        }
    }

    /**
     * Download a backup directly (Deprecated for Frontend).
     */
    public function downloadBackup(Request $request)
    {
        $path = $request->input('path');

        return $this->maintenanceService->downloadBackup($path);
    }

    /**
     * Get External Services status.
     */
    public function externalServices(): JsonResponse
    {
        return response()->json([
            'data' => $this->maintenanceService->getExternalServicesStatus(),
        ]);
    }
}
