<?php

namespace App\Http\Middleware;

use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class EnforceTwoFactor
{
    /**
     * Paths excluded from 2FA enforcement check.
     *
     * @var array<string>
     */
    protected array $excludedPaths = [
        'api/logout',
        'api/user/two-factor-*',
        'api/user/passkeys*',
        'api/user/confirm-password',
        'api/user/2fa-enforcement-status',
        'setup-2fa-required',
    ];

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        $user = $request->user();

        if (! $user) {
            return $next($request);
        }

        // Skip for excluded paths
        foreach ($this->excludedPaths as $pattern) {
            if ($request->is($pattern)) {
                return $next($request);
            }
        }

        // Check if user requires 2FA setup based on enforcement rules
        $requirement = $user->requires2FASetup();

        if ($requirement && $requirement['required']) {
            if ($request->expectsJson()) {
                return response()->json([
                    'message' => 'Two-factor authentication setup is required for your account.',
                    'action' => 'setup_2fa',
                    'redirect' => '/setup-2fa-required',
                    'allowed_methods' => $requirement['methods'],
                    'enforcement_source' => $requirement['source'],
                    'role' => $requirement['role'] ?? null,
                ], 403);
            }

            return redirect('/setup-2fa-required');
        }

        return $next($request);
    }
}
