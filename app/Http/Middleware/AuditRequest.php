<?php

namespace App\Http\Middleware;

use App\Enums\AuditAction;
use App\Enums\AuditCategory;
use App\Services\AuditService;
use Closure;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\Response;

class AuditRequest
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * Handle an incoming request.
     *
     * @param  \Closure(\Illuminate\Http\Request): (\Symfony\Component\HttpFoundation\Response)  $next
     * @param  string|null  $action  Optional action name override
     */
    public function handle(Request $request, Closure $next, ?string $action = null): Response
    {
        $response = $next($request);

        // Only audit successful mutating requests by default
        if (! $this->shouldAudit($request, $response)) {
            return $response;
        }

        // Log after response is complete (async)
        app()->terminating(function () use ($request, $response, $action): void {
            $auditAction = $action
                ? (AuditAction::tryFrom($action) ?? AuditAction::DataAccessed)
                : $this->determineAction($request);

            // Retrieve captured changes
            $changes = $this->auditService->getCapturedChanges();
            $oldValues = [];
            $newValues = [];

            if (! empty($changes)) {
                // If single model change, flatten it
                if (count($changes) === 1) {
                    $change = reset($changes);
                    $oldValues = $change['old'];
                    $newValues = $change['new'];
                } else {
                    // Multiple changes, namespace them
                    foreach ($changes as $key => $change) {
                        $modelName = class_basename($change['model']);
                        $id = $change['id'];
                        $compositeKey = "{$modelName} #{$id}";

                        if (! empty($change['old'])) {
                            $oldValues[$compositeKey] = $change['old'];
                        }
                        if (! empty($change['new'])) {
                            $newValues[$compositeKey] = $change['new'];
                        }
                    }
                }
            }

            $this->auditService->log(
                action: $auditAction,
                category: AuditCategory::Api,
                user: $request->user(),
                oldValues: ! empty($oldValues) ? $oldValues : null,
                newValues: ! empty($newValues) ? $newValues : null,
                context: [
                    'route' => $request->route()?->getName(),
                    'route_action' => $request->route()?->getActionName(),
                    'status_code' => $response->getStatusCode(),
                    'request_params' => $this->sanitizeRequestData($request),
                ]
            );
        });

        return $response;
    }

    /**
     * Determine if the request should be audited.
     */
    protected function shouldAudit(Request $request, Response $response): bool
    {
        // Skip GET requests unless explicitly configured
        if ($request->isMethod('GET') && ! config('audit.log_reads', false)) {
            return false;
        }

        // Skip failed requests (4xx, 5xx)
        if ($response->getStatusCode() >= 400) {
            return false;
        }

        // Skip certain paths
        $excludedPaths = config('audit.excluded_paths', []);

        foreach ($excludedPaths as $path) {
            if ($request->is($path)) {
                return false;
            }
        }

        return true;
    }

    /**
     * Determine the audit action based on HTTP method.
     */
    protected function determineAction(Request $request): AuditAction
    {
        return match ($request->method()) {
            'POST' => AuditAction::Created,
            'PUT', 'PATCH' => AuditAction::Updated,
            'DELETE' => AuditAction::Deleted,
            default => AuditAction::DataAccessed,
        };
    }

    /**
     * Sanitize request data for logging.
     *
     * @return array<string, mixed>
     */
    protected function sanitizeRequestData(Request $request): array
    {
        $sensitiveKeys = [
            'password',
            'password_confirmation',
            'current_password',
            'token',
            'api_key',
            'secret',
            'credit_card',
            'cvv',
        ];

        $data = $request->except($sensitiveKeys);

        // Limit data size
        return array_map(function ($value) {
            if (is_string($value) && strlen($value) > 500) {
                return substr($value, 0, 500).'...[truncated]';
            }
            if (is_array($value)) {
                return '[array]';
            }

            return $value;
        }, $data);
    }
}
