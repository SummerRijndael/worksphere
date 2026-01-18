<?php

namespace App\Http\Controllers\Auth;

use App\Http\Controllers\Controller;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class SetupPasswordController extends Controller
{
    /**
     * Handle the incoming request.
     */
    public function store(Request $request, string $id): JsonResponse
    {
        // Find user by public_id since the route param uses that (from AccountCreated notification)
        $user = User::where('public_id', $id)->firstOrFail();

        // 1. Verify Signature is handled by 'signed' middleware on the route
        // However, if we need custom logic or if the middleware passes but we want to verify user matches
        // The signature includes the 'id' parameter, so middleware ensures this URL was generated for this ID.

        // 2. Validate Password
        $validated = $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        // 3. Update User
        $user->forceFill([
            'password' => Hash::make($validated['password']),
            'is_password_set' => true,
            'password_last_updated_at' => now(),
            'email_verified_at' => now(), // Auto-verify email upon secure setup
        ])->save();

        return response()->json(['message' => 'Password set successfully. You can now log in.']);
    }
}
