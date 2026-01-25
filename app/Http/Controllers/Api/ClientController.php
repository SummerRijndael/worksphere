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
        $this->middleware('team.permission:clients.view')->only(['show']);
        $this->middleware('team.permission:clients.create')->only(['store']);
        $this->middleware('team.permission:clients.update')->only(['update']);
        $this->middleware('team.permission:clients.delete')->only(['destroy']);
    }

    /**
     * Get global client statistics.
     */
    public function stats(Request $request): JsonResponse
    {
        $user = $request->user();
        
        if ($user->hasRole('administrator')) {
            $stats = [
                'total' => Client::count(),
                'active' => Client::where('status', 'active')->count(),
                'total_projects' => \App\Models\Project::whereNotNull('client_id')->count(),
            ];
        } else {
            // For regular users, scope to their teams
            // We can either scope to "current team" if available, or all user's teams.
            // Given the top-level "Clients" navigation might imply "All my clients", 
            // let's check if we should respect 'current_team' or sum all.
            // Usually, stats on a page are contextual.
            $team = $request->attributes->get('current_team');
            
            // If we are in a specific team context, scope to that team.
            // If not, perhaps we scope to all user's teams? 
            // The ClientController seems to rely on 'current_team' middleware for most things.
            
            $query = Client::query();
            $projectQuery = \App\Models\Project::query()->whereNotNull('client_id');

            if ($team) {
                $query->where('team_id', $team->id);
                // Projects need to be scoped to the team as well via client or directly
                $projectQuery->where('team_id', $team->id);
            } else {
                // If no specific team context, scope to all user's teams
                $teamIds = $user->teams()->pluck('teams.id');
                $query->whereIn('team_id', $teamIds);
                $projectQuery->whereIn('team_id', $teamIds);
            }

            $stats = [
                'total' => $query->count(),
                'active' => (clone $query)->where('status', 'active')->count(),
                'total_projects' => $projectQuery->count(),
            ];
        }

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

        // 1. Resolve Scope
        if ($isAdmin) {
            // Admin can see everything, or filter by specific team
            if ($request->filled('team_id')) {
                $teamPublicId = $request->input('team_id');
                // Resolve team by public_id or id
                $targetTeam = \App\Models\Team::where('public_id', $teamPublicId)
                    ->orWhere('id', $teamPublicId) // Handle raw ID if passed internally
                    ->firstOrFail();
                $query->where('team_id', $targetTeam->id);
            }
            // If no team_id, returns all clients globally
        } else {
            // Regular User: Strict Scoping
            // Check if specific team requested via header or param
            $requestedTeamId = $request->header('X-Team-ID') ?? $request->input('team_id');
            
            if ($requestedTeamId) {
                // Resolve requested team
                $targetTeam = \App\Models\Team::where('public_id', $requestedTeamId)
                    ->orWhere('id', $requestedTeamId)
                    ->first();

                if (! $targetTeam) {
                    abort(404, 'Team not found');
                }

                // Verify Membership & Permission
                // We use the PermissionService via app() container for consistency with middleware
                $permissionService = app(\App\Services\PermissionService::class);
                if (! $permissionService->hasTeamPermission($user, $targetTeam, 'clients.view')) {
                    abort(403, 'Insufficient permissions for this team.');
                }

                $query->where('team_id', $targetTeam->id);
            } else {
                // No specific team requested. Show clients from ALL teams where user has permission.
                // Iterate user teams and collect IDs where they have permission.
                // Only active teams? Assuming user->teams returns active membership.
                
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
        $team = $request->attributes->get('current_team');
        $user = $request->user();

        if (! $user->hasRole('administrator')) {
            if ($client->team_id !== $team->id) {
                abort(403, 'Client does not belong to this team');
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
        $team = $request->attributes->get('current_team');
        $targetTeamId = $client->team_id;

        // Strict team check for non-admins
        if (! $user->hasRole('administrator')) {
            if ($client->team_id !== $team->id) {
                abort(403, 'Client does not belong to this team');
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
        $team = $request->attributes->get('current_team');
        $user = $request->user();

        if (! $user->hasRole('administrator')) {
            if ($client->team_id !== $team->id) {
                abort(403, 'Client does not belong to this team');
            }
        }

        $client->delete();

        return response()->json(['message' => 'Client deleted successfully']);
    }
}
