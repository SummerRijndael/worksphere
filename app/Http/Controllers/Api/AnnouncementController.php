<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\Announcement;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class AnnouncementController extends Controller
{
    /**
     * Get active announcements for the current user.
     * No permission required - all authenticated users can see announcements.
     */
    public function active(Request $request): JsonResponse
    {
        $user = $request->user();

        $announcements = Announcement::visible()
            ->when($user, function ($query) use ($user) {
                // Exclude dismissed announcements for logged-in users
                return $query->where(function ($q) use ($user) {
                    $q->where('is_dismissable', false)
                        ->orWhereDoesntHave('dismissedBy', function ($sub) use ($user) {
                            $sub->where('user_id', $user->id);
                        });
                });
            })
            ->orderBy('type', 'desc') // danger first, then warning, etc.
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $announcements->map(fn ($a) => [
                'id' => $a->id,
                'title' => $a->title,
                'message' => $a->message,
                'type' => $a->type,
                'action_text' => $a->action_text,
                'action_url' => $a->action_url,
                'is_dismissable' => $a->is_dismissable,
                'is_public' => $a->is_public,
            ]),
        ]);
    }

    /**
     * Get active public announcements (no auth required).
     */
    public function public(): JsonResponse
    {
        $announcements = Announcement::visible()
            ->where('is_public', true)
            ->orderBy('type', 'desc')
            ->orderBy('created_at', 'desc')
            ->get();

        return response()->json([
            'data' => $announcements->map(fn ($a) => [
                'id' => $a->id,
                'title' => $a->title,
                'message' => $a->message,
                'type' => $a->type,
                'action_text' => $a->action_text,
                'action_url' => $a->action_url,
                'is_dismissable' => $a->is_dismissable,
            ]),
        ]);
    }

    /**
     * Dismiss an announcement.
     */
    public function dismiss(Request $request, Announcement $announcement): JsonResponse
    {
        $user = $request->user();

        if (! $announcement->is_dismissable) {
            return response()->json([
                'message' => 'This announcement cannot be dismissed.',
            ], 422);
        }

        if (! $announcement->isVisible()) {
            return response()->json([
                'message' => 'This announcement is no longer active.',
            ], 404);
        }

        $announcement->dismissFor($user);

        return response()->json([
            'message' => 'Announcement dismissed successfully.',
        ]);
    }

    // =====================================
    // Admin endpoints (permission required)
    // =====================================

    /**
     * List all announcements (admin).
     */
    public function index(Request $request): JsonResponse
    {
        $announcements = Announcement::with('createdBy:id,name')
            ->orderBy('created_at', 'desc')
            ->paginate($request->integer('per_page', 15));

        return response()->json($announcements);
    }

    /**
     * Get a single announcement.
     */
    public function show(Announcement $announcement): JsonResponse
    {
        $announcement->load('createdBy:id,name');

        return response()->json([
            'data' => $announcement,
        ]);
    }

    /**
     * Create a new announcement.
     */
    public function store(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'required|string|max:255',
            'message' => 'required|string|max:2000',
            'type' => 'required|string|in:info,warning,danger,success',
            'action_text' => 'nullable|string|max:50',
            'action_url' => 'nullable|url|max:500',
            'is_dismissable' => 'boolean',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date|after:starts_at',
        ]);

        $announcement = Announcement::create([
            ...$validated,
            'created_by' => $request->user()->id,
        ]);

        return response()->json([
            'message' => 'Announcement created successfully.',
            'data' => $announcement,
        ], 201);
    }

    /**
     * Update an announcement.
     */
    public function update(Request $request, Announcement $announcement): JsonResponse
    {
        $validated = $request->validate([
            'title' => 'sometimes|required|string|max:255',
            'message' => 'sometimes|required|string|max:2000',
            'type' => 'sometimes|required|string|in:info,warning,danger,success',
            'action_text' => 'nullable|string|max:50',
            'action_url' => 'nullable|url|max:500',
            'is_dismissable' => 'boolean',
            'is_active' => 'boolean',
            'is_public' => 'boolean',
            'starts_at' => 'nullable|date',
            'ends_at' => 'nullable|date',
        ]);

        $announcement->update($validated);

        return response()->json([
            'message' => 'Announcement updated successfully.',
            'data' => $announcement->fresh(),
        ]);
    }

    /**
     * Delete an announcement.
     */
    public function destroy(Announcement $announcement): JsonResponse
    {
        $announcement->delete();

        return response()->json([
            'message' => 'Announcement deleted successfully.',
        ]);
    }

    /**
     * Get announcement types.
     */
    public function types(): JsonResponse
    {
        return response()->json([
            'data' => Announcement::types(),
        ]);
    }
}
