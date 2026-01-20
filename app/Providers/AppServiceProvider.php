<?php

namespace App\Providers;

use Illuminate\Cache\RateLimiting\Limit;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Gate;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\ServiceProvider;
use Laravel\Pulse\Facades\Pulse;

class AppServiceProvider extends ServiceProvider
{
    /**
     * Register any application services.
     */
    public function register(): void
    {
        $this->app->bind(\App\Contracts\FaqServiceInterface::class, \App\Services\FaqService::class);
        // Bind TicketService contract to implementation
        $this->app->bind(
            \App\Contracts\TicketServiceContract::class,
            \App\Services\TicketService::class
        );

        // Bind AvatarService as singleton
        $this->app->singleton(
            \App\Contracts\AvatarContract::class,
            \App\Services\AvatarService::class
        );

        $this->app->bind(
            \App\Contracts\EmailServiceContract::class,
            \App\Services\EmailService::class
        );

        $this->app->bind(
            \App\Contracts\EmailSyncServiceContract::class,
            \App\Services\EmailSyncService::class
        );

        $this->app->bind(
            \App\Contracts\NoteContract::class,
            \App\Services\NoteService::class
        );

        // Calendar Sharing
        $this->app->bind(
            \App\Contracts\CalendarShareContract::class,
            \App\Services\CalendarShareService::class
        );

        // Google Calendar
        $this->app->bind(
            \App\Contracts\GoogleCalendarContract::class,
            \App\Services\GoogleCalendarService::class
        );

        // Template Services
        $this->app->bind(
            \App\Contracts\TaskTemplateServiceContract::class,
            \App\Services\TaskTemplateService::class
        );

        $this->app->bind(
            \App\Contracts\InvoiceTemplateServiceContract::class,
            \App\Services\InvoiceTemplateService::class
        );
    }

    /**
     * Bootstrap any application services.
     */
    public function boot(): void
    {
        $this->configureRateLimiting();

        \Illuminate\Support\Facades\Event::subscribe(\App\Listeners\ScheduledTaskSubscriber::class);
        \App\Models\Event::observe(\App\Observers\EventObserver::class);

        // Register Policies
        Gate::policy(\App\Models\FaqCategory::class, \App\Policies\FaqPolicy::class);
        Gate::policy(\App\Models\FaqArticle::class, \App\Policies\FaqPolicy::class);
        Gate::policy(\Spatie\Permission\Models\Role::class, \App\Policies\RolePolicy::class);

        // Pulse authorization - allow users with system.maintenance permission
        Gate::define('viewPulse', function ($user) {
            return $user->hasPermissionTo('system.maintenance');
        });

        // Record user in Pulse requests
        Pulse::user(fn ($user) => [
            'name' => $user->name,
            'extra' => $user->email,
            'avatar' => $user->avatar_url,
        ]);

        // Dynamic Env Builder: Override config with Database Settings
        // We use Schema check to ensure migrations run smoothly on fresh installs
        if (\Illuminate\Support\Facades\Schema::hasTable('settings')) {
            try {
                // Apply settings using the service
                // This will fetch from Cache/DB and overwrite config() in-memory
                app(\App\Services\AppSettingsService::class)->applyToConfig();
            } catch (\Throwable $e) {
                // Fail silently/log during boot to prevent crashing CLI/Queues if DB/Cache is down
                // This ensures 'php artisan' commands still work even if infrastructure is shaky
                // We use php's error_log to avoid circular dependency with Laravel logger if it's not ready
                error_log('Failed to apply app settings during boot: '.$e->getMessage());
            }
        }

        // Custom Password Reset URL
        \Illuminate\Auth\Notifications\ResetPassword::createUrlUsing(function (object $notifiable, string $token) {
            return config('app.frontend_url', config('app.url'))."/auth/reset-password?token={$token}&email=".urlencode($notifiable->getEmailForPasswordReset());
        });
    }

    /**
     * Configure the rate limiters for the application.
     */
    protected function configureRateLimiting(): void
    {
        // Rate limiter for authenticated API requests
        RateLimiter::for('api', function (Request $request) {
            return Limit::perMinute(160)->by($request->user()?->id ?: $request->ip());
        });

        // Rate limiter for guest requests (login, register, etc.)
        RateLimiter::for('guest', function (Request $request) {
            return Limit::perMinute(25)->by($request->ip());
        });

        // Strict rate limiter for sensitive operations
        RateLimiter::for('sensitive', function (Request $request) {
            return Limit::perMinute(10)->by($request->user()?->id ?: $request->ip());
        });

        // Rate limiter for password reset
        RateLimiter::for('password-reset', function (Request $request) {
            return Limit::perMinute(5)->by($request->ip());
        });

        // Rate limiter for login attempts
        RateLimiter::for('login', function (Request $request) {
            return Limit::perMinute(10)->by($request->ip());
        });
    }
}
