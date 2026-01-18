<?php

namespace App\Jobs;

use App\Models\User;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class WatchGoogleCalendarJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public User $user
    ) {}

    /**
     * Execute the job.
     */
    public function handle(\App\Contracts\GoogleCalendarContract $service): void
    {
        \Illuminate\Support\Facades\Log::info("DEBUG: WatchGoogleCalendarJob started for user {$this->user->id}");
        try {
            $service->watchCalendar($this->user);
            \Illuminate\Support\Facades\Log::info("DEBUG: WatchGoogleCalendarJob finished for user {$this->user->id}");
        } catch (\Exception $e) {
            \Illuminate\Support\Facades\Log::error('DEBUG: WatchGoogleCalendarJob FAILED: '.$e->getMessage());
            throw $e;
        }
    }
}
