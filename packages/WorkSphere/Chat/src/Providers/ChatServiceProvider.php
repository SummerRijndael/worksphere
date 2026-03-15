<?php

namespace WorkSphere\Chat\Providers;

use Illuminate\Support\ServiceProvider;

class ChatServiceProvider extends ServiceProvider
{
    /**
     * Register services.
     */
    public function register(): void
    {
        $this->mergeConfigFrom(
            __DIR__.'/../../config/chat.php', 'chat'
        );
    }

    /**
     * Bootstrap services.
     */
    public function boot(): void
    {
        if ($this->app->runningInConsole()) {
            $this->publishes([
                __DIR__.'/../../config/chat.php' => config_path('chat.php'),
            ], 'chat-config');

            $this->loadMigrationsFrom(__DIR__.'/../../database/migrations');
        }

        $this->loadRoutesFrom(__DIR__.'/../../routes/chat.php');

        $this->registerBroadcastingChannels();
    }

    /**
     * Register the package's broadcasting channels.
     */
    protected function registerBroadcastingChannels(): void
    {
        \Illuminate\Support\Facades\Broadcast::channel('pkg.dm.{publicId}', function ($user, $publicId) {
            return \WorkSphere\Chat\Models\Chat::where('public_id', $publicId)
                ->whereHas('participants', function ($q) use ($user) {
                    $q->where('user_id', $user->id);
                })->exists();
        });

        \Illuminate\Support\Facades\Broadcast::channel('pkg.group.{publicId}', function ($user, $publicId) {
            return \WorkSphere\Chat\Models\Chat::where('public_id', $publicId)
                ->whereHas('participants', function ($q) use ($user) {
                    $q->where('user_id', $user->id);
                })->exists();
        });
    }
}
