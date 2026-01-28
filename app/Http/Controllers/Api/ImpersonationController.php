<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\User;
use App\Services\ImpersonationService;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Validation\ValidationException;

class ImpersonationController extends Controller
{
    public function __construct(
        protected ImpersonationService $impersonationService
    ) {}

    /**
     * Start impersonating a user.
     */
    public function impersonate(Request $request, User $user): JsonResponse
    {
        $this->authorize('users.impersonate');

        // Confirm password for security
        if (! \Illuminate\Support\Facades\Hash::check($request->input('password'), $request->user()->password)) {
             throw ValidationException::withMessages([
                'password' => ['Incorrect password. Re-authentication required for this action.'],
            ]);
        }

        try {
            $this->impersonationService->impersonate($request->user(), $user);
            return response()->json([
                'message' => "You are now impersonating {$user->name}.",
                'redirect' => '/dashboard',
            ]);
        } catch (\Exception $e) {
             return response()->json(['message' => $e->getMessage()], 403);
        }
    }

    /**
     * Stop impersonating.
     */
    public function stop(): JsonResponse
    {
        try {
            $this->impersonationService->stopImpersonating();
            return response()->json([
                'message' => 'Impersonation ended. Welcome back.',
                'redirect' => '/admin/users',
            ]);
        } catch (\Exception $e) {
            return response()->json(['message' => $e->getMessage()], 400);
        }
    }
    
    /**
     * Check impersonation status.
     */
    public function status(): JsonResponse
    {
        return response()->json([
            'is_impersonating' => $this->impersonationService->isImpersonating(),
            'impersonator' => $this->impersonationService->getImpersonator(),
        ]);
    }
}
