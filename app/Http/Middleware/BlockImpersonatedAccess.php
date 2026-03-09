<?php

namespace App\Http\Middleware;

use App\Services\ImpersonationService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class BlockImpersonatedAccess
{
    public function __construct(
        protected ImpersonationService $impersonationService
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     */
    public function handle(Request $request, Closure $next): Response
    {
        if ($this->impersonationService->isImpersonating()) {
            return response()->json([
                'message' => 'This feature is locked during impersonation for security and privacy.',
                'code' => 'feature_locked_impersonation'
            ], 403);
        }

        return $next($request);
    }
}
