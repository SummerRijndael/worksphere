<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Hash;
use Illuminate\Validation\Rules\Password;

class SetPasswordController extends Controller
{
    /**
     * Set password for users who haven't set one (e.g. social login).
     */
    public function store(Request $request)
    {
        $user = $request->user();

        if ($user->is_password_set) {
            return response()->json([
                'message' => 'Password already set. Please use password update to change it.',
            ], 403);
        }

        $request->validate([
            'password' => ['required', 'confirmed', Password::defaults()],
        ]);

        $user->forceFill([
            'password' => Hash::make($request->password),
            'is_password_set' => true,
            'password_last_updated_at' => now(),
        ])->save();

        return response()->json([
            'message' => 'Password set successfully.',
        ]);
    }
}
