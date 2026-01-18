<?php

namespace App\Jobs;

use App\Models\Event;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;

class SyncGoogleEventJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        public Event $event,
        public string $action = 'save' // 'save' or 'delete'
    ) {}

    /**
     * Execute the job.
     */
    public function handle(\App\Contracts\GoogleCalendarContract $service): void
    {
        if ($this->action === 'delete') {
            $service->deleteFromGoogle($this->event);
        } else {
            $service->syncToGoogle($this->event);
        }
    }
}
