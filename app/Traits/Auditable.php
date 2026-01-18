<?php

namespace App\Traits;

use App\Services\AuditService;
use Illuminate\Database\Eloquent\Model;

trait Auditable
{
    /**
     * Boot the Auditable trait.
     */
    public static function bootAuditable(): void
    {
        static::updating(function (Model $model) {
            try {
                // Resolve service from container (singleton)
                $auditService = app(AuditService::class);
                $auditService->captureChanges($model);
            } catch (\Throwable $e) {
                // Fail silently to not block updates
                report($e);
            }
        });
    }
}
