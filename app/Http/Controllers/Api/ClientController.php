<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class ClientController extends Controller
{
    public function __construct()
    {
        $this->middleware('team.permission:clients.create')->only(['store']);
        // 'show', 'update', 'destroy' use manual checks now
    }

    /**
     * Get global client statistics.
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        $query = Client::query();
        $projectQuery = \App\Models\Project::query()->whereNotNull('client_id');

        // Resolve Team Scoping
        $requestedTeamId = $request->has('team_id') 
            ? $request->input('team_id') 
            : $request->header('X-Team-ID');

        if ($requestedTeamId) {
            $team = \App\Models\Team::where('public_id', $requestedTeamId)->first();
            if ($team) {
                // Verify Permission
                $permissionService = app(\App\Services\PermissionService::class);
                if ($user->hasRole('administrator') || $permissionService->isTeamMember($user, $team)) {
                    $query->where('team_id', $team->id);
                    $projectQuery->where('team_id', $team->id);
                } else {
                    abort(403, 'Unauthorized access to this team\'s statistics.');
                }
            } else {
                return response()->json(['total' => 0, 'active' => 0, 'total_projects' => 0]);
            }
        } elseif (! $user->hasRole('administrator')) {
            // For regular users without a specific team, scope to all their teams (member + owned)
            $teamIds = $user->teams()->pluck('teams.id')
                ->merge($user->ownedTeams()->pluck('id'))
                ->unique();
            
            $query->whereIn('team_id', $teamIds);
            $projectQuery->whereIn('team_id', $teamIds);
        }
        // Admins with no team_id see global stats

        $stats = [
            'total' => $query->count(),
            'active' => (clone $query)->where('status', 'active')->count(),
            'total_projects' => $projectQuery->count(),
        ];

        return response()->json($stats);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $isAdmin = $user->hasRole('administrator');
        $query = Client::query()
            ->with([
                'team' => function ($query) {
                    $query->withCount(['members'])->with(['media']);
                },
            ]);

        // Check for route parameter 'team' from teams/{team}/clients
        $routeTeam = $request->route('team');

        // If route parameter is present (string public_id due to implicit binding typically being disabled or custom in API routes for this structure, or model if bound)
        // Given existing code uses 'public_id', lets check if we got a string or model.
        // Typically in this codebase, we see manual resolution often.
        // Let's rely on standard resolution logic combined with the input check.

        $requestedTeamId = $request->has('team_id') 
            ? $request->input('team_id') 
            : ($routeTeam ?? $request->header('X-Team-ID'));

        // Sanitize: Treat literal "undefined" or "null" strings as null
        if ($requestedTeamId === 'undefined' || $requestedTeamId === 'null') {
            $requestedTeamId = null;
        }

        // Resolve Scope
        if ($isAdmin) {
            // Admin Scoping
            if ($requestedTeamId) {
                // Check if it's already a model (Route Model Binding) or string
                if ($requestedTeamId instanceof \App\Models\Team) {
                    $targetTeam = $requestedTeamId;
                } else {
                    $targetTeam = \App\Models\Team::where('public_id', $requestedTeamId)->first();
                }

                if ($targetTeam) {
                    $query->where('team_id', $targetTeam->id);
                }
                // If team not found but user is admin, we just don't filter by team_id
            }
            // If no team_id, returns all clients globally
        } else {
            // Regular User: Strict Scoping
            if ($requestedTeamId) {
                if ($requestedTeamId instanceof \App\Models\Team) {
                    $targetTeam = $requestedTeamId;
                } else {
                    $targetTeam = \App\Models\Team::where('public_id', $requestedTeamId)->first();
                }

                if (! $targetTeam) {
                    abort(404, 'Team not found');
                }

                // Verify Permissions
                $permissionService = app(\App\Services\PermissionService::class);
                // Allow if user is member (basic) or specific permission? existing code used 'clients.view'
                // But index might be called by dropdowns, so 'clients.view' is reasonable.
                if (! $permissionService->hasTeamPermission($user, $targetTeam, 'clients.view')) {
                    abort(403, 'Insufficient permissions for this team.');
                }

                $query->where('team_id', $targetTeam->id);
            } else {
                // No specific team requested. Show clients from ALL teams where user has permission.
                $permissionService = app(\App\Services\PermissionService::class);
                $teams = $user->teams->merge($user->ownedTeams)->unique('id');

                $allowedTeamIds = $teams->filter(function ($team) use ($user, $permissionService) {
                    return $permissionService->hasTeamPermission($user, $team, 'clients.view');
                })->pluck('id');

                if ($allowedTeamIds->isEmpty()) {
                    return response()->json(['data' => [], 'meta' => ['total' => 0]]);
                }

                $query->whereIn('team_id', $allowedTeamIds);
            }
        }

        // 2. Apply Filters
        $query->when($request->search, function ($query, $search) {
            $query->where(function ($q) use ($search) {
                $q->where('name', 'like', "%{$search}%")
                    ->orWhere('email', 'like', "%{$search}%");
            });
        })
            ->when($request->status, function ($query, $status) {
                $query->where('status', $status);
            })
            ->when($request->date_from, function ($query, $date) {
                $query->whereDate('created_at', '>=', $date);
            })
            ->when($request->date_to, function ($query, $date) {
                $query->whereDate('created_at', '<=', $date);
            });

        $clients = $query->latest()->paginate($request->per_page ?? 15);

        return response()->json($clients);
    }

    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $user = $request->user();
        $team = $request->attributes->get('current_team');
        $permissionService = app(\App\Services\PermissionService::class);

        // 1. Resolve Target Team
        $teamId = null;
        if ($request->has('team_id')) {
            $teamPublicId = $request->input('team_id');
            $targetTeam = \App\Models\Team::where('public_id', $teamPublicId)
                ->orWhere('id', $teamPublicId)
                ->first();
            
            if ($targetTeam) {
                // Verify Permission for target team
                if ($user->hasRole('administrator') || $permissionService->hasTeamPermission($user, $targetTeam, 'clients.create')) {
                    $teamId = $targetTeam->id;
                } else {
                    abort(403, 'You do not have permission to create clients for this team.');
                }
            } else {
                throw \Illuminate\Validation\ValidationException::withMessages(['team_id' => 'Invalid team.']);
            }
        } 

        // 2. Fallback to request context if no team_id or team_id was invalid
        if (! $teamId) {
            if (! $team) {
                abort(400, 'Team context or team_id is required.');
            }
            $teamId = $team->id;
        }

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255', 'regex:/^[\pL\s\-\']+$/u', 'not_in:NaN,nan,null,NULL,undefined,UNDEFINED'],
            'email' => ['nullable', 'email:rfc,dns,spoof', 'max:255', Rule::unique('clients')->where(fn ($query) => $query->where('team_id', $teamId))],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:30', 'regex:/^([0-9\s\-\+\(\)]*)$/'],
            'address' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', 'in:active,inactive'],
        ]);

        $validated['team_id'] = $teamId;

        $client = Client::create($validated);

        return response()->json($client, 201);
    }

    /**
     * Display the specified resource.
     */
    public function show(Request $request, Client $client): JsonResponse
    {
        $user = $request->user();

        // Manual Permission Check
        if (! $user->hasRole('administrator')) {
            // Load client's team to check membership/permissions
            $client->load('team');
            $team = $client->team;

            if (! $team) {
                abort(404, 'Client team not found.');
            }

            $permissionService = app(\App\Services\PermissionService::class);
            if (! $permissionService->hasTeamPermission($user, $team, 'clients.view')) {
                abort(403, 'Insufficient permissions to view this client.');
            }
        }

        // Eager load relationships and aggregates for the details view
        $client->load([
            'projects' => fn ($q) => $q->latest()->limit(5),
            'projects.team:id,name,public_id',
            'invoices' => fn ($q) => $q->latest()->limit(5),
            'contacts',
            'team:id,public_id,name',
        ])->loadCount([
            'projects',
            'invoices',
            'contacts',
        ])->loadSum([
            'invoices as total_paid' => fn ($q) => $q->paid(),
            'invoices as total_pending' => fn ($q) => $q->pending(),
        ], 'total');

        // Add overdue invoices - limited to 10 for performance
        $client->setRelation('overdue_invoices', $client->invoices()->overdue()->orderBy('due_date')->limit(10)->get());

        return response()->json($client);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, Client $client): JsonResponse
    {
        $user = $request->user();
        $targetTeamId = $client->team_id;

        // Manual Permission Check
        if (! $user->hasRole('administrator')) {
            $client->load('team');
            $team = $client->team;

            if (! $team) {
                abort(404, 'Client team not found.');
            }

            $permissionService = app(\App\Services\PermissionService::class);
            if (! $permissionService->hasTeamPermission($user, $team, 'clients.update')) {
                abort(403, 'Insufficient permissions to update this client.');
            }
        }

        // Allow updating team_id (public_id) for authorized users
        if ($request->has('team_id')) {
            $teamPublicId = $request->input('team_id');
            $targetTeam = \App\Models\Team::where('public_id', $teamPublicId)
                ->orWhere('id', $teamPublicId)
                ->first();
            
            if ($targetTeam) {
                $permissionService = app(\App\Services\PermissionService::class);
                
                // Verify Permission: Need update permission on BOTH current and target team
                $canUpdateCurrent = $user->hasRole('administrator') || $permissionService->hasTeamPermission($user, $client->team, 'clients.update');
                $canUpdateTarget = $user->hasRole('administrator') || $permissionService->hasTeamPermission($user, $targetTeam, 'clients.update');

                if ($canUpdateCurrent && $canUpdateTarget) {
                    $targetTeamId = $targetTeam->id;
                } else {
                    abort(403, 'Unauthorized to move this client to the specified team.');
                }
            } else {
                throw \Illuminate\Validation\ValidationException::withMessages(['team_id' => 'Invalid target team.']);
            }
        }

        $validated = $request->validate([
            'team_id' => ['sometimes'],
            'name' => ['sometimes', 'string', 'max:255', 'regex:/^[\pL\s\-\']+$/u', 'not_in:NaN,nan,null,NULL,undefined,UNDEFINED'],
            'email' => ['sometimes', 'nullable', 'email:rfc,dns,spoof', 'max:255', Rule::unique('clients')->where(fn ($query) => $query->where('team_id', $client->team_id))->ignore($client->id)],
            'contact_person' => ['sometimes', 'nullable', 'string', 'max:255'],
            'phone' => ['sometimes', 'nullable', 'string', 'max:30', 'regex:/^([0-9\s\-\+\(\)]*)$/'],
            'address' => ['sometimes', 'nullable', 'string', 'max:1000'],
            'status' => ['sometimes', 'in:active,inactive'],
        ]);

        // If updating team_id, we need to replace the public_id in validated array with the resolved DB ID
        if (isset($validated['team_id'])) {
            $validated['team_id'] = $targetTeamId;
        }

        $client->update($validated);

        return response()->json($client);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(Request $request, Client $client): JsonResponse
    {
        $user = $request->user();

        // Manual Permission Check
        if (! $user->hasRole('administrator')) {
            $client->load('team');
            $team = $client->team;

            if (! $team) {
                abort(404, 'Client team not found.');
            }
            $permissionService = app(\App\Services\PermissionService::class);
            if (! $permissionService->hasTeamPermission($user, $team, 'clients.delete')) {
                abort(403, 'Insufficient permissions to delete this client.');
            }
        }

        $client->delete();

        return response()->json(['message' => 'Client deleted successfully']);
    }
}
