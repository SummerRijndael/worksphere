<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditAction;
use App\Enums\AuditCategory;
use App\Enums\TaskStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Task\AddTaskCommentRequest;
use App\Http\Requests\Task\AssignTaskRequest;
use App\Http\Requests\Task\CompleteQaReviewRequest;
use App\Http\Requests\Task\StoreTaskRequest;
use App\Http\Requests\Task\UpdateTaskRequest;
use App\Http\Resources\TaskCommentResource;
use App\Http\Resources\TaskResource;
use App\Models\Project;
use App\Models\QaCheckTemplate;
use App\Models\Task;
use App\Models\TaskComment;
use App\Models\TaskTemplate;
use App\Models\Team;
use App\Models\User;
use App\Services\AuditService;
use App\Services\MediaService;
use App\Services\PermissionService;
use App\Services\TaskWorkflowService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class TaskController extends Controller
{
    public function __construct(
        protected AuditService $auditService,
        protected PermissionService $permissionService,
        protected TaskWorkflowService $workflowService,
        protected MediaService $mediaService
    ) {}

    /**
     * Display a listing of tasks for a project.
     */
    public function index(Request $request, Team $team, Project $project): AnonymousResourceCollection
    {
        $this->authorizeTeamPermission($team, 'tasks.view');
        $this->ensureProjectBelongsToTeam($team, $project);

        $query = Task::query()
            ->where('project_id', $project->id)
            ->when(! $request->boolean('include_subtasks'), function ($query) {
                $query->whereNull('parent_id'); // Only top-level tasks by default
            })
            ->with(['assignee', 'creator', 'subtasks', 'project.client'])
            ->withCount(['subtasks', 'comments'])
            ->when($request->search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when($request->status, function ($query, $status) {
                if (str_contains($status, ',')) {
                    $query->whereIn('status', explode(',', $status));
                } else {
                    $query->where('status', $status);
                }
            })
            ->when($request->assignee, function ($query, $assignee) {
                $query->whereHas('assignee', function ($q) use ($assignee) {
                    $q->where('public_id', $assignee);
                });
            })
            ->when($request->boolean('unassigned'), function ($query) {
                $query->whereNull('assigned_to');
            })
            ->when($request->boolean('archived'), function ($query) {
                $query->where('status', TaskStatus::Archived);
            }, function ($query) use ($request) {
                if (! $request->boolean('include_archived')) {
                    $query->where('status', '!=', TaskStatus::Archived);
                }
            })
            ->when($request->boolean('overdue'), function ($query) {
                $query->whereNotNull('due_date')
                    ->where('due_date', '<', now())
                    ->whereNotIn('status', [TaskStatus::Completed, TaskStatus::Archived]);
            })
            ->when($request->priority, function ($query, $priority) {
                $query->where('priority', $priority);
            })
            ->when($request->sort_by, function ($query, $sortBy) use ($request) {
                $direction = $request->input('sort_direction', 'asc');
                $query->orderBy($sortBy, $direction);
            }, function ($query) {
                $query->orderBy('sort_order')->orderBy('created_at', 'desc');
            });

        $tasks = $query->paginate($request->integer('per_page', 25));

        return TaskResource::collection($tasks);
    }

    /**
     * Display a global listing of tasks for a project (Admin only).
     */
    public function indexGlobal(Request $request, Project $project): AnonymousResourceCollection
    {
        if (! $request->user()->hasRole('administrator')) {
            abort(403, 'Unauthorized access to global task list.');
        }

        // Reuse query logic - can refactor later if needed to dry up
        $query = Task::query()
            ->where('project_id', $project->id)
            ->when(! $request->boolean('include_subtasks'), function ($query) {
                $query->whereNull('parent_id'); // Only top-level tasks by default
            })
            ->with(['assignee', 'creator', 'subtasks', 'project.client'])
            ->withCount(['subtasks', 'comments'])
            ->when($request->search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('title', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when($request->status, function ($query, $status) {
                if (str_contains($status, ',')) {
                    $query->whereIn('status', explode(',', $status));
                } else {
                    $query->where('status', $status);
                }
            })
            ->when($request->assignee, function ($query, $assignee) {
                $query->whereHas('assignee', function ($q) use ($assignee) {
                    $q->where('public_id', $assignee);
                });
            })
            ->when($request->boolean('unassigned'), function ($query) {
                $query->whereNull('assigned_to');
            })
            ->when($request->boolean('archived'), function ($query) {
                $query->where('status', TaskStatus::Archived);
            }, function ($query) use ($request) {
                if (! $request->boolean('include_archived')) {
                    $query->where('status', '!=', TaskStatus::Archived);
                }
            })
            ->when($request->boolean('overdue'), function ($query) {
                $query->whereNotNull('due_date')
                    ->where('due_date', '<', now())
                    ->whereNotIn('status', [TaskStatus::Completed, TaskStatus::Archived]);
            })
            ->when($request->priority, function ($query, $priority) {
                $query->where('priority', $priority);
            })
            ->when($request->sort_by, function ($query, $sortBy) use ($request) {
                $direction = $request->input('sort_direction', 'asc');
                $query->orderBy($sortBy, $direction);
            }, function ($query) {
                $query->orderBy('sort_order')->orderBy('created_at', 'desc');
            });

        $tasks = $query->paginate($request->integer('per_page', 25));

        return TaskResource::collection($tasks);
    }

    /**
     * Store a newly created task.
     */
    public function store(StoreTaskRequest $request, Team $team, Project $project): JsonResponse
    {
        // Require team membership for task creation
        if (! $this->permissionService->isTeamMember($request->user(), $team)) {
            abort(403, 'You must be a member of this team to create tasks.');
        }

        $this->authorizeTeamPermission($team, 'tasks.create');
        $this->ensureProjectBelongsToTeam($team, $project);

        $validated = $request->validated();

        $taskData = [
            'project_id' => $project->id,
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'] ?? TaskStatus::Draft,
            'priority' => $validated['priority'] ?? 3,
            'due_date' => $validated['due_date'] ?? null,
            'estimated_hours' => $validated['estimated_hours'] ?? null,
            'sort_order' => $validated['sort_order'] ?? 0,
            'checklist' => $validated['checklist'] ?? null,
            'created_by' => $request->user()->id,
        ];

        // Handle parent task
        if (! empty($validated['parent_id'])) {
            $parent = Task::where('public_id', $validated['parent_id'])->first();
            if ($parent && $parent->project_id === $project->id) {
                $taskData['parent_id'] = $parent->id;
            }
        }

        // Handle task template
        if (! empty($validated['task_template_id'])) {
            $template = TaskTemplate::where('public_id', $validated['task_template_id'])->first();
            if ($template) {
                $taskData['task_template_id'] = $template->id;
                if ($template->checklist_template && empty($taskData['checklist'])) {
                    $taskData['checklist'] = $template->checklist_template;
                }
            }
        }

        // Handle assignee
        if (! empty($validated['assigned_to'])) {
            $assignee = User::where('public_id', $validated['assigned_to'])->first();
            if ($assignee && $project->hasMember($assignee)) {
                $taskData['assigned_to'] = $assignee->id;
                $taskData['assigned_by'] = $request->user()->id;
                $taskData['assigned_at'] = now();
            }
        }

        // Handle QA user
        if (! empty($validated['qa_user_id'])) {
            $qaUser = User::where('public_id', $validated['qa_user_id'])->first();
            if ($qaUser && $project->hasMember($qaUser)) {
                $taskData['qa_user_id'] = $qaUser->id;
            }
        }

        $task = Task::create($taskData);

        // Process checklist items to create interactive rows
        if (! empty($taskData['checklist']) && is_array($taskData['checklist'])) {
            foreach ($taskData['checklist'] as $index => $item) {
                // If item is string, use it as text. If object, look for text property.
                $text = is_array($item) ? ($item['text'] ?? '') : $item;
                $isCompleted = is_array($item) ? ($item['is_completed'] ?? false) : false;

                if (! empty($text)) {
                    $task->checklistItems()->create([
                        'text' => $text,
                        'position' => $index,
                        'status' => $isCompleted ? 'done' : 'todo',
                    ]);
                }
            }
        }

        // Handle Save as Template
        if ($request->boolean('save_as_template')) {
            TaskTemplate::create([
                'team_id' => $team->id,
                'name' => $task->title.' (Template)',
                'description' => $task->description,
                'default_priority' => $task->priority ?? 2,
                'default_estimated_hours' => $task->estimated_hours ?? 0,
                'checklist_template' => $taskData['checklist'],
                'is_active' => true,
                'created_by' => $request->user()->id,
            ]);
        }

        $this->auditService->log(
            action: AuditAction::Created,
            category: AuditCategory::TaskManagement,
            auditable: $task,
            context: [
                'project_id' => $project->id,
                'task_title' => $task->title,
            ]
        );

        $task->load(['assignee', 'creator', 'project']);

        return response()->json(new TaskResource($task), 201);
    }

    /**
     * Display the specified task.
     */
    public function show(Team $team, Project $project, Task $task): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'tasks.view');
        $this->ensureProjectBelongsToTeam($team, $project);
        $this->ensureTaskBelongsToProject($project, $task);

        $task->load([
            'project',
            'parent',
            'template',
            'assignee',
            'qaUser',
            'assigner',
            'creator',
            'archiver',
            'subtasks.assignee',
            'qaReviews.reviewer',
            'media',
            'project.client',
        ]);
        $task->loadCount(['subtasks', 'comments']);

        return response()->json(new TaskResource($task));
    }

    /**
     * Update the specified task.
     */
    public function update(UpdateTaskRequest $request, Team $team, Project $project, Task $task): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'tasks.update');
        $this->ensureProjectBelongsToTeam($team, $project);
        $this->ensureTaskBelongsToProject($project, $task);

        $validated = $request->validated();
        $oldValues = $task->only(['title', 'description', 'status', 'priority', 'due_date']);

        $updateData = array_filter([
            'title' => $validated['title'] ?? null,
            'description' => $validated['description'] ?? null,
            'priority' => $validated['priority'] ?? null,
            'due_date' => $validated['due_date'] ?? null,
            'estimated_hours' => $validated['estimated_hours'] ?? null,
            'actual_hours' => $validated['actual_hours'] ?? null,
            'sort_order' => $validated['sort_order'] ?? null,
            'checklist' => $validated['checklist'] ?? null,
        ], fn ($value) => $value !== null);

        // Handle status change through workflow if provided
        if (isset($validated['status']) && $validated['status'] !== $task->status->value) {
            $newStatus = TaskStatus::from($validated['status']);
            if ($task->canTransitionTo($newStatus)) {
                $task->transitionTo($newStatus, $request->user());
            } else {
                return response()->json([
                    'message' => "Cannot transition from '{$task->status->label()}' to '{$newStatus->label()}'. Allowed transitions: ".
                        implode(', ', array_map(fn ($s) => $s->label(), $task->status->allowedTransitions())),
                ], 422);
            }
        }

        // Handle assignee change
        if (isset($validated['assigned_to'])) {
            $assignee = User::where('public_id', $validated['assigned_to'])->first();
            if ($assignee && $project->hasMember($assignee)) {
                $this->workflowService->assignTask($task, $assignee, $request->user());
            }
        }

        // Handle QA user change
        if (array_key_exists('qa_user_id', $validated)) {
            if (empty($validated['qa_user_id'])) {
                $updateData['qa_user_id'] = null;
            } else {
                $qaUser = User::where('public_id', $validated['qa_user_id'])->first();
                if ($qaUser && $project->hasMember($qaUser)) {
                    $updateData['qa_user_id'] = $qaUser->id;
                }
            }
        }

        $task->update($updateData);

        // Note: For update, we are NOT syncing the checklists automatically here to avoid overwriting progress
        // unless specifically requested. Usually the checklist items are managed via separate endpoints.
        // If the user adds items in the modal during edit, checking if we should append?
        // For now, sticking to Creation-time generation as planned.

        $this->auditService->log(
            action: AuditAction::Updated,
            category: AuditCategory::TaskManagement,
            auditable: $task,
            context: [
                'old_values' => $oldValues,
                'new_values' => $task->only(['title', 'description', 'status', 'priority', 'due_date']),
            ]
        );

        $task->load(['assignee', 'creator', 'project']);

        return response()->json(new TaskResource($task));
    }

    /**
     * Remove the specified task.
     */
    public function destroy(Request $request, Team $team, Project $project, Task $task): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'tasks.delete');
        $this->ensureProjectBelongsToTeam($team, $project);
        $this->ensureTaskBelongsToProject($project, $task);

        $taskTitle = $task->title;
        $task->delete();

        $this->auditService->log(
            action: AuditAction::Deleted,
            category: AuditCategory::TaskManagement,
            auditable: $task,
            context: [
                'project_id' => $project->id,
                'task_title' => $taskTitle,
            ]
        );

        return response()->json([
            'message' => 'Task deleted successfully.',
        ]);
    }

    /**
     * Assign a task to a user.
     */
    public function assign(AssignTaskRequest $request, Team $team, Project $project, Task $task): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'tasks.assign');
        $this->ensureProjectBelongsToTeam($team, $project);
        $this->ensureTaskBelongsToProject($project, $task);

        $assignee = User::where('public_id', $request->validated('assigned_to'))->firstOrFail();

        if (! $project->hasMember($assignee)) {
            return response()->json([
                'message' => 'User must be a project member to be assigned tasks.',
            ], 422);
        }

        $this->workflowService->assignTask($task, $assignee, $request->user());
        $task->load(['assignee', 'creator', 'project']);

        return response()->json([
            'message' => 'Task assigned successfully.',
            'task' => new TaskResource($task),
        ]);
    }

    /**
     * Start working on a task.
     */
    public function start(Request $request, Team $team, Project $project, Task $task): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'tasks.update');
        $this->ensureProjectBelongsToTeam($team, $project);
        $this->ensureTaskBelongsToProject($project, $task);

        if (! $this->workflowService->startTask($task, $request->user())) {
            return response()->json([
                'message' => 'Cannot start this task. Invalid status transition.',
            ], 422);
        }

        $task->load(['assignee', 'creator', 'project']);

        return response()->json([
            'message' => 'Task started successfully.',
            'task' => new TaskResource($task),
        ]);
    }

    /**
     * Submit task for QA review.
     */
    public function submitForQa(Request $request, Team $team, Project $project, Task $task): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'tasks.submit');
        $this->ensureProjectBelongsToTeam($team, $project);
        $this->ensureTaskBelongsToProject($project, $task);

        if (! $task->hasAllChecklistItemsComplete()) {
            return response()->json([
                'message' => 'Cannot submit for QA. All checklist items must be completed.',
            ], 422);
        }

        $notes = $request->input('notes');

        if (! $this->workflowService->submitForQa($task, $request->user(), $notes)) {
            return response()->json([
                'message' => 'Cannot submit this task for QA. Invalid status transition.',
            ], 422);
        }

        $task->load(['assignee', 'creator', 'project']);

        return response()->json([
            'message' => 'Task submitted for QA successfully.',
            'task' => new TaskResource($task),
        ]);
    }

    /**
     * Send task to PM for review.
     */
    public function sendToPm(Request $request, Team $team, Project $project, Task $task): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'tasks.update'); // Or a specific permission
        $this->ensureProjectBelongsToTeam($team, $project);
        $this->ensureTaskBelongsToProject($project, $task);

        $notes = $request->input('notes');

        if (! $this->workflowService->sendToPm($task, $request->user(), $notes)) {
            return response()->json([
                'message' => 'Cannot send this task to PM. Invalid status transition.',
            ], 422);
        }

        $task->load(['assignee', 'creator', 'project']);

        return response()->json([
            'message' => 'Task sent to PM successfully.',
            'task' => new TaskResource($task),
        ]);
    }

    /**
     * Toggle On Hold status.
     */
    public function toggleHold(Request $request, Team $team, Project $project, Task $task): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'tasks.update');
        $this->ensureProjectBelongsToTeam($team, $project);
        $this->ensureTaskBelongsToProject($project, $task);

        $notes = $request->input('notes');

        if (! $this->workflowService->toggleHold($task, $request->user(), $notes)) {
            return response()->json([
                'message' => 'Cannot toggle hold status for this task.',
            ], 422);
        }

        $task->load(['assignee', 'creator', 'project']);

        return response()->json([
            'message' => $task->status === TaskStatus::OnHold ? 'Task paused/on-hold.' : 'Task resumed.',
            'task' => new TaskResource($task),
        ]);
    }

    /**
     * Start QA review for a task.
     */
    public function startQaReview(Request $request, Team $team, Project $project, Task $task): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'tasks.qa_review');
        $this->ensureProjectBelongsToTeam($team, $project);
        $this->ensureTaskBelongsToProject($project, $task);

        $template = null;
        if ($request->has('template_id')) {
            $template = QaCheckTemplate::where('public_id', $request->input('template_id'))->first();
        }

        $review = $this->workflowService->startQaReview($task, $request->user(), $template);

        if (! $review) {
            return response()->json([
                'message' => 'Cannot start QA review. Invalid status transition.',
            ], 422);
        }

        $task->load(['assignee', 'creator', 'project', 'qaReviews.reviewer']);

        return response()->json([
            'message' => 'QA review started successfully.',
            'task' => new TaskResource($task),
            'review_id' => $review->id,
        ]);
    }

    /**
     * Complete QA review (approve or reject).
     */
    public function completeQaReview(CompleteQaReviewRequest $request, Team $team, Project $project, Task $task): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'tasks.qa_review');
        $this->ensureProjectBelongsToTeam($team, $project);
        $this->ensureTaskBelongsToProject($project, $task);

        $validated = $request->validated();
        $review = $task->qaReviews()->where('status', 'in_progress')->latest()->first();

        if (! $review) {
            return response()->json([
                'message' => 'No active QA review found for this task.',
            ], 404);
        }

        $success = $this->workflowService->completeQaReview(
            $review,
            $validated['results'] ?? [],
            $request->user(),
            $validated['approved'],
            $validated['notes'] ?? null
        );

        if (! $success) {
            return response()->json([
                'message' => 'Failed to complete QA review.',
            ], 422);
        }

        $task->refresh()->load(['assignee', 'creator', 'project', 'qaReviews.reviewer']);

        return response()->json([
            'message' => $validated['approved'] ? 'Task approved.' : 'Task rejected.',
            'task' => new TaskResource($task),
        ]);
    }

    /**
     * Send task to client for review.
     */
    public function sendToClient(Request $request, Team $team, Project $project, Task $task): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'tasks.send_to_client');
        $this->ensureProjectBelongsToTeam($team, $project);
        $this->ensureTaskBelongsToProject($project, $task);

        $message = $request->input('message');

        if (! $this->workflowService->sendToClient($task, $request->user(), $message)) {
            return response()->json([
                'message' => 'Cannot send this task to client. Invalid status transition.',
            ], 422);
        }

        $task->load(['assignee', 'creator', 'project']);

        return response()->json([
            'message' => 'Task sent to client successfully.',
            'task' => new TaskResource($task),
        ]);
    }

    /**
     * Record client approval.
     */
    public function clientApprove(Request $request, Team $team, Project $project, Task $task): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'tasks.client_response');
        $this->ensureProjectBelongsToTeam($team, $project);
        $this->ensureTaskBelongsToProject($project, $task);

        $notes = $request->input('notes');

        if (! $this->workflowService->clientApprove($task, $request->user(), $notes)) {
            return response()->json([
                'message' => 'Cannot record client approval. Invalid status transition.',
            ], 422);
        }

        $task->load(['assignee', 'creator', 'project']);

        return response()->json([
            'message' => 'Client approval recorded successfully.',
            'task' => new TaskResource($task),
        ]);
    }

    /**
     * Record client rejection.
     */
    public function clientReject(Request $request, Team $team, Project $project, Task $task): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'tasks.client_response');
        $this->ensureProjectBelongsToTeam($team, $project);
        $this->ensureTaskBelongsToProject($project, $task);

        $request->validate([
            'reason' => 'required|string|max:1000',
        ]);

        if (! $this->workflowService->clientReject($task, $request->user(), $request->input('reason'))) {
            return response()->json([
                'message' => 'Cannot record client rejection. Invalid status transition.',
            ], 422);
        }

        $task->load(['assignee', 'creator', 'project']);

        return response()->json([
            'message' => 'Client rejection recorded successfully.',
            'task' => new TaskResource($task),
        ]);
    }

    /**
     * Return task to in progress (after rejection).
     */
    public function returnToProgress(Request $request, Team $team, Project $project, Task $task): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'tasks.update');
        $this->ensureProjectBelongsToTeam($team, $project);
        $this->ensureTaskBelongsToProject($project, $task);

        $notes = $request->input('notes');

        if (! $this->workflowService->returnToProgress($task, $request->user(), $notes)) {
            return response()->json([
                'message' => 'Cannot return this task to in progress. Invalid status transition.',
            ], 422);
        }

        $task->load(['assignee', 'creator', 'project']);

        return response()->json([
            'message' => 'Task returned to in progress.',
            'task' => new TaskResource($task),
        ]);
    }

    /**
     * Complete a task.
     */
    public function complete(Request $request, Team $team, Project $project, Task $task): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'tasks.complete');
        $this->ensureProjectBelongsToTeam($team, $project);
        $this->ensureTaskBelongsToProject($project, $task);

        $notes = $request->input('notes');

        if (! $this->workflowService->completeTask($task, $request->user(), $notes)) {
            return response()->json([
                'message' => 'Cannot complete this task. Invalid status transition.',
            ], 422);
        }

        $task->load(['assignee', 'creator', 'project']);

        return response()->json([
            'message' => 'Task completed successfully.',
            'task' => new TaskResource($task),
        ]);
    }

    /**
     * Archive a task.
     */
    public function archive(Request $request, Team $team, Project $project, Task $task): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'tasks.archive');
        $this->ensureProjectBelongsToTeam($team, $project);
        $this->ensureTaskBelongsToProject($project, $task);

        if (! $this->workflowService->archiveTask($task, $request->user())) {
            return response()->json([
                'message' => 'Cannot archive this task. Invalid status transition.',
            ], 422);
        }

        $task->load(['assignee', 'creator', 'project', 'archiver']);

        return response()->json([
            'message' => 'Task archived successfully.',
            'task' => new TaskResource($task),
        ]);
    }

    /**
     * Get task comments.
     */
    public function comments(Request $request, Team $team, Project $project, Task $task): AnonymousResourceCollection
    {
        $this->authorizeTeamPermission($team, 'tasks.view');
        $this->ensureProjectBelongsToTeam($team, $project);
        $this->ensureTaskBelongsToProject($project, $task);

        $query = $task->comments()
            ->with('user')
            ->when(! $request->boolean('include_internal'), function ($query) {
                $query->where('is_internal', false);
            })
            ->orderBy('created_at', 'desc');

        return TaskCommentResource::collection($query->paginate($request->integer('per_page', 25)));
    }

    /**
     * Add a comment to a task.
     */
    public function addComment(AddTaskCommentRequest $request, Team $team, Project $project, Task $task): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'tasks.comment');
        $this->ensureProjectBelongsToTeam($team, $project);
        $this->ensureTaskBelongsToProject($project, $task);

        $validated = $request->validated();

        $comment = TaskComment::create([
            'task_id' => $task->id,
            'user_id' => $request->user()->id,
            'content' => $validated['content'],
            'is_internal' => $validated['is_internal'] ?? false,
        ]);

        $this->auditService->log(
            action: AuditAction::Created,
            category: AuditCategory::TaskManagement,
            auditable: $comment,
            context: [
                'task_id' => $task->id,
                'is_internal' => $comment->is_internal,
            ]
        );

        $comment->load('user');

        return response()->json([
            'message' => 'Comment added successfully.',
            'comment' => new TaskCommentResource($comment),
        ], 201);
    }

    /**
     * Get task status history.
     */
    public function statusHistory(Team $team, Project $project, Task $task): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'tasks.view');
        $this->ensureProjectBelongsToTeam($team, $project);
        $this->ensureTaskBelongsToProject($project, $task);

        $history = $task->statusHistory()
            ->with('changedBy')
            ->orderBy('created_at', 'desc')
            ->get()
            ->map(function ($item) {
                return [
                    'id' => $item->id,
                    'from_status' => $item->from_status,
                    'to_status' => $item->to_status,
                    'notes' => $item->notes,
                    'changed_by' => [
                        'id' => $item->changedBy->public_id,
                        'name' => $item->changedBy->name,
                    ],
                    'created_at' => $item->created_at->toIso8601String(),
                ];
            });

        return response()->json($history);
    }

    /**
     * Get files attached to a task.
     */
    public function getFiles(Request $request, Team $team, Project $project, Task $task): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'tasks.view');
        $this->ensureProjectBelongsToTeam($team, $project);
        $this->ensureTaskBelongsToProject($project, $task);

        $media = $task->getMedia('attachments');

        $files = $media->map(function ($item) {
            return [
                'id' => $item->id,
                'uuid' => $item->uuid,
                'name' => $item->name,
                'file_name' => $item->file_name,
                'mime_type' => $item->mime_type,
                'size' => $item->size,
                'created_at' => $item->created_at,
                'url' => \Illuminate\Support\Facades\URL::temporarySignedRoute(
                    'media.show',
                    now()->addMinutes(60),
                    ['media' => $item->id]
                ),
                'download_url' => \Illuminate\Support\Facades\URL::temporarySignedRoute(
                    'api.media.secure-download',
                    now()->addMinutes(60),
                    ['media' => $item->id]
                ),
            ];
        });

        return response()->json(['data' => $files]);
    }

    /**
     * Upload a file to a task.
     */
    public function uploadFile(Request $request, Team $team, Project $project, Task $task): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'tasks.manage_files');
        $this->ensureProjectBelongsToTeam($team, $project);
        $this->ensureTaskBelongsToProject($project, $task);

        $request->validate([
            'file' => 'required|file|max:51200', // 50MB
        ]);

        $media = $this->mediaService->attachFromRequest($task, 'file', 'attachments');

        $this->auditService->log(
            action: AuditAction::FileUploaded,
            category: AuditCategory::TaskManagement,
            auditable: $task,
            context: [
                'file_name' => $media->file_name,
                'file_size' => $media->size,
            ]
        );

        return response()->json([
            'message' => 'File uploaded successfully.',
            'file' => [
                'id' => $media->id,
                'name' => $media->name,
                'file_name' => $media->file_name,
                'mime_type' => $media->mime_type,
                'size' => $media->size,
                'url' => \Illuminate\Support\Facades\URL::temporarySignedRoute(
                    'media.show',
                    now()->addMinutes(60),
                    ['media' => $media->id]
                ),
            ],
        ], 201);
    }

    /**
     * Delete a file from a task.
     */
    public function deleteFile(Request $request, Team $team, Project $project, Task $task, mixed $mediaId): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'tasks.manage_files');
        $this->ensureProjectBelongsToTeam($team, $project);
        $this->ensureTaskBelongsToProject($project, $task);

        $media = $task->media()->where('id', $mediaId)->where('collection_name', 'attachments')->first();

        if (! $media) {
            return response()->json([
                'message' => 'File not found.',
            ], 404);
        }

        $fileName = $media->file_name;
        $media->delete();

        $this->auditService->log(
            action: AuditAction::FileDeleted,
            category: AuditCategory::TaskManagement,
            auditable: $task,
            context: [
                'file_name' => $fileName,
            ]
        );

        return response()->json([
            'message' => 'File deleted successfully.',
        ]);
    }

    /**
     * Download multiple task files as a zip archive.
     */
    public function downloadFiles(Request $request, Team $team, Project $project, Task $task)
    {
        $this->authorizeTeamPermission($team, 'tasks.view');
        $this->ensureProjectBelongsToTeam($team, $project);
        $this->ensureTaskBelongsToProject($project, $task);

        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'required|integer',
        ]);

        $mediaIds = $validated['ids'];

        // Get the media items that belong to this task
        $mediaItems = $task->media()
            ->where('collection_name', 'attachments')
            ->whereIn('id', $mediaIds)
            ->get();

        if ($mediaItems->isEmpty()) {
            abort(404, 'No files found');
        }

        // Use Spatie's MediaStream to create a zip on-the-fly
        $zipName = sprintf('task-%s-files-%s.zip', $task->public_id, now()->format('Ymd-His'));

        return \Spatie\MediaLibrary\Support\MediaStream::create($zipName)
            ->addMedia($mediaItems);
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
