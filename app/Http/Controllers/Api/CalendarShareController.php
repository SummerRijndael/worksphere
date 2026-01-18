<?php

namespace App\Http\Controllers\Api;

use App\Contracts\CalendarShareContract;
use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Validation\Rule;

class CalendarShareController extends Controller
{
    public function __construct(
        protected CalendarShareContract $shareService
    ) {}

    /**
     * List all users who have shared their calendar with specific permissions.
     * Also lists users that the current user has shared their calendar with.
     */
    public function index(Request $request)
    {
        $user = $request->user();

        return response()->json($this->shareService->getShares($user));
    }

    /**
     * Share calendar with a user.
     */
    public function store(Request $request)
    {
        $validated = $request->validate([
            'email' => 'required|email|exists:users,email',
            'permission' => ['required', Rule::in(['view', 'edit'])],
        ]);

        try {
            $this->shareService->share($request->user(), $validated['email'], $validated['permission']);

            // TODO: Send notification email
            return response()->json(['message' => 'Calendar shared successfully.']);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }
    }

    /**
     * Revoke a share (Stop sharing with someone).
     */
    public function destroy(Request $request, $userId)
    {
        if ($this->shareService->revoke($request->user(), $userId)) {
            return response()->json(['message' => 'Access revoked.']);
        }

        return response()->json(['message' => 'Share not found.'], 404);
    }

    /**
     * Update permissions for an existing share
     */
    public function update(Request $request, $id)
    {
        $validated = $request->validate([
            'permission' => ['required', Rule::in(['view', 'edit'])],
        ]);

        if ($this->shareService->updatePermission($request->user(), $id, $validated['permission'])) {
            return response()->json(['message' => 'Permissions updated.']);
        }

        return response()->json(['message' => 'Share not found.'], 404);
    }
}
