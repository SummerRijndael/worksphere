<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Team;
use App\Services\DashboardService;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class DashboardController extends Controller
{
    public function __construct(
        protected DashboardService $dashboardService,
        protected PermissionService $permissionService
    ) {}

    /**
     * Get complete dashboard data.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        \Illuminate\Support\Facades\Log::info('Dashboard Index Called', [
            'user_id' => $user->id,
            'session_id' => $request->hasSession() ? $request->session()->getId() : 'no-session',
        ]);
        $team = $this->resolveTeam($request);
        $projectPublicId = $request->input('project_id');
        $period = $request->input('period', 'week');

        $data = $this->dashboardService->getDashboard($user, $team, $period, $projectPublicId);

        return response()->json([
            'data' => $data,
        ]);
    }

    /**
     * Get dashboard statistics only.
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $team = $this->resolveTeam($request);

        return response()->json([
            'data' => [
                'stats' => $this->dashboardService->getStats($user, $team),
                'features' => $this->dashboardService->getFeatureFlags($user, $team),
            ],
        ]);
    }

    /**
     * Get activity feed.
     */
    public function activity(Request $request): JsonResponse
    {
        $user = $request->user();
        $team = $this->resolveTeam($request);
        $limit = $request->input('limit', 10);

        return response()->json([
            'data' => $this->dashboardService->getActivityFeed($user, $team, $limit),
        ]);
    }

    /**
     * Get chart data.
     */
    public function charts(Request $request): JsonResponse
    {
        $user = $request->user();
        $team = $this->resolveTeam($request);
        $period = $request->input('period', 'week');

        return response()->json([
            'data' => $this->dashboardService->getChartData($user, $team, $period),
        ]);
    }

    /**
     * Resolve team from request.
     */
    protected function resolveTeam(Request $request): ?Team
    {
        $teamId = $request->input('team_id');
        $user = $request->user();

        if (! $teamId) {
            // Try to get the user's first team
            $firstTeam = $user->teams()->first();

            return $firstTeam;
        }

        $team = Team::where('public_id', $teamId)->first();

        if ($team && ! $this->permissionService->isTeamMember($user, $team)) {
            abort(403, 'Unauthorized access to this team dashboard.');
        }

        return $team;
    }
}
