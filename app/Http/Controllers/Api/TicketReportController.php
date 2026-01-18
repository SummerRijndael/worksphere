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

        if (! $user->hasPermissionTo('tickets.view') && ! $user->hasRole('administrator')) {
            $filters['for_user'] = $user;
        }

        return response()->json($this->ticketService->getStats($filters));
    }

    public function workload(Request $request): JsonResponse
    {
        $user = $request->user();
        $filters = $request->all();

        if (! $user->hasPermissionTo('tickets.view') && ! $user->hasRole('administrator')) {
            $filters['for_user'] = $user;
        }

        return response()->json($this->ticketService->getUserWorkload($filters));
    }

    public function export(Request $request): JsonResponse
    {
        $user = $request->user();
        $filters = $request->all();

        // Ensure user permission is scoped
        if (! $user->hasPermissionTo('tickets.view') && ! $user->hasRole('administrator')) {
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
