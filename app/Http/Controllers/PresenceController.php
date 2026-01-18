<?php

namespace App\Http\Controllers;

use App\Services\Chat\PresenceService;
use App\Services\ConnectionManagerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\RateLimiter;

class PresenceController extends Controller
{
    /**
     * Rate limit: 60 requests per minute (1 per second) for Heartbeats to allow aggressive client retries without blockage.
     * Actual interval is controlled by client (30s) + visibility logic.
     */
    protected const HEARTBEAT_LIMIT = 60;

    /**
     * Rate limit: 20 offline signals per minute.
     */
    protected const OFFLINE_LIMIT = 20;

    /**
     * Rate limit: 10 status changes per minute.
     */
    protected const STATUS_CHANGE_LIMIT = 10;

    public function __construct(
        protected PresenceService $presenceService,
        protected ConnectionManagerService $connectionManager
    ) {}

    /**
     * Send a heartbeat to indicate the user is active.
     *
     * POST /api/presence/heartbeat
     */
    public function heartbeat(Request $request): JsonResponse
    {
        $user = Auth::user();
        $key = "presence:heartbeat:{$user->id}";

        if (RateLimiter::tooManyAttempts($key, self::HEARTBEAT_LIMIT)) {
            // Return 429 but we treat it as valid "alive" signal in frontend
            return response()->json([
                'status' => 'ok',
                'throttled' => true,
                'presence' => $this->presenceService->presenceStatus($user->id),
                'retry_after' => RateLimiter::availableIn($key),
            ], 429);
        }

        RateLimiter::hit($key, 60);

        $this->presenceService->heartbeat($user);

        return response()->json([
            'status' => 'ok',
            'presence' => $this->presenceService->presenceStatus($user->id),
        ]);
    }

    /**
     * Explicitly mark user as offline (e.g., on page unload).
     *
     * POST /api/presence/offline
     */
    public function offline(Request $request): JsonResponse
    {
        $user = Auth::user();
        $key = "presence:offline:{$user->id}";

        if (RateLimiter::tooManyAttempts($key, self::OFFLINE_LIMIT)) {
            return response()->json([
                'error' => 'Too many offline requests',
                'retry_after' => RateLimiter::availableIn($key),
            ], 429);
        }

        RateLimiter::hit($key, 60);

        $this->presenceService->markOffline($user);

        return response()->json(['status' => 'ok']);
    }

    /**
     * Update the user's presence status preference.
     *
     * PUT /api/presence/status
     */
    public function updateStatus(Request $request): JsonResponse
    {
        $request->validate([
            'status' => 'required|string',
        ]);

        $user = Auth::user();
        $key = "presence:status:{$user->id}";

        if (RateLimiter::tooManyAttempts($key, self::STATUS_CHANGE_LIMIT)) {
            return response()->json([
                'message' => 'Too many status changes. Please wait a moment.',
                'retry_after' => RateLimiter::availableIn($key),
            ], 429);
        }

        RateLimiter::hit($key, 60);

        // Normalize status
        $status = $this->normalizeStatus($request->input('status'));

        $this->presenceService->setExplicitStatus($user, $status);

        return response()->json([
            'status' => 'ok',
            'presence' => $this->presenceService->presenceStatus($user->id),
            'preference' => $status,
        ]);
    }

    /**
     * Get the current user's presence status.
     *
     * GET /api/presence/me
     */
    public function me(): JsonResponse
    {
        $user = Auth::user();

        return response()->json([
            'public_id' => $user->public_id,
            'status' => $this->presenceService->presenceStatus($user->id),
            'preference' => $user->presence_preference ?? 'online',
            'last_seen' => $user->last_seen_at?->timestamp,
        ]);
    }

    /**
     * Get presence status for multiple users.
     *
     * GET /api/presence/users
     */
    public function users(Request $request): JsonResponse
    {
        $request->validate([
            'public_ids' => 'required|array|max:50',
            'public_ids.*' => 'string|uuid',
        ]);

        $publicIds = $request->input('public_ids');
        $users = \App\Models\User::whereIn('public_id', $publicIds)->get();

        $presenceData = [];
        foreach ($users as $user) {
            // Skip invisible users (they appear offline)
            $status = $this->presenceService->presenceStatus($user->id);
            if ($user->presence_preference === 'invisible') {
                $status = 'offline';
            }

            $presenceData[] = [
                'public_id' => $user->public_id,
                'status' => $status,
                'last_seen' => $user->last_seen_at?->timestamp,
            ];
        }

        return response()->json([
            'users' => $presenceData,
        ]);
    }

    /**
     * Check if a WebSocket connection can be established.
     *
     * POST /api/presence/connect
     */
    public function checkConnection(Request $request): JsonResponse
    {
        $user = Auth::user();
        $result = $this->connectionManager->canConnect($user->public_id);

        if (! $result['allowed']) {
            return response()->json($result, 429);
        }

        return response()->json([
            'allowed' => true,
            'connection_count' => $this->connectionManager->getConnectionCount($user->public_id),
        ]);
    }

    /**
     * Debug: Manually broadcast a status change (development only).
     *
     * POST /api/presence/debug/broadcast
     */
    public function debugBroadcast(Request $request): JsonResponse
    {
        // Only allow administrators to debug presence
        if (! Auth::user()->hasRole('administrator') && ! app()->environment('local')) {
            return response()->json(['error' => 'Unauthorized'], 403);
        }

        $request->validate([
            'status' => 'required|string',
        ]);

        $user = Auth::user();
        $status = $this->normalizeStatus($request->input('status'));

        // Dispatch the event directly without changing DB preference
        \App\Events\UserPresenceChanged::dispatch($user, $status);

        return response()->json([
            'success' => true,
            'broadcast_status' => $status,
            'user_public_id' => $user->public_id,
            'channels' => [
                'presence.'.$user->public_id,
                'presence-online-users',
            ],
            'timestamp' => now()->toISOString(),
        ]);
    }

    /**
     * Normalize presence status strings.
     */
    protected function normalizeStatus(string $status): string
    {
        $status = strtolower(trim($status));

        return match ($status) {
            'online', 'active', 'here' => 'online',
            'away', 'idle' => 'away',
            'busy', 'dnd' => 'busy',
            'invisible', 'hidden' => 'invisible',
            'offline' => 'offline',
            default => 'online',
        };
    }
}
