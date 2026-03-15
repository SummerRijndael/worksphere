<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditAction;
use App\Enums\AuditCategory;
use App\Enums\TaskChecklistItemStatus;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\StoreTaskChecklistItemRequest;
use App\Http\Requests\UpdateTaskChecklistItemRequest;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskChecklistItem;
use App\Models\Team;
use App\Services\AuditService;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Log;

class TaskChecklistItemController extends Controller
{
    public function __construct(
        protected PermissionService $permissionService,
        protected AuditService $auditService
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
        $user = $request->user();
        $this->ensureProjectBelongsToTeam($team, $project);
        $this->ensureTaskBelongsToProject($project, $task);

        if ($task->status->isLocked()) {
            abort(403, "Checklist cannot be modified when task is in '{$task->status->label()}' status.");
        }

        // Read-only logic: If in QA, only QA review can modify structure
        $isInReview = in_array($task->status, [TaskStatus::Submitted, TaskStatus::InQa, TaskStatus::PmReview]);
        $hasQaPermission = $this->permissionService->hasTeamPermission($user, $team, 'tasks.qa_review');

        if ($isInReview && ! $hasQaPermission) {
            abort(403, 'Task checklist is locked while in review.');
        }

        // New Requirement: Only Creator or TeamLead/SME can add items if task is started (InProgress or later)
        // We consider "Started" as anything not Open or Draft.
        $isStarted = ! in_array($task->status, [TaskStatus::Open, TaskStatus::Draft]);
        $canManageStarted = $task->created_by === $user->id || $this->permissionService->hasTeamPermission($user, $team, 'tasks.update');

        if ($isStarted && ! $canManageStarted) {
            abort(403, 'Cannot add checklist items after task has started.');
        }

        // Structure modification requires manage_checklist
        if (! $this->permissionService->hasTeamPermission($user, $team, 'tasks.manage_checklist')) {
            abort(403, 'You do not have permission to add items to this checklist.');
        }

        if ($task->status->isLocked()) {
            abort(403, 'Checklist cannot be modified in the current task status.');
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
        $user = $request->user();

        if ($task->status->isLocked()) {
            abort(403, "Checklist cannot be modified when task is in '{$task->status->label()}' status.");
        }

        // Read-only logic: If in QA, only QA review can modify
        $isInReview = in_array($task->status, [TaskStatus::Submitted, TaskStatus::InQa, TaskStatus::PmReview]);
        $hasQaPermission = $this->permissionService->hasTeamPermission($user, $team, 'tasks.qa_review');

        if ($isInReview && ! $hasQaPermission) {
            abort(403, 'Task checklist is locked while in review.');
        }

        // Completion requires tasks.complete_items
        if (! $this->permissionService->hasTeamPermission($user, $team, 'tasks.complete_items') &&
            $task->assigned_to !== $user->id) {
            abort(403, 'You do not have permission to update this checklist item.');
        }

        // Feature: Checklist actions disabled unless start task is pressed (In Progress)
        // This applies to changing status (completion)
        if (isset($request->status) && $task->status !== TaskStatus::InProgress) {
            // Exception: Allow if simple text update? No, `status` set means completion toggle.
            // Maybe allow PM/QA/Lead to toggle anytime? Req says "disabled unless start task is pressed".
            // We can allow if user has 'tasks.update' (Admin/Lead override).
            if (! $this->permissionService->hasTeamPermission($user, $team, 'tasks.update')) {
                abort(403, 'Task must be In Progress to complete checklist items.');
            }
        }

        // Text modification requires manage_checklist
        if (isset($request->text)) {
            if (! $this->permissionService->hasTeamPermission($user, $team, 'tasks.manage_checklist')) {
                abort(403, 'You do not have permission to modify the text of this checklist item.');
            }
            // Also enforce "Started" rule for text editing
            $isStarted = ! in_array($task->status, [TaskStatus::Open, TaskStatus::Draft]);
            $canManageStarted = $task->created_by === $user->id || $this->permissionService->hasTeamPermission($user, $team, 'tasks.update');

            if ($isStarted && ! $canManageStarted) {
                abort(403, 'Cannot edit checklist items after task has started.');
            }
        }

        // Ensure item belongs to task
        if ($checklistItem->task_id !== $task->id) {
            abort(404);
        }

        $validated = $request->validated();

        // Worker Lock / Logic
        if (isset($validated['status'])) {
            $newStatusValue = $validated['status'];
            $newStatus = $newStatusValue instanceof TaskChecklistItemStatus ? $newStatusValue : TaskChecklistItemStatus::from($newStatusValue);
            $oldStatus = $checklistItem->status;

            // Enforce worker lock: if item is InProgress or OnHold, only the worker who started it (or Admin/Lead) can change status
            if ($checklistItem->last_worked_on_by && $checklistItem->last_worked_on_by !== $user->id) {
                if (! $this->permissionService->hasTeamPermission($user, $team, 'tasks.update')) {
                    abort(403, 'This item is currently being worked on by someone else.');
                }
            }

            // Handle Transitions
            if ($oldStatus === TaskChecklistItemStatus::Todo && $newStatus === TaskChecklistItemStatus::InProgress) {
                $checklistItem->start($user);
            } elseif ($oldStatus === TaskChecklistItemStatus::InProgress && $newStatus === TaskChecklistItemStatus::OnHold) {
                $checklistItem->putOnHold();
            } elseif ($oldStatus === TaskChecklistItemStatus::OnHold && $newStatus === TaskChecklistItemStatus::InProgress) {
                $checklistItem->resume();
            } elseif ($newStatus === TaskChecklistItemStatus::Done) {
                $checklistItem->markAsDone($user);
            } elseif ($oldStatus === TaskChecklistItemStatus::Done && ($newStatus === TaskChecklistItemStatus::InProgress || $newStatus === TaskChecklistItemStatus::Todo)) {
                $checklistItem->reopen();
            } else {
                // Fallback for other status changes (e.g. from OnHold to Todo if allowed)
                $checklistItem->update(['status' => $newStatus]);
            }

            // Remove status from validated so we don't update it twice with generic logic
            unset($validated['status']);
        }

        if (! empty($validated)) {
            $checklistItem->update($validated);
        }

        return response()->json([
            'data' => $checklistItem->fresh(['completedBy:id,name', 'lastWorkedOnBy:id,name']),
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

        if ($task->status->isLocked()) {
            abort(403, "Checklist cannot be modified when task is in '{$task->status->label()}' status.");
        }

        // Read-only logic
        $isInReview = in_array($task->status, [TaskStatus::Submitted, TaskStatus::InQa, TaskStatus::PmReview]);
        $hasQaPermission = $this->permissionService->hasTeamPermission($user, $team, 'tasks.qa_review');

        if ($isInReview && ! $hasQaPermission) {
            abort(403, 'Task checklist is locked while in review.');
        }

        // New Requirement: Only Creator or TeamLead/SME can remove items if task is started
        $isStarted = ! in_array($task->status, [TaskStatus::Open, TaskStatus::Draft]);
        $canManageStarted = $task->created_by === $user->id || $this->permissionService->hasTeamPermission($user, $team, 'tasks.update');

        if ($isStarted && ! $canManageStarted) {
            abort(403, 'Cannot remove checklist items after task has started.');
        }

        // Deletion requires manage_checklist
        if (! $this->permissionService->hasTeamPermission($user, $team, 'tasks.manage_checklist')) {
            abort(403, 'You do not have permission to remove items from this checklist.');
        }

        // Ensure item belongs to task
        if ($checklistItem->task_id !== $task->id) {
            abort(404);
        }

        if ($task->status->isLocked()) {
            abort(403, 'Checklist items cannot be deleted in the current task status.');
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
        $user = $request->user();

        if ($task->status->isTerminal()) {
            abort(403, 'Checklist cannot be modified when task is completed or archived.');
        }

        // Read-only logic
        $isInReview = in_array($task->status, [TaskStatus::Submitted, TaskStatus::InQa, TaskStatus::PmReview]);
        $hasQaPermission = $this->permissionService->hasTeamPermission($user, $team, 'tasks.qa_review');

        if ($isInReview && ! $hasQaPermission) {
            abort(403, 'Task checklist is locked while in review.');
        }

        // Reordering requires manage_checklist
        if (! $this->permissionService->hasTeamPermission($user, $team, 'tasks.manage_checklist')) {
            abort(403, 'You do not have permission to reorder this checklist.');
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

        // Log the reordering action
        $this->auditService->log(
            AuditAction::Updated,
            AuditCategory::TaskManagement,
            $task,
            $user,
            null,
            null,
            [
                'action_type' => 'checklist_reordered',
                'task_title' => $task->title,
            ],
            'Reordered checklist items'
        );

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

        // Block pending teams for non-super-admins
        if ($team->status === 'pending' && ! $user->hasRole(config('roles.super_admin_role', 'administrator')) && ! $user->hasPermissionTo('user_manage')) {
            abort(403, 'Action disabled until team is approved.');
        }

        if (! $this->permissionService->hasTeamPermission($user, $team, $permission)) {
            abort(403, 'You do not have permission to perform this action.');
        }
    }

    /**
     * Ensure project belongs to the team.
     */
    protected function ensureProjectBelongsToTeam(Team $team, Project $project): void
    {
        if ($project->team_id !== $team->id) {
            abort(404, 'Project not found in this team.');
        }
    }

    /**
     * Ensure task belongs to the project.
     */
    protected function ensureTaskBelongsToProject(Project $project, Task $task): void
    {
        if ($task->project_id !== $project->id) {
            abort(404, 'Task not found in this project.');
        }
    }
}
