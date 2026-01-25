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
        $this->middleware('team.permission:clients.view')->only(['index', 'show']);
        $this->middleware('team.permission:clients.create')->only(['store']);
        $this->middleware('team.permission:clients.update')->only(['update']);
        $this->middleware('team.permission:clients.delete')->only(['destroy']);
    }

    /**
     * Display a listing of the resource.
     */
    public function index(Request $request): JsonResponse
    {
        $user = $request->user();
        $team = $request->attributes->get('current_team');
        $targetTeamId = $team?->id;

        // Allow admins to filter by team_id (public_id)
        if ($request->filled('team_id') && ($user->hasRole('administrator') || $user->can('clients.manage_any_team'))) {
             $teamPublicId = $request->input('team_id');
             $targetTeam = \App\Models\Team::where('public_id', $teamPublicId)->firstOrFail();
             $targetTeamId = $targetTeam->id;
        } elseif (!$targetTeamId) {
             abort(403, 'Team context required');
        }

        $query = Client::where('team_id', $targetTeamId)
            ->when($request->search, function ($query, $search) {
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
        if ($request->has('team_id') && ($user->hasRole('administrator') || $user->can('clients.manage_any_team'))) {
             $teamPublicId = $request->input('team_id');
             $targetTeam = \App\Models\Team::where('public_id', $teamPublicId)->first();
             if (!$targetTeam) {
                 throw \Illuminate\Validation\ValidationException::withMessages(['team_id' => 'Invalid team.']);
             }
             $teamId = $targetTeam->id;
        } else {
             if (!$team) {
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
        
        if ($client->team_id !== $team->id) {
            abort(403, 'Client does not belong to this team');
        }

        // Eager load relationships for the details view
        $client->load([
            'projects' => function ($query) {
                $query->latest()->limit(5); // Recent projects
            },
            'projects.team:id,name,public_id', // If we want to show team context
            'invoices' => function ($query) {
                $query->latest()->limit(5); // Recent invoices
            }
        ]);

        // Append counts
        $client->loadCount(['projects', 'invoices']);

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
        if (!$user->hasRole('administrator') && !$user->can('clients.manage_any_team')) {
            if ($client->team_id !== $team->id) {
                abort(403, 'Client does not belong to this team');
            }
        }
        
        // Allow updating team_id (public_id) if admin
        if ($request->has('team_id') && ($user->hasRole('administrator') || $user->can('clients.manage_any_team'))) {
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
        
        if ($client->team_id !== $team->id) {
            abort(403, 'Client does not belong to this team');
        }

        $client->delete();

        return response()->json(['message' => 'Client deleted successfully']);
    }
}
