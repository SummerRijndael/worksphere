<?php

namespace App\Providers;

use Illuminate\Foundation\Support\Providers\EventServiceProvider as ServiceProvider;

class EventServiceProvider extends ServiceProvider
{
    /**
     * The event to listener mappings for the application.
     *
     * Note: Laravel 11+ automatically registers SendEmailVerificationNotification
     * for the Registered event. Do NOT add it here or it will send duplicate emails.
     *
     * @var array<class-string, array<int, class-string>>
     */
    protected $listen = [
        //
    ];

    /**
     * Register any events for your application.
     */
    public function boot(): void
    {
        //
    }

    /**
     * Configure the proper event listeners for email verification.
     *
     * Override to prevent duplicate registration - Laravel 11+ automatically
     * registers this in the framework, so we don't need to add it again.
     */
    protected function configureEmailVerification(): void
    {
        // Intentionally empty - prevent Laravel from auto-registering
        // the SendEmailVerificationNotification listener a second time.
        // The listener is already registered by the framework.
    }

    /**
     * Determine if events and listeners should be automatically discovered.
     */
    public function shouldDiscoverEvents(): bool
    {
        return false;
    }
}
