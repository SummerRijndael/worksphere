<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DirectoryUserResource;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\DB;

class DirectoryController extends Controller
{
    /**
     * Search for users within the authenticated user's scope (shared teams).
     */
    public function search(Request $request)
    {
        $request->validate([
            'search' => 'required|string|min:2',
        ]);

        $user = $request->user();
        $query = $request->input('search');

        // 1. Get IDs of teams the current user belongs to
        $teamIds = $user->teams()->pluck('teams.id');

        // 2. Search users who are members of any of these teams
        $users = User::query()
            ->whereHas('teams', function ($q) use ($teamIds) {
                $q->whereIn('teams.id', $teamIds);
            })
            ->where(function ($q) use ($query) {
                $q->where('name', 'like', "%{$query}%")
                  ->orWhere('email', 'like', "%{$query}%");
            })
            // Exclude current user from results (optional, but usually desired for "sharing with others")
            ->where('id', '!=', $user->id)
            ->limit(20)
            ->get();

        return DirectoryUserResource::collection($users);
    }
}
