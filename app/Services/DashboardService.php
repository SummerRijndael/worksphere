<?php

namespace App\Services;

use App\Models\AuditLog;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\Ticket;
use App\Models\User;
use Carbon\Carbon;

class DashboardService
{
    /**
     * Get all dashboard data for a user.
     *
     * @return array<string, mixed>
     */
    public function getDashboard(User $user, ?Team $team = null): array
    {
        return [
            'stats' => $this->getStats($user, $team),
            'features' => $this->getFeatureFlags($user, $team),
            'activity' => $this->getActivityFeed($user, $team, 5),
            'projects' => $this->getProjectSummary($user, $team, 4),
            'charts' => $this->getChartData($user, $team, 'week'),
        ];
    }

    /**
     * Get feature-based statistics.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getStats(User $user, ?Team $team = null): array
    {
        $stats = [];
        $features = $this->getFeatureFlags($user, $team);

        // Projects stat
        if ($features['projects_enabled']) {
            $stats[] = $this->getProjectStats($user, $team);
        }

        // Tasks stat
        if ($features['tasks_enabled']) {
            $stats[] = $this->getTaskStats($user, $team);
        }

        // Tickets stat
        if ($features['tickets_enabled']) {
            $stats[] = $this->getTicketStats($user, $team);
        }

        // Team members stat (always show if user is in a team)
        if ($team) {
            $stats[] = $this->getTeamMemberStats($team);
        }

        return $stats;
    }

    /**
     * Get feature flags based on user permissions.
     *
     * @return array<string, bool>
     */
    public function getFeatureFlags(User $user, ?Team $team = null): array
    {
        return [
            'projects_enabled' => $user->can('projects.view') || $user->can('projects.view_assigned'),
            'tickets_enabled' => $user->can('tickets.view') || $user->can('tickets.view_own'),
            'tasks_enabled' => $user->can('tasks.view') || $user->can('tasks.view_assigned'),
            'invoices_enabled' => $user->can('invoices.view'),
        ];
    }

    /**
     * Get project statistics.
     *
     * @return array<string, mixed>
     */
    protected function getProjectStats(User $user, ?Team $team = null): array
    {
        $query = Project::query();

        if ($team) {
            $query->where('team_id', $team->id);
        }

        // Filter by user access if not admin
        if (! $user->can('projects.view')) {
            $query->whereHas('members', fn ($q) => $q->where('user_id', $user->id));
        }

        $currentCount = $query->clone()->whereNull('archived_at')->count();

        // Calculate change from last month
        $lastMonthCount = $query->clone()
            ->whereNull('archived_at')
            ->where('created_at', '<', now()->startOfMonth())
            ->count();

        $change = $this->calculateChange($currentCount, $lastMonthCount);

        return [
            'id' => 'projects',
            'label' => 'Active Projects',
            'value' => (string) $currentCount,
            'change' => $change['formatted'],
            'change_value' => $change['value'],
            'trend' => $change['trend'],
            'icon' => 'folder-kanban',
            'color' => 'from-blue-500 to-blue-600',
        ];
    }

    /**
     * Get task statistics.
     *
     * @return array<string, mixed>
     */
    protected function getTaskStats(User $user, ?Team $team = null): array
    {
        $query = Task::query();

        if ($team) {
            $query->whereHas('project', fn ($q) => $q->where('team_id', $team->id));
        }

        // Filter by user access if not admin
        if (! $user->can('tasks.view')) {
            $query->where('assigned_to', $user->id);
        }

        $currentCount = $query->clone()
            ->whereNotIn('status', ['completed', 'archived'])
            ->count();

        $lastMonthCount = $query->clone()
            ->whereNotIn('status', ['completed', 'archived'])
            ->where('created_at', '<', now()->startOfMonth())
            ->count();

        $change = $this->calculateChange($currentCount, $lastMonthCount);

        return [
            'id' => 'tasks',
            'label' => 'Active Tasks',
            'value' => (string) $currentCount,
            'change' => $change['formatted'],
            'change_value' => $change['value'],
            'trend' => $change['trend'],
            'icon' => 'clock',
            'color' => 'from-purple-500 to-purple-600',
        ];
    }

    /**
     * Get ticket statistics.
     *
     * @return array<string, mixed>
     */
    protected function getTicketStats(User $user, ?Team $team = null): array
    {
        $query = Ticket::query();

        // Filter by user access if not admin
        if (! $user->can('tickets.view')) {
            $query->where(function ($q) use ($user) {
                $q->where('reporter_id', $user->id)
                    ->orWhere('assigned_to', $user->id);
            });
        }

        $currentCount = $query->clone()
            ->whereIn('status', ['open', 'in_progress', 'pending'])
            ->count();

        $lastMonthCount = $query->clone()
            ->whereIn('status', ['open', 'in_progress', 'pending'])
            ->where('created_at', '<', now()->startOfMonth())
            ->count();

        $change = $this->calculateChange($currentCount, $lastMonthCount);

        return [
            'id' => 'tickets',
            'label' => 'Open Tickets',
            'value' => (string) $currentCount,
            'change' => $change['formatted'],
            'change_value' => $change['value'],
            'trend' => $change['trend'],
            'icon' => 'ticket',
            'color' => 'from-orange-500 to-orange-600',
        ];
    }

    /**
     * Get team member statistics.
     *
     * @return array<string, mixed>
     */
    protected function getTeamMemberStats(Team $team): array
    {
        $currentCount = $team->members()->count();

        // Members added this month
        $addedThisMonth = $team->members()
            ->wherePivot('joined_at', '>=', now()->startOfMonth())
            ->count();

        return [
            'id' => 'members',
            'label' => 'Team Members',
            'value' => (string) $currentCount,
            'change' => $addedThisMonth > 0 ? "+{$addedThisMonth}" : '0',
            'change_value' => $addedThisMonth,
            'trend' => $addedThisMonth > 0 ? 'up' : 'neutral',
            'icon' => 'users',
            'color' => 'from-emerald-500 to-emerald-600',
        ];
    }

    /**
     * Get recent activity feed.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getActivityFeed(User $user, ?Team $team = null, int $limit = 10): array
    {
        // Try to get from audit logs first
        $query = AuditLog::query()
            ->with('user:id,public_id,name')
            ->whereIn('action', [
                \App\Enums\AuditAction::Created,
                \App\Enums\AuditAction::Updated,
                \App\Enums\AuditAction::Deleted,
                \App\Enums\AuditAction::TicketCreated,
                \App\Enums\AuditAction::TicketUpdated,
                \App\Enums\AuditAction::TicketAssigned,
            ])
            ->whereIn('auditable_type', [
                'App\\Models\\Project',
                'App\\Models\\Task',
                'App\\Models\\Ticket',
            ])
            ->orderBy('created_at', 'desc')
            ->limit($limit);

        $logs = $query->get();

        return $logs->map(function (AuditLog $log) {
            return [
                'id' => $log->id,
                'user' => [
                    'name' => $log->user?->name ?? 'System',
                    'avatar_url' => $log->user?->avatar_url,
                    'initials' => $log->user?->initials ?? 'S',
                ],
                'action' => $this->formatAction($log->action, $log->auditable_type),
                'target' => $log->metadata['name'] ?? $log->metadata['title'] ?? class_basename($log->auditable_type),
                'target_type' => strtolower(class_basename($log->auditable_type)),
                'target_id' => $log->auditable_id,
                'time' => $log->created_at->diffForHumans(),
                'timestamp' => $log->created_at->toIso8601String(),
            ];
        })->toArray();
    }

    /**
     * Get project summary for dashboard.
     *
     * @return array<int, array<string, mixed>>
     */
    public function getProjectSummary(User $user, ?Team $team = null, int $limit = 4): array
    {
        $query = Project::query()
            ->with(['members:id,public_id,name'])
            ->whereNull('archived_at')
            ->orderBy('updated_at', 'desc');

        if ($team) {
            $query->where('team_id', $team->id);
        }

        if (! $user->can('projects.view')) {
            $query->whereHas('members', fn ($q) => $q->where('user_id', $user->id));
        }

        return $query->limit($limit)->get()->map(function (Project $project) {
            return [
                'id' => $project->public_id,
                'name' => $project->name,
                'progress' => $project->progress_percentage,
                'status' => [
                    'value' => $project->status->value,
                    'label' => $project->status->label(),
                ],
                'member_count' => $project->members->count(),
                'due_date' => $project->due_date?->toDateString(),
                'is_overdue' => $project->is_overdue,
            ];
        })->toArray();
    }

    /**
     * Get chart data for dashboard.
     *
     * @return array<string, mixed>
     */
    public function getChartData(User $user, ?Team $team = null, string $period = 'week'): array
    {
        $dates = $this->getDateRange($period);

        return [
            'activity' => $this->getActivityChartData($user, $team, $dates),
            'project_status' => $this->getProjectStatusChartData($user, $team),
            'ticket_trends' => $this->getTicketTrendsChartData($user, $team, $period),
        ];
    }

    /**
     * Get activity chart data (tasks and tickets created over time).
     *
     * @param  array<string>  $dates
     * @return array<string, mixed>
     */
    protected function getActivityChartData(User $user, ?Team $team, array $dates): array
    {
        $labels = [];
        $tasksData = [];
        $ticketsData = [];

        foreach ($dates as $date) {
            $carbon = Carbon::parse($date);
            $labels[] = $carbon->format('D');

            // Count tasks created on this day
            $taskQuery = Task::whereDate('created_at', $date);
            if ($team) {
                $taskQuery->whereHas('project', fn ($q) => $q->where('team_id', $team->id));
            }
            $tasksData[] = $taskQuery->count();

            // Count tickets created on this day
            $ticketQuery = Ticket::whereDate('created_at', $date);
            $ticketsData[] = $ticketQuery->count();
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Tasks',
                    'data' => $tasksData,
                    'borderColor' => 'rgb(139, 92, 246)',
                    'backgroundColor' => 'rgba(139, 92, 246, 0.1)',
                ],
                [
                    'label' => 'Tickets',
                    'data' => $ticketsData,
                    'borderColor' => 'rgb(249, 115, 22)',
                    'backgroundColor' => 'rgba(249, 115, 22, 0.1)',
                ],
            ],
        ];
    }

    /**
     * Get project status distribution chart data.
     *
     * @return array<string, mixed>
     */
    protected function getProjectStatusChartData(User $user, ?Team $team): array
    {
        $query = Project::query()->whereNull('archived_at');

        if ($team) {
            $query->where('team_id', $team->id);
        }

        if (! $user->can('projects.view')) {
            $query->whereHas('members', fn ($q) => $q->where('user_id', $user->id));
        }

        // Get raw counts by status - process each result to handle enum properly
        $results = $query->selectRaw('status, count(*) as count')
            ->groupBy('status')
            ->get();

        // Build status counts with string keys (handle both enum objects and string values)
        $statusCounts = [];
        foreach ($results as $result) {
            $statusKey = $result->status instanceof \App\Enums\ProjectStatus
                ? $result->status->value
                : (string) $result->status;
            $statusCounts[$statusKey] = $result->count;
        }

        // Use the enum to get proper display values
        $statusConfig = [
            'draft' => ['label' => 'Draft', 'color' => 'rgb(156, 163, 175)'],
            'active' => ['label' => 'Active', 'color' => 'rgb(59, 130, 246)'],
            'on_hold' => ['label' => 'On Hold', 'color' => 'rgb(245, 158, 11)'],
            'completed' => ['label' => 'Completed', 'color' => 'rgb(34, 197, 94)'],
        ];

        $labels = [];
        $data = [];
        $backgroundColors = [];

        foreach ($statusConfig as $statusValue => $config) {
            if (isset($statusCounts[$statusValue]) && $statusCounts[$statusValue] > 0) {
                $labels[] = $config['label'];
                $data[] = $statusCounts[$statusValue];
                $backgroundColors[] = $config['color'];
            }
        }

        return [
            'labels' => $labels,
            'data' => $data,
            'backgroundColor' => $backgroundColors,
        ];
    }

    /**
     * Get ticket trends chart data.
     *
     * @return array<string, mixed>
     */
    protected function getTicketTrendsChartData(User $user, ?Team $team, string $period): array
    {
        $weeks = $period === 'month' ? 4 : ($period === 'year' ? 12 : 4);
        $labels = [];
        $openedData = [];
        $closedData = [];

        for ($i = $weeks - 1; $i >= 0; $i--) {
            $startDate = now()->subWeeks($i)->startOfWeek();
            $endDate = now()->subWeeks($i)->endOfWeek();

            $labels[] = $period === 'year'
                ? $startDate->format('M')
                : 'Week '.($weeks - $i);

            $openedData[] = Ticket::whereBetween('created_at', [$startDate, $endDate])->count();
            $closedData[] = Ticket::where('status', 'closed')
                ->whereBetween('updated_at', [$startDate, $endDate])
                ->count();
        }

        return [
            'labels' => $labels,
            'datasets' => [
                [
                    'label' => 'Opened',
                    'data' => $openedData,
                    'backgroundColor' => 'rgba(249, 115, 22, 0.8)',
                ],
                [
                    'label' => 'Closed',
                    'data' => $closedData,
                    'backgroundColor' => 'rgba(34, 197, 94, 0.8)',
                ],
            ],
        ];
    }

    /**
     * Calculate percentage change between two values.
     *
     * @return array<string, mixed>
     */
    protected function calculateChange(int $current, int $previous): array
    {
        if ($previous === 0) {
            $changeValue = $current > 0 ? 100 : 0;
        } else {
            $changeValue = (int) round((($current - $previous) / $previous) * 100);
        }

        $trend = $changeValue > 0 ? 'up' : ($changeValue < 0 ? 'down' : 'neutral');
        $formatted = ($changeValue >= 0 ? '+' : '').$changeValue.'%';

        return [
            'value' => $changeValue,
            'formatted' => $formatted,
            'trend' => $trend,
        ];
    }

    /**
     * Get date range for chart period.
     *
     * @return array<string>
     */
    protected function getDateRange(string $period): array
    {
        $dates = [];
        $days = $period === 'week' ? 7 : ($period === 'month' ? 30 : 365);

        for ($i = $days - 1; $i >= 0; $i--) {
            $dates[] = now()->subDays($i)->toDateString();
        }

        return $period === 'week' ? $dates : array_slice($dates, 0, 7);
    }

    /**
     * Format action string for display.
     */
    protected function formatAction(\App\Enums\AuditAction $action, string $type): string
    {
        $typeLabel = strtolower(class_basename($type));

        return match ($action) {
            \App\Enums\AuditAction::Created => "created {$typeLabel}",
            \App\Enums\AuditAction::Updated => "updated {$typeLabel}",
            \App\Enums\AuditAction::Deleted => "deleted {$typeLabel}",
            \App\Enums\AuditAction::TicketCreated => "created {$typeLabel}",
            \App\Enums\AuditAction::TicketUpdated => "updated {$typeLabel}",
            \App\Enums\AuditAction::TicketAssigned => "was assigned {$typeLabel}",
            default => "{$action->value} {$typeLabel}",
        };
    }
}
