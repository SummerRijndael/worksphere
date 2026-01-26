<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Mail\TeamEventInvitation;
use App\Models\Team;
use App\Models\User;
use App\Notifications\InvitationResponseNotification;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Notifications\DatabaseNotification;
use Illuminate\Support\Facades\Mail;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\Support\MediaStream;

class TeamController extends Controller
{
    public function __construct(
        protected \App\Services\AuditService $auditService,
        protected \App\Services\MediaService $mediaService,
        protected \App\Services\PermissionService $permissionService
    ) {}

    /**
     * Get global or user-scoped team statistics.
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();

        $scope = $request->query('scope', 'all');

        if ($user->hasRole('administrator') && $scope === 'all') {
            $stats = [
                'total' => Team::count(),
                'active' => Team::where('status', 'active')->count(),
                'total_members' => \Illuminate\Support\Facades\DB::table('team_user')->count(),
                'new_this_month' => Team::where('created_at', '>=', now()->startOfMonth())->count(),
            ];
        } else {
            // Scope to user's teams (personal or non-admin)
            $teamIds = $user->teams()->pluck('teams.id');
            
            $stats = [
                'total' => $teamIds->count(),
                'active' => Team::whereIn('id', $teamIds)->where('status', 'active')->count(),
                'total_members' => \Illuminate\Support\Facades\DB::table('team_user')
                    ->whereIn('team_id', $teamIds)
                    ->distinct('user_id')
                    ->count(),
                'new_this_month' => Team::whereIn('id', $teamIds)
                    ->where('created_at', '>=', now()->startOfMonth())
                    ->count(),
            ];
        }

        return response()->json($stats);
    }

    /**
     * Get team financial statistics.
     */
    public function financialStats(Team $team): JsonResponse
    {
        $this->authorize('view', $team);

        // 1. Total Earnings
        $totalEarnings = \App\Models\Invoice::where('team_id', $team->id)
            ->paid()
            ->sum('total');

        // 2. Top 5 Clients by Earnings
        $topClients = \App\Models\Client::where('team_id', $team->id)
            ->withSum(['invoices' => fn($q) => $q->paid()], 'total')
            ->orderByDesc('invoices_sum_total')
            ->take(5)
            ->get()
            ->map(fn($client) => [
                'id' => $client->public_id,
                'name' => $client->name,
                'avatar_url' => $client->avatar_url,
                'total_earnings' => $client->invoices_sum_total ?? 0,
            ]);

        // 3. Top 5 Projects by Earnings
        $topProjects = \App\Models\Project::where('team_id', $team->id)
            ->withSum(['invoices' => fn($q) => $q->paid()], 'total')
            ->orderByDesc('invoices_sum_total')
            ->take(5)
            ->get()
            ->map(fn($project) => [
                'id' => $project->public_id,
                'name' => $project->name,
                'status' => $project->status,
                'total_earnings' => $project->invoices_sum_total ?? 0,
            ]);

        // 4. Monthly Earnings Trend (Last 6 months)
        $sixMonthsAgo = now()->subMonths(5)->startOfMonth();
        $invoices = \App\Models\Invoice::where('team_id', $team->id)
            ->paid()
            ->where('paid_at', '>=', $sixMonthsAgo)
            ->get();

        $monthlyEarnings = collect(range(0, 5))->map(function ($i) use ($sixMonthsAgo, $invoices) {
            $date = $sixMonthsAgo->copy()->addMonths($i);
            $monthKey = $date->format('Y-m');
            $label = $date->format('M Y');
            
            $total = $invoices->filter(function ($invoice) use ($monthKey) {
                return $invoice->paid_at->format('Y-m') === $monthKey;
            })->sum('total');

            return [
                'month' => $label,
                'amount' => $total
            ];
        });

        return response()->json([
            'total_earnings' => $totalEarnings,
            'top_clients' => $topClients,
            'top_projects' => $topProjects,
            'monthly_earnings' => $monthlyEarnings,
        ]);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', Team::class);

        $user = $request->user();
        
        $query = Team::query()->with(['owner', 'members']);

        $scope = $request->query('scope', 'all');

        // Scope: Admin sees all (unless personal scope requested), Regular user sees joined teams
        if (! $user->hasRole('administrator') || $scope === 'personal') {
            $query->whereIn('id', $user->teams()->pluck('teams.id'));
        }

        $query->when($request->search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                      ->orWhere('description', 'like', "%{$search}%");
                });
            })
            ->when($request->date_from, function ($query, $date) {
                $query->whereDate('created_at', '>=', $date);
            })
            ->when($request->date_to, function ($query, $date) {
                $query->whereDate('created_at', '<=', $date);
            });

        $teams = $query->paginate($request->per_page ?? 15);

        return \App\Http\Resources\TeamResource::collection($teams)->response();
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', Team::class);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'owner_id' => ['required', 'exists:users,public_id'],
            'status' => ['required', 'in:active,inactive'],
        ]);

        $owner = \App\Models\User::where('public_id', $validated['owner_id'])->first();

        // Check team creation limit
        $teamActivityService = app(\App\Services\TeamActivityService::class);
        if (! $teamActivityService->canUserCreateTeam($owner)) {
            $maxTeams = $teamActivityService->getMaxTeamsOwned();

            return response()->json([
                'message' => "Team creation limit reached. Maximum {$maxTeams} teams allowed per user.",
                'errors' => ['owner_id' => ["User has reached the maximum of {$maxTeams} owned teams."]],
            ], 403);
        }

        $team = Team::create([
            'name' => $validated['name'],
            'description' => $validated['description'],
            'owner_id' => $owner->id,
            'status' => $validated['status'],
            'last_activity_at' => now(),
        ]);

        // Add owner as a member with 'team_lead' role
        $team->members()->attach($owner->id, ['role' => \App\Enums\TeamRole::TeamLead->value]);

        $team->load('owner');

        return response()->json(new \App\Http\Resources\TeamResource($team), 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Team $team): JsonResponse
    {
        $this->authorize('view', $team);

        $team->load(['owner', 'members']);

        return response()->json(new \App\Http\Resources\TeamResource($team));
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Team $team): JsonResponse
    {
        $this->authorize('update', $team);

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'description' => ['nullable', 'string', 'max:1000'],
            'owner_id' => ['sometimes', 'exists:users,public_id'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);

        // Update logic: Handle owner change if needed
        if (isset($validated['name'])) {
            $team->name = $validated['name'];
        }
        if (isset($validated['description'])) {
            $team->description = $validated['description'];
        }
        if (isset($validated['status'])) {
            $team->status = $validated['status'];
        }

        if (isset($validated['owner_id'])) {
            $newOwner = \App\Models\User::where('public_id', $validated['owner_id'])->first();
            if ($newOwner && $newOwner->id !== $team->owner_id) {
                $team->owner_id = $newOwner->id;

                // Ensure new owner is member
                if (! $team->members()->where('user_id', $newOwner->id)->exists()) {
                    $team->members()->attach($newOwner->id, ['role' => \App\Enums\TeamRole::TeamLead->value]);
                } else {
                    $team->members()->updateExistingPivot($newOwner->id, ['role' => \App\Enums\TeamRole::TeamLead->value]);
                }
            }
        }

        $team->save();

        return response()->json(new \App\Http\Resources\TeamResource($team));
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Team $team): JsonResponse
    {
        $this->authorize('delete', $team);

        $team->delete();

        return response()->json(['message' => 'Team deleted successfully']);
    }

    /**
     * Get team members.
     */
    public function members(Request $request, Team $team): JsonResponse
    {
        $this->authorize('view', $team);

        $query = $team->members()
            ->when($request->search, function ($query, $search) {
                $query->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            })
            ->when($request->role, function ($query, $role) {
                $query->wherePivot('role', $role);
            });

        $members = $query->paginate($request->per_page ?? 10);

        return response()->json(\App\Http\Resources\TeamMemberResource::collection($members)->response()->getData(true));
    }

    /**
     * Get team participants (members + project clients) for event invitations.
     */
    public function participants(Request $request, Team $team): JsonResponse
    {
        $this->authorize('view', $team);

        $search = $request->search;
        $perPage = $request->per_page ?? 30;

        // Get team members
        $membersQuery = $team->members()
            ->select('users.id', 'users.public_id', 'users.name', 'users.email')
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('users.name', 'like', "%{$search}%")
                        ->orWhere('users.email', 'like', "%{$search}%");
                });
            });

        // Get clients from team projects
        $clientIds = $team->projects()
            ->whereNotNull('client_id')
            ->pluck('client_id')
            ->unique();

        $clientsQuery = \App\Models\Client::whereIn('id', $clientIds)
            ->select('id', 'public_id', 'name', 'email')
            ->when($search, function ($query, $search) {
                $query->where(function ($q) use ($search) {
                    $q->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                });
            });

        // Combine and format results
        $members = $membersQuery->get()->map(fn ($m) => [
            'id' => $m->public_id,
            'name' => $m->name,
            'email' => $m->email,
            'avatar_url' => $m->avatar_url ?? null,
            'type' => 'member',
        ]);

        $clients = $clientsQuery->get()->map(fn ($c) => [
            'id' => $c->public_id,
            'name' => $c->name,
            'email' => $c->email,
            'avatar_url' => null,
            'type' => 'client',
        ]);

        // Merge and filter by search, then paginate manually
        $all = $members->merge($clients)->unique('id');

        if ($search) {
            $all = $all->filter(function ($item) use ($search) {
                return str_contains(strtolower($item['name']), strtolower($search))
                    || str_contains(strtolower($item['email'] ?? ''), strtolower($search));
            });
        }

        $total = $all->count();
        $page = $request->page ?? 1;
        $items = $all->slice(($page - 1) * $perPage, $perPage)->values();

        return response()->json([
            'data' => $items,
            'meta' => [
                'current_page' => (int) $page,
                'last_page' => (int) ceil($total / $perPage),
                'per_page' => $perPage,
                'total' => $total,
            ],
        ]);
    }

    /**
     * Invite a member to the team (Add existing user).
     */
    /**
     * Invite a member to the team.
     */
    public function invite(Request $request, Team $team): JsonResponse
    {
        $this->authorize('invite', $team);

        $validated = $request->validate([
            'email' => ['required', 'email', 'exists:users,email'],
            'role' => ['required', 'in:subject_matter_expert,quality_assessor,operator'],
        ]);

        $user = User::where('email', $validated['email'])->first();

        if ($team->hasMember($user)) {
            return response()->json(['message' => 'User is already a member of this team.'], 409);
        }

        // Check if already invited
        $existingInvite = $user->notifications()
            ->where('type', \App\Notifications\TeamInvitationNotification::class)
            ->where('data->team_id', $team->id)
            ->whereNull('read_at')
            ->exists();

        if ($existingInvite) {
            return response()->json(['message' => 'User has already been invited.'], 409);
        }

        $user->notify(new \App\Notifications\TeamInvitationNotification($team, auth()->user(), $validated['role']));

        $this->auditService->log(
            action: \App\Enums\AuditAction::TeamInvitationSent,
            category: \App\Enums\AuditCategory::TeamManagement,
            auditable: $team,
            context: [
                'email' => $validated['email'],
                'role' => $validated['role'],
                'target_user_id' => $user->id,
            ]
        );

        return response()->json(['message' => 'Invitation sent successfully.']);
    }

    /**
     * Accept a team invitation.
     */
    public function acceptInvitation(string $notificationId): JsonResponse
    {
        $notification = auth()->user()->notifications()->findOrFail($notificationId);

        if ($notification->type !== \App\Notifications\TeamInvitationNotification::class) {
            return response()->json(['message' => 'Invalid notification type.'], 400);
        }

        $teamId = $notification->data['team_id'];
        $role = $notification->data['role'];
        $team = Team::find($teamId);

        if (! $team) {
            return response()->json(['message' => 'Team no longer exists.'], 404);
        }

        if ($team->hasMember(auth()->user())) {
            $notification->markAsRead();

            return response()->json(['message' => 'You are already a member of this team.']);
        }

        $team->addMember(auth()->user(), $role);
        $notification->markAsRead(); // Keep it to show history? Or delete? User choice. Let's keep as read.

        // Notify Inviter
        if (isset($notification->data['inviter_id'])) {
            $inviter = User::find($notification->data['inviter_id']);
            if ($inviter) {
                $inviter->notify(new InvitationResponseNotification(auth()->user(), $team, 'accepted'));
            }
        }

        $this->auditService->log(
            action: \App\Enums\AuditAction::TeamInvitationAccepted,
            category: \App\Enums\AuditCategory::TeamManagement,
            auditable: $team,
            context: [
                'role' => $role,
            ]
        );

        return response()->json(['message' => 'Invitation accepted. You joined the team.']);
    }

    /**
     * Decline a team invitation.
     */
    public function declineInvitation(string $notificationId): JsonResponse
    {
        $notification = auth()->user()->notifications()->findOrFail($notificationId);

        if ($notification->type !== \App\Notifications\TeamInvitationNotification::class) {
            return response()->json(['message' => 'Invalid notification type.'], 400);
        }

        $teamId = $notification->data['team_id'] ?? null;
        $team = $teamId ? Team::find($teamId) : null;

        $notification->markAsRead();
        $notification->delete();

        // Notify Inviter
        if ($team && isset($notification->data['inviter_id'])) {
            $inviter = User::find($notification->data['inviter_id']);
            if ($inviter) {
                $inviter->notify(new InvitationResponseNotification(auth()->user(), $team, 'declined'));
            }
        }

        if ($team) {
            $this->auditService->log(
                action: \App\Enums\AuditAction::TeamInvitationDeclined,
                category: \App\Enums\AuditCategory::TeamManagement,
                auditable: $team
            );
        }

        return response()->json(['message' => 'Invitation declined.']);
    }

    /**
     * Remove a member from the team.
     */
    public function removeMember(Team $team, User $user): JsonResponse
    {
        $this->authorize('update', $team);

        if ($team->owner_id === $user->id) {
            return response()->json(['message' => 'Cannot remove the team owner.'], 403);
        }

        $team->removeMember($user);

        $this->auditService->log(
            action: \App\Enums\AuditAction::TeamMemberRemoved,
            category: \App\Enums\AuditCategory::TeamManagement,
            auditable: $team,
            context: [
                'member_id' => $user->id,
                'member_email' => $user->email,
                'member_name' => $user->name,
            ]
        );

        return response()->json(['message' => 'Member removed successfully.']);
    }

    /**
     * Update a member's role in the team.
     */
    public function updateMemberRole(\App\Http\Requests\UpdateTeamMemberRoleRequest $request, Team $team, User $user)
    {
        /** @var \App\Models\User $currentUser */
        $currentUser = $request->user();

        // Only team owner or admins can update roles.
        // We actally use Jetstream-like logic here usually, but let's stick to permissions.
        // Assuming 'addUser' implies management or we check ownership.
        if ($currentUser->id !== $team->owner_id && ! $this->permissionService->hasTeamPermission($currentUser, $team, 'team_roles.assign')) {
            abort(403, 'You do not have permission to update member roles.');
        }

        // Specific protection: Only OWNER can change roles to/from admin?
        // Let's allow admins to promote members to admin, but NOT demote/touch the Owner.

        // Cannot change role of the owner
        if ($user->id === $team->owner_id) {
            abort(403, 'Cannot change the role of the team owner.');
        }

        $team->members()->updateExistingPivot($user->id, [
            'role' => $request->role,
        ]);

        $this->auditService->log(
            action: \App\Enums\AuditAction::TeamRoleChanged,
            category: \App\Enums\AuditCategory::TeamManagement,
            auditable: $team,
            context: [
                'member_id' => $user->id,
                'member_name' => $user->name,
                'new_role' => $request->role,
            ]
        );

        return response()->json(['message' => 'Member role updated successfully']);
    }

    /**
     * Get team files.
     */
    public function files(Team $team): JsonResponse
    {
        $this->authorize('view', $team);

        $media = $team->getMedia('team_files')->map(function ($file) {
            return [
                'id' => $file->id, // Spatie Media Library uses ID
                'uuid' => $file->uuid,
                'name' => $file->name,
                'file_name' => $file->file_name,
                'mime_type' => $file->mime_type,
                'size' => $file->size,
                // USE SIGNED URL
                'url' => \Illuminate\Support\Facades\URL::temporarySignedRoute(
                    'media.show',
                    now()->addMinutes(60),
                    ['media' => $file->id]
                ),
                'created_at' => $file->created_at,
            ];
        });

        return response()->json($media);
    }

    /**
     * Upload a file to the team.
     */
    public function uploadFile(Request $request, Team $team): JsonResponse
    {
        $this->authorize('update', $team);

        $request->validate([
            'file' => ['required', 'file', 'max:10240'], // 10MB max upload size per file
        ]);

        // Check Storage Limit
        $maxStorageMb = app(\App\Services\AppSettingsService::class)->get('storage.max_team_storage', 1024);
        $maxStorageBytes = $maxStorageMb * 1024 * 1024;
        $currentUsage = $team->media()->sum('size');
        $newFileSize = $request->file('file')->getSize();

        if (($currentUsage + $newFileSize) > $maxStorageBytes) {
            return response()->json([
                'message' => 'Storage limit exceeded. Please contact support or delete some files.',
                'errors' => ['file' => ['Team storage limit exceeded.']],
            ], 422);
        }

        $media = $this->mediaService->attachFromRequest($team, 'file', 'team_files');

        return response()->json([
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
            'created_at' => $media->created_at,
        ]);
    }

    /**
     * Bulk download files as zip.
     */
    public function bulkDownload(Request $request, Team $team)
    {
        $this->authorize('view', $team);

        $request->validate([
            'media_ids' => ['required', 'array', 'max:10'], // Max 10 files
            'media_ids.*' => ['exists:media,id'],
        ]);

        $media = $team->media()->whereIn('id', $request->media_ids)->get();

        if ($media->isEmpty()) {
            return response()->json(['message' => 'No files found.'], 404);
        }

        // Return zip stream
        return MediaStream::create($team->slug.'-files.zip')->addMedia($media);
    }

    /**
     * Bulk delete files.
     */
    public function bulkDelete(Request $request, Team $team): JsonResponse
    {
        $this->authorize('update', $team);

        $request->validate([
            'media_ids' => ['required', 'array', 'max:10'],
            'media_ids.*' => ['exists:media,id'],
        ]);

        $team->media()->whereIn('id', $request->media_ids)->delete();

        return response()->json(['message' => 'Files deleted successfully.']);
    }

    /**
     * Delete a file from the team.
     */
    public function deleteFile(Team $team, string $mediaId): JsonResponse
    {
        $this->authorize('update', $team);

        $media = $team->media()->where('id', $mediaId)->firstOrFail();
        $media->delete();

        return response()->json(['message' => 'File deleted successfully.']);
    }

    /**
     * Get pending invites for the team.
     */
    public function pendingInvites(Team $team): JsonResponse
    {
        $this->authorize('invite', $team);

        $invites = DatabaseNotification::with('notifiable')
            ->where('type', \App\Notifications\TeamInvitationNotification::class)
            ->where('data->team_id', $team->id)
            ->whereNull('read_at')
            ->get()
            ->map(function ($notification) {
                $user = $notification->notifiable;

                return [
                    'id' => $notification->id,
                    'email' => $user ? $user->email : 'Unknown',
                    'name' => $user ? $user->name : 'Unknown',
                    'avatar_url' => $user ? $user->avatar_url : null,
                    'role' => $notification->data['role'] ?? 'operator',
                    'sent_at' => $notification->created_at,
                    'inviter_name' => $notification->data['inviter_name'] ?? 'Unknown',
                ];
            });

        return response()->json($invites);
    }

    /**
     * Cancel a pending invitation.
     */
    public function cancelInvite(Team $team, string $notificationId): JsonResponse
    {
        $this->authorize('invite', $team);

        $notification = DatabaseNotification::findOrFail($notificationId);

        // Verify this notification belongs to this team
        if (($notification->data['team_id'] ?? null) != $team->id) {
            return response()->json(['message' => 'Invalid invitation for this team.'], 403);
        }

        $this->auditService->log(
            action: \App\Enums\AuditAction::TeamInvitationCancelled,
            category: \App\Enums\AuditCategory::TeamManagement,
            auditable: $team,
            context: [
                'email' => $notification->data['email'] ?? null, // Notification data usually has this? Need to check TeamInvitationNotification structure. The controller pendingInvites uses $notification->notifiable->email.
                'target_user_id' => $notification->notifiable_id,
            ]
        );

        $notification->delete();

        return response()->json(['message' => 'Invitation cancelled.']);
    }

    /**
     * Get team activity / audit trail.
     */
    public function activity(Request $request, Team $team): JsonResponse
    {
        $this->authorize('view', $team);

        $perPage = $request->input('per_page', 20);

        // Get audit logs related to this team or its entities
        $logs = \App\Models\AuditLog::query()
            ->with('user:id,public_id,name')
            ->where(function ($query) use ($team) {
                // Team itself
                $query->where(function ($q) use ($team) {
                    $q->where('auditable_type', 'App\\Models\\Team')
                        ->where('auditable_id', $team->id);
                })
                // Team projects
                    ->orWhere(function ($q) use ($team) {
                        $q->where('auditable_type', 'App\\Models\\Project')
                            ->whereIn('auditable_id', $team->projects()->pluck('id'));
                    })
                // Team tasks (via projects)
                    ->orWhere(function ($q) use ($team) {
                        $projectIds = $team->projects()->pluck('id');
                        $q->where('auditable_type', 'App\\Models\\Task')
                            ->whereIn('auditable_id', \App\Models\Task::whereIn('project_id', $projectIds)->pluck('id'));
                    });
            })
            ->orderBy('created_at', 'desc')
            ->paginate($perPage);

        // Transform for frontend
        $logs->getCollection()->transform(function ($log) {
            return [
                'id' => $log->id,
                'user' => $log->user ? [
                    'name' => $log->user->name,
                    'avatar_url' => $log->user->avatar_url,
                    'initials' => $log->user->initials,
                ] : null,
                'action' => $log->action->value ?? $log->action,
                'action_label' => $log->action->label() ?? ucwords(str_replace('_', ' ', $log->action)),
                'target' => $log->metadata['name'] ?? $log->metadata['title'] ?? class_basename($log->auditable_type),
                'target_type' => strtolower(class_basename($log->auditable_type)),
                'time' => $log->created_at->diffForHumans(),
                'created_at' => $log->created_at->toIso8601String(),
            ];
        });

        return response()->json($logs);
    }

    /**
     * Get team calendar events.
     */
    public function calendar(Request $request, Team $team): JsonResponse
    {
        $this->authorize('view', $team);

        $start = $request->input('start');
        $end = $request->input('end');

        // 1. Project Deadlines
        $projects = $team->projects()
            ->whereNotNull('due_date')
            ->when($start && $end, function ($q) use ($start, $end) {
                $q->whereBetween('due_date', [$start, $end]);
            })
            ->get()
            ->map(function ($project) {
                return [
                    'id' => 'project-'.$project->id,
                    'title' => 'Deadline: '.$project->name,
                    'start' => $project->due_date->format('Y-m-d'),
                    'allDay' => true,
                    'backgroundColor' => '#3B82F6', // Blue
                    'borderColor' => '#3B82F6',
                    'extendedProps' => [
                        'type' => 'project',
                        'project_id' => $project->public_id,
                        'status' => $project->status,
                    ],
                ];
            });

        // 2. Task Deadlines
        $tasks = \App\Models\Task::whereIn('project_id', $team->projects()->pluck('id'))
            ->whereNotNull('due_date')
            ->when($start && $end, function ($q) use ($start, $end) {
                $q->whereBetween('due_date', [$start, $end]);
            })
            ->get()
            ->map(function ($task) {
                return [
                    'id' => 'task-'.$task->id,
                    'title' => 'Due: '.$task->title,
                    'start' => $task->due_date->format('Y-m-d'),
                    'allDay' => true,
                    'backgroundColor' => '#10B981', // Green
                    'borderColor' => '#10B981',
                    'extendedProps' => [
                        'type' => 'task',
                        'task_id' => $task->public_id,
                        'status' => $task->status,
                        // 'assignee' => $task->assignee ? $task->assignee->name : null,
                    ],
                ];
            });

        // 3. Custom Team Events
        $events = $team->events()
            ->with('creator') // Load creator
            ->when($start && $end, function ($q) use ($start, $end) {
                $q->between($start, $end);
            })
            ->get()
            ->map(function ($event) use ($team) {
                // Determine if user can edit: owner, event creator, or team admin
                $canEdit = auth()->id() === $event->user_id ||
                           auth()->id() === $team->owner_id ||
                           $team->members()->where('user_id', auth()->id())->whereIn('role', [\App\Enums\TeamRole::TeamLead->value, \App\Enums\TeamRole::SubjectMatterExpert->value])->exists();

                return [
                    'id' => $event->public_id,
                    'title' => $event->title,
                    'start' => $event->start_time->toIso8601String(),
                    'end' => $event->end_time ? $event->end_time->toIso8601String() : null,
                    'allDay' => $event->is_all_day,
                    'backgroundColor' => $event->color,
                    'borderColor' => $event->color,
                    'extendedProps' => [
                        'type' => 'event',
                        'description' => $event->description,
                        'location' => $event->location,
                        'creator' => $event->creator->name,
                        'can_edit' => $canEdit,
                        'participants' => $event->participants->map(fn ($u) => $u->id),
                        'participants_details' => $event->participants->map(fn ($u) => [
                            'id' => $u->id,
                            'name' => $u->name,
                            'avatar_url' => $u->avatar_url,
                            'status' => $u->pivot->status,
                        ]),
                    ],
                ];
            });

        return response()->json([
            'data' => $projects->concat($tasks)->concat($events),
        ]);
    }

    /**
     * Store a newly created team event.
     */
    public function storeEvent(Request $request, Team $team): JsonResponse
    {
        // Permission check: Owner or Admin
        if (auth()->id() !== $team->owner_id &&
            ! $team->members()->where('user_id', auth()->id())->whereIn('role', [\App\Enums\TeamRole::TeamLead->value, \App\Enums\TeamRole::SubjectMatterExpert->value])->exists()) {
            abort(403, 'Only team admins can create events.');
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'start_time' => ['required', 'date'],
            'end_time' => ['nullable', 'date', 'after_or_equal:start_time'],
            'is_all_day' => ['boolean'],
            'color' => ['required', 'string', 'max:7'],
            'location' => ['nullable', 'string', 'max:255'],
            'reminder_minutes_before' => ['nullable', 'integer', 'min:0'],
            'participants' => ['nullable', 'array'],
            'participants.*' => ['exists:users,public_id'],
        ]);

        $event = $team->events()->create([
            'user_id' => auth()->id(),
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'] ?? null,
            'is_all_day' => $validated['is_all_day'] ?? false,
            'color' => $validated['color'],
            'location' => $validated['location'] ?? null,
            'reminder_minutes_before' => $validated['reminder_minutes_before'] ?? null,
        ]);

        if (! empty($validated['participants'])) {
            $userIds = \App\Models\User::whereIn('public_id', $validated['participants'])->pluck('id');
            $event->participants()->sync($userIds);

            // Send invitations
            foreach ($event->participants as $participant) {
                if ($participant->id !== auth()->id()) {
                    Mail::to($participant)->queue(new TeamEventInvitation($event));
                }
            }
        }

        return response()->json($event->load(['creator', 'participants']), 201);
    }

    /**
     * Update the specified team event.
     */
    public function updateEvent(Request $request, Team $team, \App\Models\TeamEvent $event): JsonResponse
    {
        // Verify event belongs to team
        if ($event->team_id !== $team->id) {
            abort(404);
        }

        // Permission check: Owner, Admin, or Creator
        if (auth()->id() !== $team->owner_id &&
            auth()->id() !== $event->user_id &&
            ! $team->members()->where('user_id', auth()->id())->wherePivot('role', 'admin')->exists()) {
            abort(403, 'Unauthorized action.');
        }

        $validated = $request->validate([
            'title' => ['required', 'string', 'max:255'],
            'description' => ['nullable', 'string'],
            'start_time' => ['required', 'date'],
            'end_time' => ['nullable', 'date', 'after_or_equal:start_time'],
            'is_all_day' => ['boolean'],
            'color' => ['required', 'string', 'max:7'],
            'location' => ['nullable', 'string', 'max:255'],
            'reminder_minutes_before' => ['nullable', 'integer', 'min:0'],
            'participants' => ['nullable', 'array'],
            'participants.*' => ['exists:users,public_id'],
        ]);

        $event->update([
            'title' => $validated['title'],
            'description' => $validated['description'] ?? null,
            'start_time' => $validated['start_time'],
            'end_time' => $validated['end_time'] ?? null,
            'is_all_day' => $validated['is_all_day'] ?? false,
            'color' => $validated['color'],
            'location' => $validated['location'] ?? null,
            'reminder_minutes_before' => $validated['reminder_minutes_before'] ?? null,
        ]);

        if (isset($validated['participants'])) {
            $userIds = \App\Models\User::whereIn('public_id', $validated['participants'])->pluck('id');
            $changes = $event->participants()->sync($userIds);

            // Send invitations to new participants
            if (! empty($changes['attached'])) {
                foreach ($changes['attached'] as $userId) {
                    $user = User::find($userId);
                    if ($user && $user->id !== auth()->id()) {
                        Mail::to($user)->queue(new TeamEventInvitation($event));
                    }
                }
            }

            // Send value updates to existing (invitation text is generic enough "You have been invited" vs "Event Updated" - user said "invitations upon creation/update". I will reuse invitation mail).
            // Optimization: If significant fields changed, maybe send to everyone?
            // For now, sticking to logic: New participants get invite. Data syncs.
        }

        return response()->json($event->load(['creator', 'participants']));
    }

    /**
     * Remove the specified team event.
     */
    public function destroyEvent(Team $team, \App\Models\TeamEvent $event): JsonResponse
    {
        // Verify event belongs to team
        if ($event->team_id !== $team->id) {
            abort(404);
        }

        // Permission check: Owner, Admin, or Creator
        if (auth()->id() !== $team->owner_id &&
            auth()->id() !== $event->user_id &&
            ! $team->members()->where('user_id', auth()->id())->wherePivot('role', 'admin')->exists()) {
            abort(403, 'Unauthorized action.');
        }

        $event->delete();

        return response()->json(['message' => 'Event deleted successfully']);
    }

    /**
     * Send email invites to all participants of a team event.
     */
    public function inviteEvent(Team $team, \App\Models\TeamEvent $event): JsonResponse
    {
        // Verify event belongs to team
        if ($event->team_id !== $team->id) {
            abort(404);
        }

        // Permission check: Owner, Admin, or Creator
        if (auth()->id() !== $team->owner_id &&
            auth()->id() !== $event->user_id &&
            ! $team->members()->where('user_id', auth()->id())->wherePivot('role', 'admin')->exists()) {
            abort(403, 'Unauthorized action.');
        }

        $event->load(['creator', 'participants', 'team']);

        if ($event->participants->isEmpty()) {
            return response()->json(['message' => 'No participants to invite.'], 422);
        }

        foreach ($event->participants as $participant) {
            if ($participant->id !== auth()->id()) {
                Mail::to($participant)->queue(new TeamEventInvitation($event));
            }
        }

        return response()->json([
            'message' => 'Invites sent to '.$event->participants->count().' participant(s).',
        ]);
    }

    /**
     * Download ICS file for a team event.
     */
    public function downloadEventIcs(Team $team, \App\Models\TeamEvent $event)
    {
        // Verify event belongs to team
        if ($event->team_id !== $team->id) {
            abort(404);
        }

        // User must be a team member
        if (! $team->hasMember(auth()->user())) {
            abort(403, 'Unauthorized access.');
        }

        $event->load(['creator', 'participants', 'team']);

        $exportService = app(\App\Services\CalendarExportService::class);
        $icsContent = $exportService->generateIcs($event);

        $filename = \Illuminate\Support\Str::slug($event->title).'.ics';

        return response($icsContent, 200, [
            'Content-Type' => 'text/calendar; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * Export team events as ICS file.
     */
    public function exportEvents(Request $request, Team $team)
    {
        $this->authorize('view', $team);

        $request->validate([
            'start' => 'required|date',
            'end' => 'required|date|after_or_equal:start',
        ]);

        $events = $team->events()
            ->with(['creator', 'participants'])
            ->between($request->start, $request->end)
            ->get();

        if ($events->isEmpty()) {
            return response()->json(['message' => 'No events to export in this date range.'], 422);
        }

        $exportService = app(\App\Services\CalendarExportService::class);
        $icsContent = $exportService->generateMultipleIcs($events);

        $filename = \Illuminate\Support\Str::slug($team->name).'-events-'.now()->format('Y-m-d').'.ics';

        return response($icsContent, 200, [
            'Content-Type' => 'text/calendar; charset=UTF-8',
            'Content-Disposition' => 'attachment; filename="'.$filename.'"',
        ]);
    }

    /**
     * Upload a team avatar.
     */
    public function uploadAvatar(Request $request, Team $team): JsonResponse
    {
        $this->authorize('update', $team);

        $request->validate(['avatar' => ['required', 'image', 'max:2048']]);

        $this->mediaService->attachFromRequest(
            $team,
            'avatar',
            'avatars',
            Str::random(40).'.webp',
            null,
            'public' // Force public disk
        );

        return response()->json($team->fresh()->load('owner'));
    }

    /**
     * Remove the team avatar.
     */
    public function deleteAvatar(Team $team): JsonResponse
    {
        $this->authorize('update', $team);

        $team->clearMediaCollection('avatars');

        return response()->json($team->fresh()->load('owner'));
    }

    /**
     * Keep team active - reset dormancy status.
     */
    public function keepActive(Team $team): JsonResponse
    {
        // Only owner can keep team active
        if (auth()->id() !== $team->owner_id) {
            abort(403, 'Only the team owner can perform this action.');
        }

        $team->keepActive();

        $this->auditService->log(
            action: \App\Enums\AuditAction::Updated,
            category: \App\Enums\AuditCategory::TeamManagement,
            auditable: $team,
            context: ['action' => 'keep_active', 'previous_status' => $team->getOriginal('lifecycle_status')]
        );

        return response()->json([
            'message' => 'Team has been marked as active.',
            'team' => $team->fresh(),
        ]);
    }

    /**
     * Owner self-delete team.
     */
    public function selfDelete(Team $team): JsonResponse
    {
        // Only owner can self-delete
        if (auth()->id() !== $team->owner_id) {
            abort(403, 'Only the team owner can delete their team.');
        }

        $this->auditService->log(
            action: \App\Enums\AuditAction::Deleted,
            category: \App\Enums\AuditCategory::TeamManagement,
            auditable: $team,
            context: ['reason' => 'owner_self_delete']
        );

        $team->delete();

        return response()->json(['message' => 'Team deleted successfully.']);
    }

    /**
     * Get ownership summary for current user.
     */
    public function ownershipSummary(): JsonResponse
    {
        $user = auth()->user();
        $teamActivityService = app(\App\Services\TeamActivityService::class);

        $ownedTeams = Team::where('owner_id', $user->id)->get();
        $memberTeams = Team::forUser($user)->whereNot('owner_id', $user->id)->get();

        return response()->json([
            'owned_count' => $ownedTeams->count(),
            'member_count' => $memberTeams->count(),
            'max_owned' => $teamActivityService->getMaxTeamsOwned(),
            'max_joined' => $teamActivityService->getMaxTeamsJoined(),
            'remaining_slots' => $teamActivityService->getRemainingTeamSlots($user),
            'owned_teams' => $ownedTeams->map(fn ($t) => [
                'id' => $t->public_id,
                'name' => $t->name,
                'lifecycle_status' => $t->lifecycle_status,
                'last_activity_at' => $t->last_activity_at?->toIso8601String(),
            ]),
            'member_teams' => $memberTeams->map(fn ($t) => [
                'id' => $t->public_id,
                'name' => $t->name,
            ]),
        ]);
    }
}
