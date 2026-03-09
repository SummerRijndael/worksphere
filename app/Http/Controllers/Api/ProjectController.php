<?php

namespace App\Http\Controllers\Api;

use App\Enums\AuditAction;
use App\Enums\AuditCategory;
use App\Enums\InvoiceStatus;
use App\Enums\ProjectStatus;
use App\Http\Controllers\Controller;
use App\Http\Requests\Project\StoreProjectRequest;
use App\Http\Requests\Project\UpdateProjectRequest;
use App\Http\Resources\ProjectResource;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use App\Services\AuditService;
use App\Services\PermissionService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ProjectController extends Controller
{
    public function __construct(
        protected AuditService $auditService,
        protected PermissionService $permissionService,
        protected \App\Services\MediaService $mediaService
    ) {}

    /**
     * Get global or team-specific project statistics.
     */
    public function globalStats(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Project::query();

        // 1. Resolve Team Scoping
        if ($request->filled('team_id')) {
            $teamPublicId = $request->input('team_id');
            $team = Team::where('public_id', $teamPublicId)->first();

            if (! $team) {
                // If team not found, return zeroes to prevent leakage
                return response()->json([
                    'total' => 0,
                    'active' => 0,
                    'completed' => 0,
                    'overdue' => 0,
                ]);
            }

            // Verify Permission: Admin or Team Member
            if (! $user->hasRole('administrator') && ! $this->permissionService->isTeamMember($user, $team)) {
                abort(403, 'Unauthorized access to this team\'s statistics.');
            }

            $query->where('team_id', $team->id);
        } elseif (! $user->hasRole('administrator')) {
            // Non-admins MUST provide a team_id or be restricted to their teams
            // For "global" view of a regular user, return stats from all their teams (member + owned)
            $allowedTeamIds = $user->teams()->pluck('teams.id')
                ->merge($user->ownedTeams()->pluck('id'))
                ->unique();

            $query->whereIn('team_id', $allowedTeamIds);
        }
        // Admins with no team_id see global stats (original behavior)

        $stats = [
            'total' => (clone $query)->count(),
            'active' => (clone $query)->where('status', 'active')->count(),
            'completed' => (clone $query)->where('status', 'completed')->count(),
            'overdue' => (clone $query)
                ->whereNotNull('due_date')
                ->where('due_date', '<', now())
                ->whereNotIn('status', ['completed', 'archived'])
                ->count(),
        ];

        return response()->json($stats);
    }

    /**
     * Display a listing of projects for a team.
     */
    /**
     * Display a listing of projects for a team.
     */
    public function index(Request $request, Team $team): AnonymousResourceCollection
    {
        $user = $request->user();
        $hasView = $this->permissionService->hasTeamPermission($user, $team, 'projects.view');
        $hasViewAssigned = $this->permissionService->hasTeamPermission($user, $team, 'projects.view_assigned');

        if (! $hasView && ! $hasViewAssigned) {
            abort(403, 'You do not have permission to view projects in this team.');
        }

        $query = $this->getProjectsQuery($request)
            ->where('team_id', $team->id);

        // If user only has assigned view, filter by membership
        if (! $hasView && $hasViewAssigned) {
            $query->whereHas('members', function ($q) use ($user) {
                $q->where('users.id', $user->id);
            });
        }

        $projects = $query->paginate($request->integer('per_page', 15));

        return ProjectResource::collection($projects);
    }

    /**
     * Display a global listing of projects (Admin only).
     */
    public function indexGlobal(Request $request): AnonymousResourceCollection
    {
        $user = $request->user();
        $query = $this->getProjectsQuery($request);

        // If specific team is requested in filter
        if ($request->filled('team_id')) {
            $team = Team::where('public_id', $request->team_id)->first();
            if ($team) {
                // Verify Permission: Admin or Team Member/Owner
                if (! $user->hasRole('administrator') && ! $this->permissionService->isTeamMember($user, $team) && ! $team->isOwner($user)) {
                    abort(403, 'Unauthorized access to this team\'s projects.');
                }
                $query->where('team_id', $team->id);
            }
        } elseif (! $user->hasRole('administrator')) {
            // For regular users, scope to their teams (member + owned)
            $allowedTeamIds = $user->teams()->pluck('teams.id')
                ->merge($user->ownedTeams()->pluck('id'))
                ->unique();

            $query->whereIn('team_id', $allowedTeamIds);
        }

        $projects = $query->paginate($request->integer('per_page', 15));

        return ProjectResource::collection($projects);
    }

    /**
     * Display the specified project globally (Admin only).
     */
    public function showGlobal(Request $request, Project $project): JsonResponse
    {
        $this->authorizeGlobalProjectAccess($request->user(), $project);

        $project->load(['creator', 'client', 'members', 'archiver', 'team']);
        $project->loadCount(['tasks', 'members']);

        return response()->json(new ProjectResource($project));
    }

    /**
     * Get statistics for a specific project globally (Admin only).
     */
    public function statsGlobal(Request $request, Project $project): JsonResponse
    {
        $this->authorizeGlobalProjectAccess($request->user(), $project);

        $stats = [
            'total_tasks' => $project->tasks()->count(),
            'completed_tasks' => $project->tasks()->where('status', 'completed')->count(),
            'in_progress_tasks' => $project->tasks()->where('status', 'in_progress')->count(),
            'pending_tasks' => $project->tasks()->where('status', 'pending')->count(),
            'overdue_tasks' => $project->tasks()
                ->whereNotNull('due_date')
                ->where('due_date', '<', now())
                ->whereNotIn('status', ['completed', 'archived'])
                ->count(),
            'member_count' => $project->members()->count(),
            'progress_percentage' => $project->progress_percentage,
            'days_until_due' => $project->days_until_due,
            'is_overdue' => $project->is_overdue,
            'total_budget' => $project->budget,
            'total_invoiced' => $project->invoices()->where('status', '!=', InvoiceStatus::Cancelled)->sum('total'),
            'total_paid' => $project->invoices()->where('status', InvoiceStatus::Paid)->sum('total'),
            'pending_amount' => $project->invoices()->whereIn('status', [
                InvoiceStatus::Sent,
                InvoiceStatus::Viewed,
                InvoiceStatus::Overdue,
            ])->sum('total'),
            'currency' => $project->currency,
        ];

        return response()->json($stats);
    }

    /**
     * Get files for a specific project globally (Admin only).
     */
    public function filesGlobal(Request $request, Project $project): JsonResponse
    {
        $this->authorizeGlobalProjectAccess($request->user(), $project);

        $collection = $request->input('collection', 'attachments');
        $media = $project->getMedia($collection);

        $files = $media->map(function (Media $item) {
            return [
                'id' => $item->id,
                'uuid' => $item->uuid,
                'name' => $item->name,
                'file_name' => $item->file_name,
                'mime_type' => $item->mime_type,
                'size' => $item->size,
                // USE SIGNED URL (60 mins validity)
                'url' => \Illuminate\Support\Facades\URL::temporarySignedRoute(
                    'media.show',
                    now()->addMinutes(60),
                    ['media' => $item->id]
                ),
                'thumb_url' => $item->hasGeneratedConversion('thumb')
                    ? \Illuminate\Support\Facades\URL::temporarySignedRoute(
                        'media.show',
                        now()->addMinutes(60),
                        ['media' => $item->id, 'conversion' => 'thumb']
                    )
                    : null,
                'created_at' => $item->created_at,
            ];
        });

        return response()->json($files);
    }

    /**
     * Authorize access to global project endpoints for non-admins based on team permissions.
     *
     * @param  \App\Models\User  $user
     *
     * @throws \Symfony\Component\HttpKernel\Exception\HttpException
     */
    private function authorizeGlobalProjectAccess($user, Project $project): void
    {
        $team = $project->team;
        $isAdmin = $user->hasRole('administrator');

        if ($isAdmin) {
            return;
        }

        $hasView = $this->permissionService->hasTeamPermission($user, $team, 'projects.view');
        $hasViewAssigned = $this->permissionService->hasTeamPermission($user, $team, 'projects.view_assigned');

        \Illuminate\Support\Facades\Log::debug('ProjectController: authorizeGlobalProjectAccess', [
            'user_id' => $user->id,
            'team_id' => $team->id,
            'hasView' => $hasView,
            'hasViewAssigned' => $hasViewAssigned,
            'isMember' => $project->hasMember($user),
        ]);

        if (! $hasView && ! $hasViewAssigned) {
            abort(403, 'Unauthorized access to project resources.');
        }

        // For view_assigned, ensure user is a project member
        if (! $hasView && $hasViewAssigned && ! $project->hasMember($user)) {
            abort(403, 'You do not have permission to view this specific project.');
        }
    }

    /**
     * Build the base project query with filters.
     */
    protected function getProjectsQuery(Request $request): \Illuminate\Database\Eloquent\Builder
    {
        return Project::query()
            ->with(['creator', 'client', 'team'])
            ->withCount(['tasks', 'members'])
            ->when($request->search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when($request->status, function ($query, $status) {
                $query->where('status', $status);
            })
            ->when($request->priority, function ($query, $priority) {
                $query->where('priority', $priority);
            })
            ->when($request->client_id, function ($query, $clientId) {
                $query->whereHas('client', function ($q) use ($clientId) {
                    $q->where('public_id', $clientId);
                });
            })
            ->when($request->boolean('archived'), function ($query) {
                $query->archived();
            }, function ($query) use ($request) {
                if (! $request->boolean('include_archived')) {
                    $query->where('status', '!=', ProjectStatus::Archived);
                }
            })
            ->when($request->boolean('overdue'), function ($query) {
                $query->overdue();
            })
            ->when($request->public_id, function ($query, $publicId) {
                $query->where('public_id', $publicId);
            })
            ->when($request->sort_by, function ($query, $sortBy) use ($request) {
                $direction = $request->input('sort_direction', 'asc');

                // Allow sorting by team name if needed, though simpler to stick to project fields
                if ($sortBy === 'team.name') {
                    // Complex join sort, skip for now unless requested
                    $query->orderBy('created_at', 'desc');
                } else {
                    $query->orderBy($sortBy, $direction);
                }
            }, function ($query) {
                $query->orderBy('created_at', 'desc');
            });
    }

    /**
     * Store a newly created project.
     */
    /**
     * Store a newly created project.
     */
    public function store(StoreProjectRequest $request, Team $team): \Illuminate\Http\JsonResponse
    {
        // Require team membership for project creation
        if (! $this->permissionService->isTeamMember($request->user(), $team)) {
            abort(403, 'You must be a member of this team to create projects.');
        }

        $this->authorizeTeamPermission($team, 'projects.create');

        $validated = $request->validated();

        $project = Project::create([
            'team_id' => $team->id,
            'name' => $validated['name'],
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'] ?? ProjectStatus::Draft,
            'priority' => $validated['priority'] ?? 'medium',
            'start_date' => $validated['start_date'] ?? null,
            'due_date' => $validated['due_date'] ?? null,
            'client_id' => $this->resolveClientId($validated['client_id'] ?? null),
            'budget' => $validated['budget'] ?? null,
            'currency' => $validated['currency'] ?? 'USD',
            'settings' => $validated['settings'] ?? null,
            'created_by' => $request->user()->id,
        ]);

        // Add creator as manager
        $project->addMember($request->user(), 'manager');

        // Add additional members if provided
        if (! empty($validated['members'])) {
            foreach ($validated['members'] as $memberData) {
                $user = User::where('public_id', $memberData['user_id'])->first();
                if ($user && $team->hasMember($user)) {
                    $project->addMember($user, $memberData['role'] ?? 'member');
                }
            }
        }

        $this->auditService->log(
            action: AuditAction::Created,
            category: AuditCategory::ProjectManagement,
            auditable: $project,
            context: [
                'team_id' => $team->id,
                'project_name' => $project->name,
            ]
        );

        $project->load(['creator', 'client', 'members']);
        $project->loadCount(['tasks', 'members']);

        \App\Events\ProjectCreated::dispatch($project);

        return (new ProjectResource($project))->response()->setStatusCode(201);
    }

    /**
     * Display the specified project.
     */
    public function show(Team $team, Project $project): ProjectResource
    {
        $user = request()->user();
        $hasView = $this->permissionService->hasTeamPermission($user, $team, 'projects.view');
        $hasViewAssigned = $this->permissionService->hasTeamPermission($user, $team, 'projects.view_assigned');

        if (! $hasView && ! $hasViewAssigned) {
            abort(403, 'You do not have permission to perform this action.');
        }

        $this->ensureProjectBelongsToTeam($team, $project);

        if (! $hasView && $hasViewAssigned) {
            if (! $project->hasMember($user)) {
                abort(403, 'You do not have permission to view this project.');
            }
        }

        $project->load(['creator', 'client', 'members', 'archiver', 'team']);
        $project->loadCount(['tasks', 'members']);

        // dd($project->relationLoaded('client'), $project->client ? $project->client->toArray() : 'NULL CLIENT');

        return new ProjectResource($project);
    }

    /**
     * Update the specified project.
     */
    public function update(UpdateProjectRequest $request, Team $team, Project $project): ProjectResource
    {
        $this->authorizeTeamPermission($team, 'projects.update');
        $this->ensureProjectBelongsToTeam($team, $project);

        $validated = $request->validated();
        $oldValues = $project->only(['name', 'description', 'status', 'priority', 'due_date']);

        $updateData = array_filter([
            'name' => $validated['name'] ?? null,
            'description' => $validated['description'] ?? null,
            'status' => $validated['status'] ?? null,
            'priority' => $validated['priority'] ?? null,
            'start_date' => $validated['start_date'] ?? null,
            'due_date' => $validated['due_date'] ?? null,
            'budget' => $validated['budget'] ?? null,
            'currency' => $validated['currency'] ?? null,
            'settings' => $validated['settings'] ?? null,
        ], fn ($value) => $value !== null);

        if (isset($validated['client_id'])) {
            $updateData['client_id'] = $this->resolveClientId($validated['client_id']);
        }

        $project->update($updateData);

        $this->auditService->log(
            action: AuditAction::Updated,
            category: AuditCategory::ProjectManagement,
            auditable: $project,
            context: [
                'team_id' => $team->id,
                'old_values' => $oldValues,
                'new_values' => $project->only(['name', 'description', 'status', 'priority', 'due_date']),
            ]
        );

        $project->load(['creator', 'client', 'members']);
        $project->loadCount(['tasks', 'members']);

        \App\Events\ProjectUpdated::dispatch($project);

        return new ProjectResource($project);
    }

    /**
     * Remove the specified project (soft delete).
     */
    public function destroy(Request $request, Team $team, Project $project): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'projects.delete');
        $this->ensureProjectBelongsToTeam($team, $project);

        $projectName = $project->name;
        $project->delete();

        $this->auditService->log(
            action: AuditAction::Deleted,
            category: AuditCategory::ProjectManagement,
            auditable: $project,
            context: [
                'team_id' => $team->id,
                'project_name' => $projectName,
            ]
        );

        return response()->json([
            'message' => 'Project deleted successfully.',
        ]);
    }

    /**
     * Archive the project.
     */
    public function archive(Request $request, Team $team, Project $project): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'projects.archive');
        $this->ensureProjectBelongsToTeam($team, $project);

        $project->archive($request->user());

        $this->auditService->log(
            action: AuditAction::Archived,
            category: AuditCategory::ProjectManagement,
            auditable: $project,
            context: [
                'team_id' => $team->id,
                'project_name' => $project->name,
            ]
        );

        $project->load(['creator', 'client', 'members', 'archiver']);

        \App\Events\ProjectUpdated::dispatch($project);

        return response()->json([
            'message' => 'Project archived successfully.',
            'project' => new ProjectResource($project),
        ]);
    }

    /**
     * Unarchive the project.
     */
    public function unarchive(Request $request, Team $team, Project $project): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'projects.archive');
        $this->ensureProjectBelongsToTeam($team, $project);

        $project->unarchive();

        $this->auditService->log(
            action: AuditAction::Restored,
            category: AuditCategory::ProjectManagement,
            auditable: $project,
            context: [
                'team_id' => $team->id,
                'project_name' => $project->name,
            ]
        );

        $project->load(['creator', 'client', 'members']);

        \App\Events\ProjectUpdated::dispatch($project);

        return response()->json([
            'message' => 'Project unarchived successfully.',
            'project' => new ProjectResource($project),
        ]);
    }

    /**
     * Add a member to the project.
     */
    public function addMember(Request $request, Team $team, Project $project, User $user): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'projects.manage_members');
        $this->ensureProjectBelongsToTeam($team, $project);

        if (! $team->hasMember($user)) {
            return response()->json([
                'message' => 'User must be a team member first.',
            ], 422);
        }

        if ($project->hasMember($user)) {
            return response()->json([
                'message' => 'User is already a project member.',
            ], 422);
        }

        $role = $request->input('role', 'member');
        $project->addMember($user, $role);

        $this->auditService->log(
            action: AuditAction::MemberAdded,
            category: AuditCategory::ProjectManagement,
            auditable: $project,
            context: [
                'member_id' => $user->id,
                'member_name' => $user->name,
                'role' => $role,
            ]
        );

        return response()->json([
            'message' => 'Member added successfully.',
        ]);
    }

    /**
     * Remove a member from the project.
     */
    public function removeMember(Request $request, Team $team, Project $project, User $user): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'projects.manage_members');
        $this->ensureProjectBelongsToTeam($team, $project);

        if (! $project->hasMember($user)) {
            return response()->json([
                'message' => 'User is not a project member.',
            ], 404);
        }

        $project->removeMember($user);

        $this->auditService->log(
            action: AuditAction::MemberRemoved,
            category: AuditCategory::ProjectManagement,
            auditable: $project,
            context: [
                'member_id' => $user->id,
                'member_name' => $user->name,
            ]
        );

        return response()->json([
            'message' => 'Member removed successfully.',
        ]);
    }

    /**
     * Update a member's role.
     */
    public function updateMemberRole(Request $request, Team $team, Project $project, User $user): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'projects.manage_members');
        $this->ensureProjectBelongsToTeam($team, $project);

        if (! $project->hasMember($user)) {
            return response()->json([
                'message' => 'User is not a project member.',
            ], 404);
        }

        $role = $request->input('role', 'member');
        $project->updateMemberRole($user, $role);

        return response()->json([
            'message' => 'Member role updated successfully.',
        ]);
    }

    /**
     * Get project statistics.
     */
    public function stats(Team $team, Project $project): JsonResponse
    {
        $user = request()->user();
        $hasView = $this->permissionService->hasTeamPermission($user, $team, 'projects.view');
        $hasViewAssigned = $this->permissionService->hasTeamPermission($user, $team, 'projects.view_assigned');

        if (! $hasView && ! $hasViewAssigned) {
            abort(403, 'You do not have permission to perform this action.');
        }

        $this->ensureProjectBelongsToTeam($team, $project);

        if (! $hasView && $hasViewAssigned) {
            if (! $project->hasMember($user)) {
                abort(403, 'You do not have permission to view this project stats.');
            }
        }

        $cacheKey = "project_stats_{$project->id}";

        $stats = Cache::remember($cacheKey, now()->addMinutes(5), function () use ($project) {
            $taskStats = $project->tasks()
                ->selectRaw('COUNT(*) as total')
                ->selectRaw("SUM(CASE WHEN status = 'completed' THEN 1 ELSE 0 END) as completed")
                ->selectRaw("SUM(CASE WHEN status = 'in_progress' THEN 1 ELSE 0 END) as in_progress")
                ->selectRaw("SUM(CASE WHEN status = 'pending' THEN 1 ELSE 0 END) as pending")
                ->selectRaw("SUM(CASE WHEN due_date < ? AND status NOT IN ('completed', 'archived') THEN 1 ELSE 0 END) as overdue", [now()])
                ->first();

            $invoiceStats = $project->invoices()
                ->selectRaw('SUM(CASE WHEN status != ? THEN total ELSE 0 END) as total_invoiced', [InvoiceStatus::Cancelled->value])
                ->selectRaw('SUM(CASE WHEN status = ? THEN total ELSE 0 END) as total_paid', [InvoiceStatus::Paid->value])
                ->selectRaw('SUM(CASE WHEN status IN (?, ?, ?) THEN total ELSE 0 END) as pending_amount', [
                    InvoiceStatus::Sent->value,
                    InvoiceStatus::Viewed->value,
                    InvoiceStatus::Overdue->value,
                ])
                ->first();

            return [
                'total_tasks' => (int) $taskStats->total,
                'completed_tasks' => (int) $taskStats->completed,
                'in_progress_tasks' => (int) $taskStats->in_progress,
                'pending_tasks' => (int) $taskStats->pending,
                'overdue_tasks' => (int) $taskStats->overdue,
                'member_count' => $project->members()->count(),
                'progress_percentage' => (int) $project->progress_percentage,
                'days_until_due' => $project->days_until_due,
                'is_overdue' => (bool) $project->is_overdue,
                'total_budget' => $project->budget,
                'total_invoiced' => (float) ($invoiceStats->total_invoiced ?? 0),
                'total_paid' => (float) ($invoiceStats->total_paid ?? 0),
                'pending_amount' => (float) ($invoiceStats->pending_amount ?? 0),
                'currency' => $project->currency,
            ];
        });

        return response()->json($stats);
    }

    /**
     * Get project members.
     */
    public function members(Request $request, Team $team, Project $project): AnonymousResourceCollection
    {
        $user = request()->user();
        // Permission check: View Project or View Assigned (if member)
        $hasView = $this->permissionService->hasTeamPermission($user, $team, 'projects.view');
        $hasViewAssigned = $this->permissionService->hasTeamPermission($user, $team, 'projects.view_assigned');

        if (! $hasView && ! $hasViewAssigned) {
            abort(403, 'You do not have permission to view project members.');
        }

        $this->ensureProjectBelongsToTeam($team, $project);

        if (! $hasView && $hasViewAssigned) {
            if (! $project->hasMember($user)) {
                abort(403, 'You do not have permission to view members of this project.');
            }
        }

        // Members are accessed via relationship defined in Project model (belongsToMany User)
        // We can reuse UserResource or creating a simple customized response if needed.
        // For now, let's use a standard User resource or just simple collection.
        // The frontend expects: label, value, avatar, subtitle (role).

        // Let's return JSON directly to match what frontend likely wants,
        // OR return a ResourceCollection.
        // The current QuickAssignModal expects `data` array.

        $members = $project->members()
            ->withPivot(['role', 'joined_at'])
            ->with(['teams' => function ($query) use ($project) {
                // Ensure we only get the pivot for this specific team (in case user is in multiple)
                $query->where('teams.id', $project->team_id);
            }])
            ->orderBy('name')
            ->get();

        // Minimal transformation for UI optimization if desired,
        // but creating a resource class is cleaner.
        // Let's check what `TeamController::members` returns.
        // It likely returns UserResource.

        // Using ProjectMemberResource to include pivot data
        return \App\Http\Resources\ProjectMemberResource::collection($members);
    }

    /**
     * Get project members (Global access).
     */
    public function membersGlobal(Request $request, Project $project): \Illuminate\Http\Resources\Json\AnonymousResourceCollection
    {
        $user = $request->user();

        // Check permissions
        if ($user->id !== $project->team->owner_id && ! $project->hasMember($user)) {
            // Allow if admin or specific permission?
            // mimicking access control from members()
            if (! $user->hasRole('administrator')) {
                abort(403, 'You do not have permission to view members of this project.');
            }
        }

        $members = $project->members()
            ->withPivot(['role', 'joined_at'])
            ->with(['teams' => function ($query) use ($project) {
                // Ensure we only get the pivot for this specific team (in case user is in multiple)
                $query->where('teams.id', $project->team_id);
            }])
            ->orderBy('name')
            ->get();

        return \App\Http\Resources\ProjectMemberResource::collection($members);
    }

    /**
     * Get project files/attachments.
     */
    public function files(Request $request, Team $team, Project $project): JsonResponse
    {
        $user = request()->user();
        $hasView = $this->permissionService->hasTeamPermission($user, $team, 'projects.view');
        $hasViewAssigned = $this->permissionService->hasTeamPermission($user, $team, 'projects.view_assigned');

        if (! $hasView && ! $hasViewAssigned) {
            abort(403, 'You do not have permission to perform this action.');
        }

        $this->ensureProjectBelongsToTeam($team, $project);

        if (! $hasView && $hasViewAssigned) {
            if (! $project->hasMember($user)) {
                abort(403, 'You do not have permission to view this project files.');
            }
        }

        $collection = $request->input('collection', 'attachments');
        $media = $project->getMedia($collection);

        $files = $media->map(function (Media $item) {
            return [
                'id' => $item->id,
                'uuid' => $item->uuid,
                'name' => $item->name,
                'file_name' => $item->file_name,
                'mime_type' => $item->mime_type,
                'size' => $item->size,
                // USE SIGNED URL (60 mins validity)
                'url' => \Illuminate\Support\Facades\URL::temporarySignedRoute(
                    'media.show',
                    now()->addMinutes(60),
                    ['media' => $item->id]
                ),
                'thumb_url' => $item->hasGeneratedConversion('thumb')
                    ? \Illuminate\Support\Facades\URL::temporarySignedRoute(
                        'media.show',
                        now()->addMinutes(60),
                        ['media' => $item->id, 'conversion' => 'thumb']
                    )
                    : null,
                'created_at' => $item->created_at,
            ];
        });

        return response()->json($files);
    }

    // ... (upload/delete file methods stay strictly manage_files) ...

    /**
     * Upload a file to the project.
     */
    public function uploadFile(Request $request, Team $team, Project $project): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'projects.manage_files');
        $this->ensureProjectBelongsToTeam($team, $project);

        $request->validate([
            'file' => 'required|file|max:51200', // 50MB
            'collection' => 'sometimes|in:attachments,gallery',
        ]);

        $collection = $request->input('collection', 'attachments');

        $media = $this->mediaService->attachFromRequest($project, 'file', $collection);

        $this->auditService->log(
            action: AuditAction::FileUploaded,
            category: AuditCategory::ProjectManagement,
            auditable: $project,
            context: [
                'file_name' => $media->file_name,
                'file_size' => $media->size,
                'collection' => $collection,
            ]
        );

        return response()->json([
            'message' => 'File uploaded successfully.',
            'file' => [
                'id' => $media->id,
                'uuid' => $media->uuid,
                'name' => $media->name,
                'file_name' => $media->file_name,
                'mime_type' => $media->mime_type,
                'size' => $media->size,
                // USE SIGNED URL
                'url' => \Illuminate\Support\Facades\URL::temporarySignedRoute(
                    'media.show',
                    now()->addMinutes(60),
                    ['media' => $media->id]
                ),
            ],
        ], 201);
    }

    /**
     * Delete a file from the project.
     */
    public function deleteFile(Request $request, Team $team, Project $project, int $mediaId): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'projects.manage_files');
        $this->ensureProjectBelongsToTeam($team, $project);

        $media = $project->media()->where('id', $mediaId)->first();

        if (! $media) {
            return response()->json([
                'message' => 'File not found.',
            ], 404);
        }

        $fileName = $media->file_name;

        $media->delete();

        $this->auditService->log(
            action: AuditAction::FileDeleted,
            category: AuditCategory::ProjectManagement,
            auditable: $project,
            context: [
                'file_name' => $fileName,
            ]
        );

        return response()->json([
            'message' => 'File deleted successfully.',
        ]);
    }

    /**
     * Calendar events for the project.
     */
    public function calendar(Request $request, Team $team, Project $project): JsonResponse
    {
        $user = request()->user();
        $hasView = $this->permissionService->hasTeamPermission($user, $team, 'projects.view');
        $hasViewAssigned = $this->permissionService->hasTeamPermission($user, $team, 'projects.view_assigned');

        if (! $hasView && ! $hasViewAssigned) {
            abort(403, 'You do not have permission to perform this action.');
        }

        $this->ensureProjectBelongsToTeam($team, $project);

        if (! $hasView && $hasViewAssigned) {
            if (! $project->hasMember($user)) {
                abort(403, 'You do not have permission to view this project.');
            }
        }

        $events = [];

        // Project due date
        if ($project->due_date) {
            $events[] = [
                'id' => 'project-'.$project->public_id,
                'title' => $project->name.' (Due)',
                'start' => $project->due_date->toDateString(),
                'type' => 'project_deadline',
                'color' => $project->is_overdue ? 'error' : 'primary',
            ];
        }

        // Task due dates
        $tasks = $project->tasks()
            ->whereNotNull('due_date')
            ->get(['public_id', 'title', 'due_date', 'status', 'priority']);

        foreach ($tasks as $task) {
            $events[] = [
                'id' => 'task-'.$task->public_id,
                'title' => $task->title,
                'start' => $task->due_date->toDateString(),
                'type' => 'task_deadline',
                'status' => $task->status,
                'priority' => $task->priority,
            ];
        }

        return response()->json($events);
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
     * Resolve client ID from public_id.
     */
    protected function resolveClientId(?string $publicId): ?int
    {
        if (! $publicId) {
            return null;
        }

        $client = \App\Models\Client::where('public_id', $publicId)->first();

        return $client?->id;
    }

    /**
     * Upload a project avatar.
     */
    public function uploadAvatar(Request $request, Team $team, Project $project): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'projects.update');
        $this->ensureProjectBelongsToTeam($team, $project);

        $request->validate(['avatar' => ['required', 'image', 'max:2048']]);

        $this->mediaService->attachFromRequest(
            $project,
            'avatar',
            'avatars',
            Str::random(40).'.webp'
        );

        return response()->json(new ProjectResource($project->fresh()));
    }

    /**
     * Remove the project avatar.
     */
    public function deleteAvatar(Team $team, Project $project): JsonResponse
    {
        $this->authorizeTeamPermission($team, 'projects.update');
        $this->ensureProjectBelongsToTeam($team, $project);

        $project->clearMediaCollection('avatars');

        return response()->json(new ProjectResource($project->fresh()));
    }
}
