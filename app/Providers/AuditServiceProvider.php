<?php

namespace App\Providers;

use App\Listeners\AuditEventSubscriber;
use App\Observers\AuditableObserver;
use App\Services\AuditService;
use App\Services\CacheService;
use App\Services\PermissionService;
use Illuminate\Console\Scheduling\Schedule;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\ServiceProvider;

class AuditServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        // Register CacheService as singleton
        $this->app->singleton(CacheService::class, function ($app) {
            return new CacheService;
        });

        // Register PermissionService as singleton
        $this->app->singleton(PermissionService::class, function ($app) {
            return new PermissionService(
                $app->make(CacheService::class)
            );
        });

        // Register AuditService as singleton
        $this->app->singleton(AuditService::class, function ($app) {
            return new AuditService(
                $app->make(CacheService::class)
            );
        });

        // Merge config
        $this->mergeConfigFrom(
            __DIR__.'/../../config/audit.php',
            'audit'
        );

        $this->mergeConfigFrom(
            __DIR__.'/../../config/caching.php',
            'caching'
        );
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        // Register the event subscriber
        Event::subscribe(AuditEventSubscriber::class);

        // Register model observers for auditable models
        $this->registerModelObservers();

        // Register scheduled tasks
        $this->registerScheduledTasks();
    }

    /**
     * Register model observers for auditable models.
     */
    protected function registerModelObservers(): void
    {
        $auditableModels = config('audit.models', []);

        foreach ($auditableModels as $model) {
            if (class_exists($model)) {
                $model::observe(AuditableObserver::class);
            }
        }
    }

    /**
     * Register scheduled tasks for audit log maintenance.
     */
    protected function registerScheduledTasks(): void
    {
        $this->app->booted(function (): void {
            $schedule = $this->app->make(Schedule::class);

            // Prune old audit logs daily at 3 AM
            $retentionDays = config('audit.retention_days', 90);

            if ($retentionDays > 0) {
                $schedule->call(function (): void {
                    $auditService = app(AuditService::class);
                    $pruned = $auditService->prune();

                    if ($pruned > 0) {
                        logger()->info("Pruned {$pruned} old audit logs.");
                    }
                })->daily()->at('03:00')->name('audit:prune');
            }
        });
    }
}
