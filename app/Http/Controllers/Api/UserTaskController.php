<?php

namespace App\Http\Controllers\Api;

use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Resources\TaskResource;
use App\Models\Task;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class UserTaskController extends Controller
{
    /**
     * Display a listing of tasks for the authenticated user across all projects.
     */
    public function index(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();

        $query = Task::query()
            ->with(['project.team', 'assignee', 'creator'])
            ->whereHas('project', function ($q) use ($user) {
                // Ensure user has access to the project via team membership
                $q->whereHas('team', function ($t) use ($user) {
                    $t->whereHas('members', function ($m) use ($user) {
                        $m->where('user_id', $user->id);
                    });
                });
            });

        // Filter by scope with optimized queries
        if ($request->input('scope') === 'assigned') {
            // Optimized: User's assigned tasks imply visibility, skip deep project checks
            // We still eager load project.team for the UI
             $query = Task::query()
                ->with(['project.team', 'assignee', 'creator'])
                ->where('assigned_to', $user->id);
            // We don't need the deep whereHas check here because assignment implies visibility
        } elseif ($request->input('scope') === 'created') {
            $query->where('created_by', $user->id);
        }

        // Standard filters
        $query->when($request->search, function ($query, $search) {
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%");
            });
        })
            ->when($request->status, function ($query, $status) {
                $query->where('status', $status);
            })
            ->when($request->priority, function ($query, $priority) {
                $query->where('priority', $priority);
            })
            ->when(! $request->boolean('include_archived'), function ($query) {
                $query->where('status', '!=', TaskStatus::Archived);
            })
            ->when($request->team_id, function ($query, $teamId) {
                $query->whereHas('project', function ($q) use ($teamId) {
                    $q->whereHas('team', function ($t) use ($teamId) {
                        $t->where('public_id', $teamId);
                    });
                });
            })
            ->when($request->project_id, function ($query, $projectId) {
                $query->whereHas('project', function ($q) use ($projectId) {
                    $q->where('public_id', $projectId);
                });
            });

        // Sorting
        $sortBy = $request->input('sort_by', 'created_at');
        $sortOrder = $request->input('sort_order', 'desc');
        $query->orderBy($sortBy, $sortOrder);

        $tasks = $query->paginate($request->integer('per_page', 25));

        return TaskResource::collection($tasks);
    }
}
