<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;

class UserProfileController extends Controller
{
    /**
     * Display the specified user profile.
     */
    public function show(Request $request, User $user)
    {
        $this->authorize('viewProfile', $user);

        return response()->json([
            'data' => [
                'public_id' => $user->public_id,
                'name' => $user->name,
                'username' => $user->username,
                'avatar_url' => $user->avatar_url,
                'bio' => $user->bio,
                'job_title' => $user->title,
                'location' => $user->location,
                'website' => $user->website,
                'skills' => $user->skills,
                'joined_at' => $user->created_at->toIso8601String(),
                'role_level' => $user->role_level,
                'status' => $user->status,
                // Only show email if they are in the same team
                'email' => $user->email,
            ],
        ]);
    }
}
