<?php

namespace App\Http\Controllers\Api\Support;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\Support\SupportRealtimeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupportBroadcastController extends Controller
{
    public function __construct(
        protected SupportRealtimeService $supportRealtimeService
    ) {}

    public function authenticate(Request $request): JsonResponse|\Illuminate\Http\Response
    {
        $validated = $request->validate([
            'socket_id' => ['required', 'string'],
            'channel_name' => ['required', 'string'],
            'support_realtime_token' => ['nullable', 'string'],
        ]);

        $actor = $this->resolveActor($request);
        $token = (string) ($validated['support_realtime_token']
            ?? $request->header('X-Support-Realtime-Token', ''));

        try {
            return $this->supportRealtimeService->authenticateBroadcasting(
                (string) $validated['channel_name'],
                (string) $validated['socket_id'],
                $actor,
                $token
            );
        } catch (AuthorizationException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 403);
        } catch (\Throwable) {
            return response()->json([
                'message' => 'Support realtime authorization failed.',
            ], 403);
        }
    }

    protected function resolveActor(Request $request): ?User
    {
        return $request->user() ?: $request->user('sanctum');
    }
}
