<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\UserResource;
use App\Models\User;
use Illuminate\Http\Request;

class PublicProfileController extends Controller
{
    /**
     * Display the specified resource.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function show(string $slug)
    {
        $user = User::where('username', $slug)
            ->orWhere('public_id', $slug)
            ->firstOrFail();

        if (! $user->is_public) {
            // Allow if requesting own profile, otherwise 404 to hide existence
            if (auth()->id() !== $user->id) {
                abort(404);
            }
        }

        return new UserResource($user);
    }

    /**
     * Update the public visibility status.
     *
     * @return \Illuminate\Http\JsonResponse
     */
    public function updateStatus(Request $request)
    {
        $request->validate([
            'is_public' => 'required|boolean',
        ]);

        $user = $request->user();
        $user->update(['is_public' => $request->is_public]);

        return response()->json([
            'message' => 'Profile visibility updated.',
            'is_public' => $user->is_public,
        ]);
    }
}
