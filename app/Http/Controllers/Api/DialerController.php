<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Http\Resources\DialerCallResource;
use App\Models\DialerCall;
use App\Services\Dialer\DialerService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use InvalidArgumentException;

class DialerController extends Controller
{
    public function __construct(
        protected DialerService $dialerService,
    ) {}

    public function bootstrap(Request $request): JsonResponse
    {
        $bundle = $this->dialerService->bootstrap($request->user());
        $recentCalls = $bundle['recent_calls'];

        return response()->json([
            'data' => [
                ...$bundle,
                'active_call' => $bundle['active_call'] ? new DialerCallResource($bundle['active_call']) : null,
                'recent_calls' => [
                    'data' => DialerCallResource::collection($recentCalls->items())->resolve(),
                    'pagination' => [
                        'current_page' => $recentCalls->currentPage(),
                        'per_page' => $recentCalls->perPage(),
                        'total' => $recentCalls->total(),
                        'last_page' => $recentCalls->lastPage(),
                    ],
                ],
            ],
        ]);
    }

    public function placeCall(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'to_number' => ['required', 'string', 'max:30'],
            'contact_name' => ['nullable', 'string', 'max:120'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $call = $this->dialerService->dial($request->user(), (string) $validated['to_number'], $validated);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Outbound call request accepted.',
            'data' => new DialerCallResource($call),
        ]);
    }

    public function hangup(Request $request, DialerCall $dialerCall): JsonResponse
    {
        try {
            $call = $this->dialerService->hangup($request->user(), $dialerCall);
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Call ended.',
            'data' => new DialerCallResource($call),
        ]);
    }

    public function transfer(Request $request, DialerCall $dialerCall): JsonResponse
    {
        $validated = $request->validate([
            'target_number' => ['required', 'string', 'max:30'],
            'notes' => ['nullable', 'string', 'max:500'],
        ]);

        try {
            $call = $this->dialerService->transfer(
                $request->user(),
                $dialerCall,
                (string) $validated['target_number'],
                $validated
            );
        } catch (InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Call transferred.',
            'data' => new DialerCallResource($call),
        ]);
    }
}
