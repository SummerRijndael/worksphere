<?php

namespace App\Jobs;

use App\Contracts\TicketServiceContract;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessTicketRemindersJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(TicketServiceContract $ticketService): void
    {
        $slaBreaches = $ticketService->checkSlaBreaches();
        $reminders = $ticketService->sendDeadlineReminders();

        Log::info('Ticket reminders job completed', [
            'sla_breaches' => $slaBreaches,
            'reminders_sent' => $reminders,
        ]);
    }
}
