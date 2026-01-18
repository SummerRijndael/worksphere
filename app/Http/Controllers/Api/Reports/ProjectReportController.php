<?php

namespace App\Http\Controllers\Api\Reports;

use App\Http\Controllers\Controller;
use App\Services\Reports\ProjectReportService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ProjectReportController extends Controller
{
    public function __construct(
        protected ProjectReportService $reportService
    ) {}

    /**
     * Get the overview data for the projects report dashboard.
     */
    private function resolveTeamId(Request $request): ?int
    {
        $teamId = $request->input('team_id');
        if (! $teamId) {
            return null;
        }

        // If it looks like a UUID strings
        if (is_string($teamId) && ! is_numeric($teamId)) {
            return \App\Models\Team::where('public_id', $teamId)->value('id');
        }

        return (int) $teamId;
    }

    /**
     * Get the overview data for the projects report dashboard.
     */
    public function overview(Request $request): JsonResponse
    {
        $teamId = $this->resolveTeamId($request);

        $stats = $this->reportService->getOverviewStats($teamId);
        $distribution = $this->reportService->getProjectStatusDistribution($teamId);
        $budgetVsRevenue = $this->reportService->getBudgetVsRevenue(5, $teamId); // Added limit arg 5

        return response()->json([
            'stats' => $stats,
            'charts' => [
                'status_distribution' => $distribution,
                'budget_vs_revenue' => $budgetVsRevenue,
            ],
        ]);
    }

    /**
     * Get the detailed projects list for the report table.
     */
    public function index(Request $request): JsonResponse
    {
        $filters = $request->only(['status', 'client_id', 'search']);
        $perPage = $request->input('per_page', 15);
        $teamId = $this->resolveTeamId($request);

        $projects = $this->reportService->getProjectsDataTable($filters, (int) $perPage, $teamId);

        return response()->json($projects);
    }

    /**
     * Get simple list of projects for the selector dropdown.
     */
    public function selector(Request $request): JsonResponse
    {
        $teamId = $this->resolveTeamId($request);
        $projects = $this->reportService->getProjectsForSelector($teamId);

        return response()->json($projects);
    }
}
