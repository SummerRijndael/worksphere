<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\AuditCategory;
use App\Enums\AuditSeverity;
use App\Models\AuditLog;
use App\Models\Team;
use App\Models\User;
use Illuminate\Database\Eloquent\Model;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use Throwable;

class AuditService
{
    protected bool $asyncEnabled;

    /**
     * Fields that should never be logged.
     *
     * @var array<string>
     */
    protected array $sensitiveFields;

    /**
     * Fields that should be masked (partial visibility).
     *
     * @var array<string>
     */
    protected array $maskedFields;

    public function __construct(
        protected CacheService $cache
    ) {
        $this->asyncEnabled = config('audit.async', true);
        $this->sensitiveFields = config('audit.excluded_fields', []);
        $this->maskedFields = config('audit.masked_fields', []);
    }

    /**
     * Temporary storage for model changes during a request.
     *
     * @var array<string, array>
     */
    protected array $capturedChanges = [];

    /**
     * Capture changes from a model.
     */
    public function captureChanges(Model $model): void
    {
        // Only capture if model exists and has changes
        if (! $model->exists || empty($model->getDirty())) {
            return;
        }

        $old = [];
        $new = [];

        foreach ($model->getDirty() as $key => $value) {
            // Skip sensitive fields
            if (in_array($key, $this->sensitiveFields)) {
                continue;
            }

            $original = $model->getOriginal($key);

            // Handle masking
            if (in_array($key, $this->maskedFields)) {
                $old[$key] = '********';
                $new[$key] = '********';
            } else {
                $old[$key] = $original;
                $new[$key] = $value;
            }
        }

        if (! empty($old) || ! empty($new)) {
            // Key by model class + id to handle multiple updates
            $key = get_class($model).':'.$model->getKey();
            $this->capturedChanges[$key] = [
                'old' => $old,
                'new' => $new,
                'model' => get_class($model),
                'id' => $model->getKey(),
            ];
        }
    }

    /**
     * Get executed changes.
     */
    public function getCapturedChanges(): array
    {
        return $this->capturedChanges;
    }

    /**
     * Log an audit event.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  array<string, mixed>  $context
     */
    public function log(
        AuditAction $action,
        AuditCategory $category,
        ?Model $auditable = null,
        ?User $user = null,
        ?array $oldValues = null,
        ?array $newValues = null,
        array $context = []
    ): ?AuditLog {
        try {
            $data = $this->prepareLogData(
                $action,
                $category,
                $auditable,
                $user,
                $oldValues,
                $newValues,
                $context
            );

            if (config('audit.async', true) && $this->shouldQueueLog($action)) {
                dispatch(function () use ($data): void {
                    AuditLog::create($data);
                })->afterResponse();

                return null;
            }

            $auditLog = AuditLog::create($data);
            $this->invalidateRecentLogsCache();

            return $auditLog;
        } catch (Throwable $e) {
            Log::error('Audit logging failed', [
                'action' => $action->value,
                'error' => $e->getMessage(),
            ]);

            return null;
        }
    }

    /**
     * Log authentication event.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function logAuth(
        AuditAction $action,
        ?User $user = null,
        array $metadata = []
    ): ?AuditLog {
        return $this->log(
            action: $action,
            category: AuditCategory::Authentication,
            user: $user,
            context: $metadata
        );
    }

    /**
     * Log model change (create/update/delete).
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     */
    public function logModelChange(
        AuditAction $action,
        Model $model,
        ?array $oldValues = null,
        ?array $newValues = null
    ): ?AuditLog {
        $category = $this->getCategoryForModel($model);

        return $this->log(
            action: $action,
            category: $category,
            auditable: $model,
            user: auth()->user(),
            oldValues: $this->sanitizeValues($oldValues),
            newValues: $this->sanitizeValues($newValues)
        );
    }

    /**
     * Log permission change.
     */
    public function logPermissionChange(
        AuditAction $action,
        User $targetUser,
        string $permissionOrRole,
        ?int $teamId = null
    ): ?AuditLog {
        return $this->log(
            action: $action,
            category: AuditCategory::Authorization,
            auditable: $targetUser,
            user: auth()->user(),
            context: [
                'permission_or_role' => $permissionOrRole,
                'team_id' => $teamId,
            ]
        );
    }

    /**
     * Log security event.
     *
     * @param  array<string, mixed>  $metadata
     */
    public function logSecurity(
        AuditAction $action,
        ?User $user = null,
        array $metadata = []
    ): ?AuditLog {
        return $this->log(
            action: $action,
            category: AuditCategory::Security,
            user: $user,
            context: $metadata
        );
    }

    /**
     * Get recent audit logs (from cache if available).
     *
     * @return Collection<int, AuditLog>
     */
    public function getRecentLogs(int $limit = 50): Collection
    {
        if (! config('audit.cache.enabled', true)) {
            return $this->queryRecentLogs($limit);
        }

        $cacheKey = "audit_logs:recent:{$limit}";

        return $this->cache->remember(
            $cacheKey,
            config('audit.cache.ttl', 300),
            fn () => $this->queryRecentLogs($limit),
            'audit_logs'
        );
    }

    /**
     * Get audit logs for a specific user.
     */
    public function getLogsForUser(User $user, int $perPage = 15): LengthAwarePaginator
    {
        return AuditLog::query()
            ->where('user_id', $user->id)
            ->with(['auditable'])
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Get audit logs for a specific model.
     */
    public function getLogsForModel(Model $model, int $perPage = 15): LengthAwarePaginator
    {
        return AuditLog::query()
            ->where('auditable_type', get_class($model))
            ->where('auditable_id', $model->getKey())
            ->with(['user'])
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Get audit logs for a specific team.
     */
    public function getLogsForTeam(Team $team, int $perPage = 15): LengthAwarePaginator
    {
        return AuditLog::query()
            ->where('team_id', $team->id)
            ->with(['user', 'auditable'])
            ->latest()
            ->paginate($perPage);
    }

    /**
     * Query audit logs with filters.
     *
     * @param  array<string, mixed>  $filters
     * @return \Illuminate\Database\Eloquent\Builder<AuditLog>
     */
    public function query(array $filters = []): \Illuminate\Database\Eloquent\Builder
    {
        $query = AuditLog::query()->with(['user', 'auditable']);

        if (isset($filters['action'])) {
            $query->where('action', $filters['action']);
        }

        if (isset($filters['category'])) {
            $query->where('category', $filters['category']);
        }

        if (isset($filters['severity'])) {
            $query->where('severity', $filters['severity']);
        }

        if (isset($filters['user_id'])) {
            $query->where('user_id', $filters['user_id']);
        }

        if (isset($filters['team_id'])) {
            $query->where('team_id', $filters['team_id']);
        }

        if (isset($filters['auditable_type'])) {
            $query->where('auditable_type', $filters['auditable_type']);
        }

        if (isset($filters['auditable_id'])) {
            $query->where('auditable_id', $filters['auditable_id']);
        }

        if (isset($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }

        if (isset($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        if (isset($filters['search'])) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search): void {
                $q->where('user_name', 'like', "%{$search}%")
                    ->orWhere('user_email', 'like', "%{$search}%")
                    ->orWhere('ip_address', 'like', "%{$search}%");
            });
        }

        return $query->latest();
    }

    /**
     * Prune old audit logs.
     */
    public function prune(?int $daysToKeep = null): int
    {
        $daysToKeep = $daysToKeep ?? config('audit.retention_days', 90);

        if ($daysToKeep <= 0) {
            return 0;
        }

        $cutoff = now()->subDays($daysToKeep);

        return AuditLog::where('created_at', '<', $cutoff)->delete();
    }

    /**
     * Export audit logs.
     *
     * @param  array<string, mixed>  $filters
     * @return Collection<int, array<string, mixed>>
     */
    public function export(array $filters = [], int $limit = 10000): Collection
    {
        return $this->query($filters)
            ->limit($limit)
            ->get()
            ->map(fn (AuditLog $log) => $log->toExportArray());
    }

    /**
     * Get audit statistics.
     *
     * @return array<string, mixed>
     */
    public function getStatistics(?int $days = 30): array
    {
        $startDate = now()->subDays($days);

        $total = AuditLog::where('created_at', '>=', $startDate)->count();

        $byCategory = AuditLog::where('created_at', '>=', $startDate)
            ->selectRaw('category, count(*) as count')
            ->groupBy('category')
            ->pluck('count', 'category')
            ->toArray();

        $byAction = AuditLog::where('created_at', '>=', $startDate)
            ->selectRaw('action, count(*) as count')
            ->groupBy('action')
            ->orderByDesc('count')
            ->limit(10)
            ->pluck('count', 'action')
            ->toArray();

        return [
            'total' => $total,
            'by_category' => $byCategory,
            'top_actions' => $byAction,
            'period_days' => $days,
        ];
    }

    /**
     * Prepare log data for storage.
     *
     * @param  array<string, mixed>|null  $oldValues
     * @param  array<string, mixed>|null  $newValues
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    protected function prepareLogData(
        AuditAction $action,
        AuditCategory $category,
        ?Model $auditable,
        ?User $user,
        ?array $oldValues,
        ?array $newValues,
        array $context
    ): array {
        $request = request();
        $user = $user ?? auth()->user();

        $metadata = $context;

        if (config('audit.parse_user_agent', true) && $request->userAgent()) {
            $metadata['user_agent_parsed'] = $this->parseUserAgent($request->userAgent());
        }

        return [
            'public_id' => (string) Str::uuid(),
            'user_id' => $user?->id,
            'user_name' => $user?->name ?? $context['user_name'] ?? $context['name'] ?? null,
            'user_email' => $user?->email ?? $context['user_email'] ?? $context['email'] ?? $context['credentials']['email'] ?? null,
            'team_id' => $context['team_id'] ?? null,
            'action' => $action->value,
            'category' => $category->value,
            'severity' => $this->getSeverityForAction($action)->value,
            'auditable_type' => $auditable ? get_class($auditable) : null,
            'auditable_id' => $auditable?->getKey(),
            'old_values' => $oldValues ? $this->sanitizeValues($oldValues) : null,
            'new_values' => $newValues ? $this->sanitizeValues($newValues) : null,
            'metadata' => ! empty($metadata) ? $metadata : null,
            'ip_address' => $request->ip(),
            'user_agent' => $request->userAgent() ? Str::limit($request->userAgent(), 500, '') : null,
            'url' => Str::limit($request->fullUrl(), 2048, ''),
            'method' => $request->method(),
        ];
    }

    /**
     * Sanitize values to remove sensitive data.
     *
     * @param  array<string, mixed>|null  $values
     * @return array<string, mixed>|null
     */
    public function sanitizeValues(?array $values): ?array
    {
        if (! $values) {
            return null;
        }

        $sanitized = [];

        foreach ($values as $key => $value) {
            $lowerKey = strtolower($key);

            // Skip sensitive fields entirely
            if (in_array($lowerKey, array_map('strtolower', $this->sensitiveFields))) {
                $sanitized[$key] = '[REDACTED]';

                continue;
            }

            // Mask certain fields
            if (in_array($lowerKey, array_map('strtolower', $this->maskedFields))) {
                $sanitized[$key] = $this->maskValue($value);

                continue;
            }

            // Recursively sanitize arrays
            if (is_array($value)) {
                $sanitized[$key] = $this->sanitizeValues($value);

                continue;
            }

            // Truncate long strings
            if (is_string($value) && strlen($value) > 500) {
                $sanitized[$key] = Str::limit($value, 500, '...[truncated]');

                continue;
            }

            $sanitized[$key] = $value;
        }

        return $sanitized;
    }

    /**
     * Mask a value for privacy.
     */
    protected function maskValue(mixed $value): string
    {
        if (! is_string($value)) {
            return '[MASKED]';
        }

        $length = strlen($value);

        if ($length <= 4) {
            return str_repeat('*', $length);
        }

        return substr($value, 0, 2).str_repeat('*', $length - 4).substr($value, -2);
    }

    /**
     * Get severity for an action.
     */
    protected function getSeverityForAction(AuditAction $action): AuditSeverity
    {
        return match ($action) {
            AuditAction::LoginFailed => AuditSeverity::Warning,
            AuditAction::Deleted, AuditAction::ForceDeleted => AuditSeverity::Warning,
            AuditAction::PasswordChanged, AuditAction::PasswordReset => AuditSeverity::Notice,
            AuditAction::RoleAssigned, AuditAction::RoleRevoked => AuditSeverity::Notice,
            AuditAction::PermissionGranted, AuditAction::PermissionRevoked => AuditSeverity::Notice,
            AuditAction::AllSessionsRevoked => AuditSeverity::Warning,
            AuditAction::TwoFactorDisabled => AuditSeverity::Warning,
            AuditAction::RateLimitExceeded => AuditSeverity::Warning,
            AuditAction::AccountSuspended => AuditSeverity::Warning,
            AuditAction::AccountBanned => AuditSeverity::Critical,
            default => AuditSeverity::Info,
        };
    }

    /**
     * Get category for a model.
     */
    protected function getCategoryForModel(Model $model): AuditCategory
    {
        return match (get_class($model)) {
            User::class => AuditCategory::UserManagement,
            Team::class => AuditCategory::TeamManagement,
            default => AuditCategory::DataModification,
        };
    }

    /**
     * Determine if log should be queued.
     */
    protected function shouldQueueLog(AuditAction $action): bool
    {
        return ! $action->isCritical();
    }

    /**
     * Query recent logs without cache.
     *
     * @return Collection<int, AuditLog>
     */
    protected function queryRecentLogs(int $limit): Collection
    {
        return AuditLog::query()
            ->with(['user', 'auditable'])
            ->latest()
            ->limit($limit)
            ->get();
    }

    /**
     * Invalidate recent logs cache.
     */
    protected function invalidateRecentLogsCache(): void
    {
        try {
            $this->cache->flushTags(['audit_logs:recent']);
        } catch (Throwable) {
            // Silently fail cache operations
        }
    }

    /**
     * Parse user agent string.
     *
     * @return array<string, string|null>
     */
    protected function parseUserAgent(?string $userAgent): array
    {
        if (! $userAgent) {
            return ['browser' => null, 'os' => null, 'device' => null];
        }

        $browser = 'Unknown';
        $os = 'Unknown';

        if (str_contains($userAgent, 'Chrome') && ! str_contains($userAgent, 'Edge')) {
            $browser = 'Chrome';
        } elseif (str_contains($userAgent, 'Firefox')) {
            $browser = 'Firefox';
        } elseif (str_contains($userAgent, 'Safari') && ! str_contains($userAgent, 'Chrome')) {
            $browser = 'Safari';
        } elseif (str_contains($userAgent, 'Edge')) {
            $browser = 'Edge';
        } elseif (str_contains($userAgent, 'Opera') || str_contains($userAgent, 'OPR')) {
            $browser = 'Opera';
        }

        if (str_contains($userAgent, 'Windows')) {
            $os = 'Windows';
        } elseif (str_contains($userAgent, 'Mac')) {
            $os = 'macOS';
        } elseif (str_contains($userAgent, 'Linux') && ! str_contains($userAgent, 'Android')) {
            $os = 'Linux';
        } elseif (str_contains($userAgent, 'Android')) {
            $os = 'Android';
        } elseif (str_contains($userAgent, 'iPhone') || str_contains($userAgent, 'iPad')) {
            $os = 'iOS';
        }

        $device = 'Desktop';
        if (str_contains($userAgent, 'Mobile') || str_contains($userAgent, 'Android')) {
            $device = 'Mobile';
        } elseif (str_contains($userAgent, 'Tablet') || str_contains($userAgent, 'iPad')) {
            $device = 'Tablet';
        }

        return [
            'browser' => $browser,
            'os' => $os,
            'device' => $device,
        ];
    }
}
