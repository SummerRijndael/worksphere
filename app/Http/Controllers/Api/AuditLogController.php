<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditAction;
use App\Enums\AuditCategory;
use App\Enums\AuditSeverity;
use App\Http\Controllers\Controller;
use App\Http\Resources\AuditLogResource;
use App\Models\AuditLog;
use App\Models\User;
use App\Services\AuditService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class AuditLogController extends Controller
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * List all audit logs with filtering and pagination.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AuditLog::class);

        $filters = $request->only([
            'action',
            'category',
            'severity',
            'user_id',
            'team_id',
            'auditable_type',
            'date_from',
            'date_to',
            'search',
        ]);

        $perPage = min($request->integer('per_page', 25), 100);

        $logs = $this->auditService->query($filters)->paginate($perPage);

        return AuditLogResource::collection($logs);
    }

    /**
     * Get a single audit log.
     */
    public function show(AuditLog $auditLog): AuditLogResource
    {
        $this->authorize('view', $auditLog);

        $auditLog->load(['user', 'team', 'auditable']);

        return new AuditLogResource($auditLog);
    }

    /**
     * Get audit logs for a specific user.
     */
    public function forUser(Request $request, User $user): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AuditLog::class);

        $perPage = min($request->integer('per_page', 25), 100);

        $logs = $this->auditService->getLogsForUser($user, $perPage);

        return AuditLogResource::collection($logs);
    }

    /**
     * Get audit statistics.
     */
    public function statistics(Request $request): JsonResponse
    {
        $this->authorize('viewAny', AuditLog::class);

        $days = $request->integer('days', 30);
        $statistics = $this->auditService->getStatistics($days);

        return response()->json([
            'data' => $statistics,
        ]);
    }

    /**
     * Export audit logs.
     */
    public function export(Request $request): JsonResponse
    {
        $this->authorize('export', AuditLog::class);

        $filters = $request->only([
            'action',
            'category',
            'severity',
            'user_id',
            'team_id',
            'date_from',
            'date_to',
            'search',
        ]);

        $limit = min($request->integer('limit', 1000), 10000);

        $logs = $this->auditService->export($filters, $limit);

        // Log the export action
        $this->auditService->log(
            action: AuditAction::DataExported,
            category: AuditCategory::Security,
            user: $request->user(),
            context: [
                'export_type' => 'audit_logs',
                'filters' => $filters,
                'record_count' => $logs->count(),
            ]
        );

        return response()->json([
            'data' => $logs,
            'meta' => [
                'total' => $logs->count(),
                'exported_at' => now()->toIso8601String(),
            ],
        ]);
    }

    /**
     * Get filter options (actions, categories, severities).
     */
    public function filters(): JsonResponse
    {
        return response()->json([
            'actions' => collect(AuditAction::cases())->map(fn ($action) => [
                'value' => $action->value,
                'label' => $action->label(),
                'icon' => $action->icon(),
            ]),
            'categories' => collect(AuditCategory::cases())->map(fn ($category) => [
                'value' => $category->value,
                'label' => $category->label(),
                'color' => $category->color(),
                'icon' => $category->icon(),
            ]),
            'severities' => collect(AuditSeverity::cases())->map(fn ($severity) => [
                'value' => $severity->value,
                'label' => $severity->label(),
                'color' => $severity->color(),
            ]),
        ]);
    }
}
