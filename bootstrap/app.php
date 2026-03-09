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
            'demo' => \App\Http\Middleware\CheckDemoMode::class,
            'impersonation.block' => \App\Http\Middleware\BlockImpersonatedAccess::class,
            // Firewall Aliases
            'firewall.agent' => \Akaunting\Firewall\Middleware\Agent::class,
            'firewall.bot' => \Akaunting\Firewall\Middleware\Bot::class,
            'firewall.geo' => \Akaunting\Firewall\Middleware\Geo::class,
            'firewall.ip' => \Akaunting\Firewall\Middleware\Ip::class,
            'firewall.lfi' => \Akaunting\Firewall\Middleware\Lfi::class,
            'firewall.php' => \Akaunting\Firewall\Middleware\Php::class,
            'firewall.referrer' => \Akaunting\Firewall\Middleware\Referrer::class,
            'firewall.rfi' => \Akaunting\Firewall\Middleware\Rfi::class,
            'firewall.session' => \Akaunting\Firewall\Middleware\Session::class,
            'firewall.sqli' => \Akaunting\Firewall\Middleware\Sqli::class,
            'firewall.swear' => \Akaunting\Firewall\Middleware\Swear::class,
            'firewall.url' => \Akaunting\Firewall\Middleware\Url::class,
            'firewall.whitelist' => \Akaunting\Firewall\Middleware\Whitelist::class,
            'firewall.xss' => \Akaunting\Firewall\Middleware\Xss::class,
        ]);

        // Append middleware to web group
        $middleware->web(append: [
            \Akaunting\Firewall\Middleware\Ip::class,
            \Akaunting\Firewall\Middleware\Agent::class,
            \Akaunting\Firewall\Middleware\Bot::class,
            \Akaunting\Firewall\Middleware\Lfi::class,
            \Akaunting\Firewall\Middleware\Php::class,
            \Akaunting\Firewall\Middleware\Referrer::class,
            \Akaunting\Firewall\Middleware\Rfi::class,
            \Akaunting\Firewall\Middleware\Sqli::class,
            \Akaunting\Firewall\Middleware\Xss::class,

            CheckUserStatus::class,
            \App\Http\Middleware\SecurityHeaders::class,
            \App\Http\Middleware\SetUserTimezone::class,
            \App\Http\Middleware\TrackPageView::class,
        ]);

        // API middleware configuration
        $middleware->api(prepend: array_merge(
            [\App\Http\Middleware\SecurityHeaders::class],
            ($_ENV['APP_ENV'] ?? '') === 'testing' ? [] : [
                \Akaunting\Firewall\Middleware\Ip::class,
                \Akaunting\Firewall\Middleware\Agent::class,
                \Akaunting\Firewall\Middleware\Bot::class,
                \Akaunting\Firewall\Middleware\Lfi::class,
                \Akaunting\Firewall\Middleware\Php::class,
                \Akaunting\Firewall\Middleware\Referrer::class,
                \Akaunting\Firewall\Middleware\Rfi::class,
                \Akaunting\Firewall\Middleware\Sqli::class,
                \Akaunting\Firewall\Middleware\Xss::class,
            ],
            [\Laravel\Sanctum\Http\Middleware\EnsureFrontendRequestsAreStateful::class]
        ));

        $middleware->api(append: [
            \App\Http\Middleware\CheckImpersonation::class,
        ]);

        // Exclude certain fields from TrimStrings middleware
        $middleware->trimStrings(except: [
            'sessionDescription.sdp',
            'sdp',
        ]);

        // Exclude certain API routes from CSRF verification
        // These routes are protected by auth:sanctum and rate limiting
        // Exclude certain API routes from CSRF verification
        // These routes are protected by auth:sanctum and rate limiting
        $middleware->validateCsrfTokens(except: [
            // 'api/*', // Temporarily Allow All API for ZAP Scan
            'stripe/*',
            'reverb/*',
            'api/two-factor-challenge',
            'api/two-factor-challenge/*',
            'api/email/verification-notification',
            'api/webhooks/google/pubsub',
            'api/webhooks/cloudflare/recording',
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
