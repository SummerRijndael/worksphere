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
        $requestedTeamId = $request->header('X-Team-ID') ?? $request->input('team_id');
        
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
            // For regular users without a specific team, scope to all their teams
            $teamIds = $user->teams()->pluck('teams.id');
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
        $query = Client::query()->with(['team']);
        
        // Check for route parameter 'team' from teams/{team}/clients
        $routeTeam = $request->route('team');
        
        // If route parameter is present (string public_id due to implicit binding typically being disabled or custom in API routes for this structure, or model if bound)
        // Given existing code uses 'public_id', lets check if we got a string or model. 
        // Typically in this codebase, we see manual resolution often.
        // Let's rely on standard resolution logic combined with the input check.

        $requestedTeamId = $routeTeam ?? $request->header('X-Team-ID') ?? $request->input('team_id');

        // Sanitize: Treat literal "undefined" or "null" strings as null
        if ($requestedTeamId === 'undefined' || $requestedTeamId === 'null') {
            $requestedTeamId = null;
        }

        // 1. Resolve Scope
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
                $allowedTeamIds = $user->teams->filter(function ($team) use ($user, $permissionService) {
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

        // Allow admins to specify team_id (public_id)
        if ($request->has('team_id') && $user->hasRole('administrator')) {
            $teamPublicId = $request->input('team_id');
            $targetTeam = \App\Models\Team::where('public_id', $teamPublicId)->first();
            if (! $targetTeam) {
                throw \Illuminate\Validation\ValidationException::withMessages(['team_id' => 'Invalid team.']);
            }
            $teamId = $targetTeam->id;
        } else {
            if (! $team) {
                abort(403, 'Team context required');
            }
            $teamId = $team->id;
        }

        $validated = $request->validate([
            'team_id' => ['sometimes', 'exists:teams,public_id'], // Validate public_id
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('clients')->where(fn ($query) => $query->where('team_id', $teamId))],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:1000'],
            'status' => ['required', 'in:active,inactive'],
        ]);

        // Ensure team_id is set in validated data
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

        // Eager load relationships for the details view
        $client->load([
            'projects' => function ($query) {
                $query->latest()->limit(5); // Recent projects
            },
            'projects.team:id,name,public_id',
            'invoices' => function ($query) {
                $query->latest()->limit(5); // Recent invoices
            },
            'contacts',
            'team:id,public_id,name',
        ]);

        // Append counts and sums
        $client->loadCount(['projects', 'invoices', 'contacts']);
        
        $client->loadSum(['invoices as total_paid' => function($query) {
            $query->paid();
        }], 'total');

        $client->loadSum(['invoices as total_pending' => function($query) {
            $query->pending();
        }], 'total');

        // Add overdue invoices
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

        // Allow updating team_id (public_id) if admin
        if ($request->has('team_id') && $user->hasRole('administrator')) {
            $teamPublicId = $request->input('team_id');
            $targetTeam = \App\Models\Team::where('public_id', $teamPublicId)->first();
            if ($targetTeam) {
                $targetTeamId = $targetTeam->id;
            }
        }

        $validated = $request->validate([
            'team_id' => ['sometimes', 'exists:teams,public_id'],
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255', Rule::unique('clients')->ignore($client->id)->where(fn ($query) => $query->where('team_id', $targetTeamId))],
            'contact_person' => ['nullable', 'string', 'max:255'],
            'phone' => ['nullable', 'string', 'max:50'],
            'address' => ['nullable', 'string', 'max:1000'],
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
