<?php

namespace App\Http\Controllers\Api;

use App\Enums\TaskChecklistItemStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTaskChecklistItemRequest;
use App\Http\Requests\UpdateTaskChecklistItemRequest;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\Team;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TaskChecklistItemController extends Controller
{
    public function __construct(
        protected PermissionService $permissionService
    ) {}

    /**
     * Display a listing of checklist items for a task.
     */
    public function index(Team $team, Project $project, Task $task): JsonResponse
    {
        $user = request()->user();
        $hasView = $this->permissionService->hasTeamPermission($user, $team, 'tasks.view');
        $hasViewAssigned = $this->permissionService->hasTeamPermission($user, $team, 'tasks.view_assigned');

        if (! $hasView && ! $hasViewAssigned) {
            abort(403, 'You do not have permission to view tasks in this team.');
        }

        if (! $hasView && $hasViewAssigned) {
            $isAssociated = $task->assigned_to === $user->id ||
                          $task->qa_user_id === $user->id ||
                          $task->created_by === $user->id;

            if (! $isAssociated) {
                abort(403, 'You do not have permission to view this task checklist.');
            }
        }

        Log::info('TaskChecklistItemController index', ['task_id' => $task->id]);

        $items = $task->checklistItems()
            ->with('completedBy:id,name')
            ->orderBy('position')
            ->get();

        return response()->json([
            'data' => $items,
            'meta' => [
                'total' => $items->count(),
                'completed' => $items->where('status', TaskChecklistItemStatus::Done)->count(),
                'can_submit_for_review' => $task->canSubmitForReview(),
            ],
        ]);
    }

    /**
     * Store a newly created checklist item.
     */
    public function store(StoreTaskChecklistItemRequest $request, Team $team, Project $project, Task $task): JsonResponse
    {
        // Allow if user has team permission OR is the assignee
        if (! $this->permissionService->hasTeamPermission($request->user(), $team, 'tasks.update') &&
            $task->assigned_to !== $request->user()->id) {
            abort(403, 'You do not have permission to update this task checklist.');
        }

        $validated = $request->validated();

        // Sanitize text
        // Sanitize text - disabled to prevent <p> tag wrapping
        // $validated['text'] = \Mews\Purifier\Facades\Purifier::clean($validated['text']);

        // Auto-set position if not provided
        if (! isset($validated['position'])) {
            $validated['position'] = $task->checklistItems()->max('position') + 1;
        }

        $item = $task->checklistItems()->create($validated);

        return response()->json([
            'data' => $item->fresh(['completedBy:id,name']),
            'message' => 'Checklist item added.',
        ], 201);
    }

    /**
     * Display the specified checklist item.
     */
    public function show(Team $team, Project $project, Task $task, TaskChecklistItem $checklistItem): JsonResponse
    {
        $user = request()->user();
        $hasView = $this->permissionService->hasTeamPermission($user, $team, 'tasks.view');
        $hasViewAssigned = $this->permissionService->hasTeamPermission($user, $team, 'tasks.view_assigned');

        if (! $hasView && ! $hasViewAssigned) {
            abort(403, 'You do not have permission to view tasks in this team.');
        }

        if (! $hasView && $hasViewAssigned) {
            $isAssociated = $task->assigned_to === $user->id ||
                          $task->qa_user_id === $user->id ||
                          $task->created_by === $user->id;

            if (! $isAssociated) {
                abort(403, 'You do not have permission to view this task checklist.');
            }
        }

        // Ensure item belongs to task
        if ($checklistItem->task_id !== $task->id) {
            abort(404);
        }

        return response()->json([
            'data' => $checklistItem->load('completedBy:id,name'),
        ]);
    }

    /**
     * Update the specified checklist item.
     */
    public function update(UpdateTaskChecklistItemRequest $request, Team $team, Project $project, Task $task, TaskChecklistItem $checklistItem): JsonResponse
    {
        // Allow if user has team permission OR is the assignee
        if (! $this->permissionService->hasTeamPermission($request->user(), $team, 'tasks.update') &&
            $task->assigned_to !== $request->user()->id) {
            abort(403, 'You do not have permission to update this task checklist.');
        }
        // Ensure item belongs to task
        if ($checklistItem->task_id !== $task->id) {
            abort(404);
        }

        $validated = $request->validated();

        // Sanitize text if present
        if (isset($validated['text'])) {
            // Sanitize text if present - disabled
            // $validated['text'] = \Mews\Purifier\Facades\Purifier::clean($validated['text']);
        }

        // Track completion
        if (isset($validated['status'])) {
            $newStatus = $validated['status'];
            if ($newStatus === TaskChecklistItemStatus::Done || $newStatus === 'done') {
                $validated['completed_by'] = $request->user()->id;
                $validated['completed_at'] = now();
            } elseif ($checklistItem->status === TaskChecklistItemStatus::Done) {
                // Changing from done to another status
                $validated['completed_by'] = null;
                $validated['completed_at'] = null;
            }
        }

        $checklistItem->update($validated);

        return response()->json([
            'data' => $checklistItem->fresh(['completedBy:id,name']),
            'message' => 'Checklist item updated.',
            'meta' => [
                'can_submit_for_review' => $task->fresh()->canSubmitForReview(),
            ],
        ]);
    }

    /**
     * Remove the specified checklist item.
     */
    public function destroy(Team $team, Project $project, Task $task, TaskChecklistItem $checklistItem): JsonResponse
    {
        $user = request()->user();
        
        // Allow if user has team permission OR is the assignee
        if (! $this->permissionService->hasTeamPermission($user, $team, 'tasks.update') &&
            $task->assigned_to !== $user->id) {
            abort(403, 'You do not have permission to update this task checklist.');
        }

        // Ensure item belongs to task
        if ($checklistItem->task_id !== $task->id) {
            abort(404);
        }

        $checklistItem->delete();

        return response()->json([
            'message' => 'Checklist item removed.',
        ]);
    }

    /**
     * Reorder checklist items.
     */
    public function reorder(Request $request, Team $team, Project $project, Task $task): JsonResponse
    {
        // Allow if user has team permission OR is the assignee
        if (! $this->permissionService->hasTeamPermission($request->user(), $team, 'tasks.update') &&
            $task->assigned_to !== $request->user()->id) {
            abort(403, 'You do not have permission to update this task checklist.');
        }

        $validated = $request->validate([
            'items' => ['required', 'array'],
            'items.*.public_id' => ['required', 'uuid'],
            'items.*.position' => ['required', 'integer', 'min:0'],
        ]);

        foreach ($validated['items'] as $itemData) {
            $task->checklistItems()
                ->where('public_id', $itemData['public_id'])
                ->update(['position' => $itemData['position']]);
        }

        return response()->json([
            'message' => 'Checklist items reordered.',
            'data' => $task->checklistItems()->orderBy('position')->get(),
        ]);
    }

    /**
     * Authorize team permission.
     */
    protected function authorizeTeamPermission(Team $team, string $permission): void
    {
        $user = request()->user();

        if (! $this->permissionService->hasTeamPermission($user, $team, $permission)) {
            abort(403, 'You do not have permission to perform this action.');
        }
    }
}
