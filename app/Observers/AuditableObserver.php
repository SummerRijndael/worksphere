<?php

namespace App\Observers;

use App\Enums\AuditAction;
use App\Services\AuditService;
use Illuminate\Database\Eloquent\Model;

class AuditableObserver
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * Handle the "created" event.
     */
    public function created(Model $model): void
    {
        if (! $this->shouldAudit($model)) {
            return;
        }

        $this->auditService->logModelChange(
            action: AuditAction::Created,
            model: $model,
            newValues: $this->getAuditableAttributes($model)
        );
    }

    /**
     * Handle the "updated" event.
     */
    public function updated(Model $model): void
    {
        if (! $this->shouldAudit($model)) {
            return;
        }

        $changes = $model->getChanges();
        $original = array_intersect_key($model->getOriginal(), $changes);

        // Skip if no meaningful changes
        if (empty($changes)) {
            return;
        }

        // Remove timestamp fields from audit
        unset($changes['updated_at'], $changes['created_at']);
        unset($original['updated_at'], $original['created_at']);

        if (empty($changes)) {
            return;
        }

        $this->auditService->logModelChange(
            action: AuditAction::Updated,
            model: $model,
            oldValues: $this->filterAuditableAttributes($model, $original),
            newValues: $this->filterAuditableAttributes($model, $changes)
        );
    }

    /**
     * Handle the "deleted" event.
     */
    public function deleted(Model $model): void
    {
        if (! $this->shouldAudit($model)) {
            return;
        }

        $this->auditService->logModelChange(
            action: AuditAction::Deleted,
            model: $model,
            oldValues: $this->getAuditableAttributes($model)
        );
    }

    /**
     * Handle the "restored" event.
     */
    public function restored(Model $model): void
    {
        if (! $this->shouldAudit($model)) {
            return;
        }

        $this->auditService->logModelChange(
            action: AuditAction::Restored,
            model: $model
        );
    }

    /**
     * Handle the "force deleted" event.
     */
    public function forceDeleted(Model $model): void
    {
        if (! $this->shouldAudit($model)) {
            return;
        }

        $this->auditService->logModelChange(
            action: AuditAction::ForceDeleted,
            model: $model,
            oldValues: $this->getAuditableAttributes($model)
        );
    }

    /**
     * Determine if the model should be audited.
     */
    protected function shouldAudit(Model $model): bool
    {
        // Check if model uses Auditable trait or is in auditable list
        $auditableModels = config('audit.models', []);

        if (in_array(get_class($model), $auditableModels)) {
            return true;
        }

        // Check for trait or interface
        if (method_exists($model, 'isAuditable')) {
            return $model->isAuditable();
        }

        return false;
    }

    /**
     * Get auditable attributes from the model.
     *
     * @return array<string, mixed>
     */
    protected function getAuditableAttributes(Model $model): array
    {
        $attributes = $model->getAttributes();

        return $this->filterAuditableAttributes($model, $attributes);
    }

    /**
     * Filter attributes to only include auditable ones.
     *
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function filterAuditableAttributes(Model $model, array $attributes): array
    {
        // Get excluded fields from model or config
        $excluded = $this->getExcludedFields($model);

        // Remove excluded fields
        $filtered = array_diff_key($attributes, array_flip($excluded));

        // Remove internal fields
        unset($filtered['id'], $filtered['created_at'], $filtered['updated_at']);

        return $filtered;
    }

    /**
     * Get fields to exclude from auditing.
     *
     * @return array<string>
     */
    protected function getExcludedFields(Model $model): array
    {
        $configExcluded = config('audit.excluded_fields', []);

        if (method_exists($model, 'getAuditExclude')) {
            $modelExcluded = $model->getAuditExclude();

            return array_merge($configExcluded, $modelExcluded);
        }

        // Also exclude hidden fields by default
        if (method_exists($model, 'getHidden')) {
            return array_merge($configExcluded, $model->getHidden());
        }

        return $configExcluded;
    }
}
