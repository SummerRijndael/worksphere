<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\BlockedUrl;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;

class BlockedUrlController extends Controller
{
    public function index(Request $request)
    {
        Gate::authorize('system.manage_blocklist');

        $query = BlockedUrl::query();

        if ($request->has('search')) {
            $search = $request->input('search');
            $query->where('pattern', 'like', "%{$search}%")
                ->orWhere('reason', 'like', "%{$search}%");
        }

        $blockedUrls = $query->orderBy('created_at', 'desc')
            ->paginate(10);

        return response()->json([
            'data' => $blockedUrls,
        ]);
    }

    public function store(Request $request)
    {
        Gate::authorize('system.manage_blocklist');

        $validated = $request->validate([
            'pattern' => 'required|string|unique:blocked_urls,pattern|max:255',
            'reason' => 'nullable|string|max:255',
        ]);

        $blockedUrl = BlockedUrl::create($validated);

        return response()->json([
            'message' => 'Blocked URL added successfully.',
            'data' => $blockedUrl,
        ], 201);
    }

    public function destroy(BlockedUrl $blockedUrl)
    {
        Gate::authorize('system.manage_blocklist');

        $blockedUrl->delete();

        return response()->json([
            'message' => 'Blocked URL removed successfully.',
        ]);
    }
}
