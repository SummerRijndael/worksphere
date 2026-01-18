<?php

namespace App\Services\Reports;

use App\Enums\InvoiceStatus;
use App\Enums\ProjectStatus;
use App\Models\Invoice;
use App\Models\Project;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;

class ProjectReportService
{
    /**
     * Get high-level overview statistics.
     *
     * @return array{
     *     total_projects: int,
     *     active_projects: int,
     *     total_budget: float,
     *     total_revenue: float,
     *     avg_progress: float
     * }
     */
    public function getOverviewStats(?int $teamId = null): array
    {
        $query = Project::query();
        if ($teamId) {
            $query->where('team_id', $teamId);
        }

        $activeQuery = Project::active();
        if ($teamId) {
            $activeQuery->where('team_id', $teamId);
        }

        return [
            'total_projects' => $query->count('*'),
            'active_projects' => $activeQuery->count('*'),
            'total_budget' => round((float) $activeQuery->sum('budget'), 2),
            // Revenue calculated from paid invoices linked to projects
            'total_revenue' => round(
                (float) Invoice::whereNotNull('project_id', 'and')
                    ->where('status', InvoiceStatus::Paid)
                    ->when($teamId, fn ($q) => $q->whereHas('project', fn ($p) => $p->where('team_id', $teamId)))
                    ->sum('total'),
                2
            ),
            'avg_progress' => round((float) $activeQuery->avg('progress_percentage'), 1),
        ];
    }

    /**
     * Get project distribution by status.
     *
     * @return Collection<int, array{status: string, count: int, color: string}>
     */
    public function getProjectStatusDistribution(?int $teamId = null): Collection
    {
        return Project::select(['status', DB::raw('count(*) as count')])
            ->when($teamId, fn ($q) => $q->where('team_id', $teamId))
            ->groupBy('status')
            ->get()
            ->map(function ($item) {
                // Assuming the enum has a color method or we define default map
                // If ProjectStatus enum doesn't have color(), we'll fallback
                $statusEnum = $item->status;

                return [
                    'status' => $statusEnum->label(),
                    'count' => $item->count,
                    'color' => method_exists($statusEnum, 'color') ? $statusEnum->color() : 'gray',
                ];
            });
    }

    /**
     * Get top active projects comparing Budget vs Revenue (Invoiced).
     */
    public function getBudgetVsRevenue(int $limit = 5, ?int $teamId = null): Collection
    {
        // Since this method wasn't fully implemented in previous steps, I'll add the basic structure with filter
        return Project::active()
            ->when($teamId, fn ($q) => $q->where('team_id', $teamId))
            ->where('budget', '>', 0)
            ->withSum(['invoices as revenue' => function ($q) {
                $q->where('status', InvoiceStatus::Paid);
            }], 'total')
            ->orderByDesc('budget')
            ->limit($limit)
            ->get()
            ->map(function ($project) {
                return [
                    'id' => $project->id,
                    'name' => $project->name,
                    'budget' => (float) $project->budget,
                    'revenue' => (float) $project->revenue,
                    'utilization_rate' => $project->budget > 0 ? round(($project->revenue / $project->budget) * 100, 1) : 0,
                ];
            });
    }

    /**
     * Get paginated project data for detailed table.
     */
    public function getProjectsDataTable(array $filters = [], int $perPage = 15, ?int $teamId = null): LengthAwarePaginator
    {
        $query = Project::with(['client']) // 'leader' does not exist
            ->withCount(['tasks as overdue_tasks_count' => function (Builder $q) {
                $q->overdue();
            }])
            ->withSum(['invoices' => function (Builder $q) {
                $q->where('status', InvoiceStatus::Paid);
            }], 'total')
            ->when($teamId, fn ($q) => $q->where('team_id', $teamId));

        // Apply Filters
        if (! empty($filters['status'])) {
            $query->where('status', $filters['status']);
        }

        if (! empty($filters['client_id'])) {
            $query->where('client_id', $filters['client_id']);
        }

        // Search
        if (! empty($filters['search'])) {
            $query->where(function ($q) use ($filters) {
                $q->where('name', 'like', '%'.$filters['search'].'%')
                    ->orWhereHas('client', function ($c) use ($filters) {
                        $c->where('name', 'like', '%'.$filters['search'].'%');
                    });
            });
        }

        return $query->paginate($perPage)->through(function (Project $project) {
            return [
                'id' => $project->id,
                'public_id' => $project->public_id,
                'name' => $project->name,
                'client_name' => $project->client ? $project->client->name : 'N/A',
                'status' => $project->status,
                'progress' => $project->progress_percentage,
                'budget' => $project->budget,
                'collected_revenue' => $project->invoices_sum_total ?? 0,
                'due_date' => $project->due_date ? $project->due_date->format('Y-m-d') : null,
                'is_overdue' => $project->is_overdue,
                'overdue_tasks_count' => $project->overdue_tasks_count,
            ];
        });
    }

    /**
     * Get simple list of projects for selector.
     *
     * @return Collection<int, array{id: int, name: string}>
     */
    public function getProjectsForSelector(?int $teamId = null): Collection
    {
        return Project::select(['id', 'name'])
            ->when($teamId, fn ($q) => $q->where('team_id', $teamId))
            ->orderBy('name')
            ->get();
    }
}
