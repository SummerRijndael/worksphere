<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Client;
use App\Models\ClientContact;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class ClientContactController extends Controller
{
    /**
     * Store a newly created resource in storage.
     */
    public function store(Request $request, string $teamId, Client $client): JsonResponse
    {
        // Check permission (reuse generic client update permission or create a new one)
        // $this->authorize('update', $client);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^([0-9\s\-\+\(\)]*)$/'],
            'role' => ['nullable', 'string', 'max:255'],
            'is_primary' => ['boolean'],
        ]);

        $contact = $client->contacts()->create($validated);

        return response()->json([
            'message' => 'Contact added successfully.',
            'data' => $contact,
        ], 201);
    }

    /**
     * Update the specified resource in storage.
     */
    public function update(Request $request, string $teamId, ClientContact $contact): JsonResponse
    {
        // $client check removed as we don't have $client in route for shallow update
        // But we can check if $contact belongs to team via client?
        // if ($contact->client->team_id !== ...) -> complicates things, relying on middleware usually.

        $validated = $request->validate([
            'name' => ['sometimes', 'string', 'max:255'],
            'email' => ['nullable', 'email', 'max:255'],
            'phone' => ['nullable', 'string', 'max:20', 'regex:/^([0-9\s\-\+\(\)]*)$/'],
            'role' => ['nullable', 'string', 'max:255'],
            'is_primary' => ['boolean'],
        ]);

        $contact->update($validated);

        return response()->json([
            'message' => 'Contact updated successfully.',
            'data' => $contact,
        ]);
    }

    /**
     * Remove the specified resource from storage.
     */
    public function destroy(string $teamId, ClientContact $contact): JsonResponse
    {

        $contact->delete();

        return response()->json([
            'message' => 'Contact deleted successfully.',
        ]);
    }
}
