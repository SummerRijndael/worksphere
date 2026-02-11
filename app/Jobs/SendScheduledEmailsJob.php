<?php

namespace App\Jobs;

use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;

class SendScheduledEmailsJob implements ShouldQueue
{
    use Queueable;

    /**
     * Create a new job instance.
     */
    public function __construct()
    {
        //
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        \App\Models\Email::where('is_draft', true)
            ->whereNotNull('scheduled_at')
            ->where('scheduled_at', '<=', now())
            ->chunkById(50, function ($emails) {
                foreach ($emails as $email) {
                    // Mark as no longer a draft (it's now "sending" or "sent")
                    $email->update(['is_draft' => false]);

                    SendEmailJob::dispatch($email->id, $email->email_account_id);

                    \Illuminate\Support\Facades\Log::info('[SendScheduledEmailsJob] Dispatched scheduled email', [
                        'email_id' => $email->id,
                        'scheduled_at' => $email->scheduled_at,
                    ]);
                }
            });
    }

    /**
     * Get the middleware the job should pass through.
     */
    public function middleware(): array
    {
        return [new \App\Jobs\Middleware\LogMemoryUsage];
    }
}
