<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Auth;
use Symfony\Component\HttpFoundation\Response;

class CheckUserStatus
{
    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if (Auth::check()) {
            $user = Auth::user();

            if (! $user->canLogin()) {
                Auth::logout();

                $request->session()->invalidate();
                $request->session()->regenerateToken();

                $statusConfig = config('roles.statuses.'.$user->status, []);
                $message = $user->status_reason ?? ($statusConfig['label'] ?? 'Your account has been disabled.');

                if ($request->expectsJson()) {
                    return response()->json([
                        'message' => 'Account not accessible.',
                        'reason' => $message,
                        'status' => $user->status,
                    ], 403);
                }

                return redirect()->route('login')->with('error', $message);
            }
        }

        return $next($request);
    }
}
