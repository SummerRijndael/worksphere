<?php

namespace App\Http\Controllers\Api;

use App\Contracts\TicketServiceContract;
use App\Http\Controllers\Controller;
use App\Jobs\ExportTicketsJob;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class TicketReportController extends Controller
{
    public function __construct(
        protected TicketServiceContract $ticketService
    ) {}

    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $filters = $request->all();

        // 1. Resolve Team Scoping
        $requestedTeamId = $request->header('X-Team-ID') ?? $filters['team_id'] ?? null;
        if ($requestedTeamId) {
            $team = \App\Models\Team::where('public_id', $requestedTeamId)->first();
            if ($team) {
                // Verify Permission
                $permissionService = app(\App\Services\PermissionService::class);
                if ($user->hasRole('administrator') || $permissionService->isTeamMember($user, $team)) {
                    $filters['team_id'] = $team->id;
                } else {
                    abort(403, 'Unauthorized access to this team\'s statistics.');
                }
            } else {
                return response()->json(['total' => 0, 'open' => 0, 'in_progress' => 0, 'resolved' => 0, 'closed' => 0]);
            }
        } elseif (! $user->hasRole('administrator')) {
            // No specific team, scope to all user's teams
            $filters['team_ids'] = $user->teams()->pluck('teams.id')->toArray();
        }

        // 2. Personal scope if needed
        if (! $user->hasPermissionTo('tickets.view') && ! $user->hasRole('administrator')) {
            $filters['for_user'] = $user;
        }

        return response()->json($this->ticketService->getStats($filters));
    }

    public function workload(Request $request): JsonResponse
    {
        $user = $request->user();
        $filters = $request->all();

        if (! $user->hasPermissionTo('tickets.view')) {
            $filters['for_user'] = $user;
        }

        return response()->json($this->ticketService->getUserWorkload($filters));
    }

    public function export(Request $request): JsonResponse
    {
        $user = $request->user();
        $filters = $request->all();

        // Ensure user permission is scoped
        if (! $user->hasPermissionTo('tickets.view')) {
            $filters['for_user'] = $user;
        }

        // Remove 'for_user' object from filters if passed to Job, because User object might not serialize well or we want ID?
        // Actually, Job serialization handles models. But 'for_user' key in TicketService expects User model or ID?
        // TicketService Line 79 `query->forUser($filters['for_user'])`.
        // Ticket scope `forUser` typically accepts User model or ID.
        // Let's pass the User model.

        ExportTicketsJob::dispatch($user, $filters);

        return response()->json(['message' => 'Export started. You will be notified when it is ready.']);
    }
}
