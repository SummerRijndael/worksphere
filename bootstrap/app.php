<?php

use App\Http\Middleware\AuditRequest;
use App\Http\Middleware\CheckUserStatus;
use App\Http\Middleware\TeamPermission;
use Illuminate\Foundation\Application;
use Illuminate\Foundation\Configuration\Exceptions;
use Illuminate\Foundation\Configuration\Middleware;
use Illuminate\Http\Request;
use Symfony\Component\HttpKernel\Exception\NotFoundHttpException;
use Symfony\Component\HttpKernel\Exception\TooManyRequestsHttpException;

return Application::configure(basePath: dirname(__DIR__))
    ->withRouting(
        web: __DIR__.'/../routes/web.php',
        api: __DIR__.'/../routes/api.php',
        commands: __DIR__.'/../routes/console.php',
        channels: __DIR__.'/../routes/channels.php',
        health: '/up',
        apiPrefix: 'api',
    )
    ->withBroadcasting(
        __DIR__.'/../routes/channels.php',
        ['prefix' => 'api', 'middleware' => ['api', 'auth:sanctum']],
    )
    ->withMiddleware(function (Middleware $middleware): void {
        // Stateful API (for SPA with Sanctum)
        $middleware->statefulApi();

        // Alias middleware
        $middleware->alias([
            'role' => \Spatie\Permission\Middleware\RoleMiddleware::class,
            'permission' => \Spatie\Permission\Middleware\PermissionMiddleware::class,
            'role_or_permission' => \Spatie\Permission\Middleware\RoleOrPermissionMiddleware::class,
            'check_status' => CheckUserStatus::class,
            'team.permission' => TeamPermission::class,
            'audit' => AuditRequest::class,
            '2fa.enforce' => \App\Http\Middleware\EnforceTwoFactor::class,
        ]);

        // Append middleware to web group
        $middleware->web(append: [
            CheckUserStatus::class,
            \App\Http\Middleware\SecurityHeaders::class,
            \App\Http\Middleware\TrackPageView::class,
        ]);

        // API middleware configuration
        $middleware->api(prepend: [
            \Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class,
        ]);

        $middleware->api(append: [
            \App\Http\Middleware\SecurityHeaders::class,
            \App\Http\Middleware\CheckImpersonation::class,
        ]);

        // Exclude certain API routes from CSRF verification
        // These routes are protected by auth:sanctum and rate limiting
        $middleware->validateCsrfTokens(except: [
            'api/two-factor-challenge',
            'api/two-factor-challenge/*',
            'api/email/verification-notification',
        ]);
    })
    ->withExceptions(function (Exceptions $exceptions): void {
        $exceptions->report(function (\Illuminate\Http\Exceptions\ThrottleRequestsException $e) {
            try {
                $request = request();

                \App\Models\AuditLog::create([
                    'public_id' => \Illuminate\Support\Str::uuid(),
                    'user_id' => $request->user()?->id,
                    'user_name' => $request->user()?->name ?? 'Guest',
                    'user_email' => $request->user()?->email ?? $request->input('email'),
                    'action' => \App\Enums\AuditAction::RateLimitExceeded,
                    'category' => \App\Enums\AuditCategory::Security,
                    'severity' => \App\Enums\AuditSeverity::Warning,
                    'ip_address' => $request->ip(),
                    'user_agent' => $request->userAgent(),
                    'url' => $request->fullUrl(),
                    'method' => $request->method(),
                    'metadata' => [
                        'headers' => $e->getHeaders(),
                        'api_endpoint' => $request->path(),
                    ],
                    'created_at' => now(),
                ]);
            } catch (\Throwable $t) {
                // Fail silently to avoid validation/database errors during exception reporting
            }
        });

        // Handle API exceptions as JSON
        $exceptions->render(function (NotFoundHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Resource not found.',
                ], 404);
            }
        });

        $exceptions->render(function (TooManyRequestsHttpException $e, Request $request) {
            if ($request->is('api/*')) {
                return response()->json([
                    'message' => 'Too many requests. Please slow down.',
                    'retry_after' => $e->getHeaders()['Retry-After'] ?? null,
                ], 429);
            }
        });
    })->create();
