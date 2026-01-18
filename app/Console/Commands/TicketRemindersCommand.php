<?php

namespace App\Console\Commands;

use App\Contracts\TicketServiceContract;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class TicketRemindersCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'tickets:reminders';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check SLA breaches and send deadline reminders for tickets';

    /**
     * Execute the console command.
     */
    public function handle(TicketServiceContract $ticketService): int
    {
        $this->info('Checking SLA breaches...');
        $slaBreaches = $ticketService->checkSlaBreaches();
        $this->info("Marked {$slaBreaches} tickets as SLA breached.");

        $this->info('Sending deadline reminders...');
        $reminders = $ticketService->sendDeadlineReminders();
        $this->info("Sent {$reminders} deadline reminders.");

        Log::info('Ticket reminders command completed', [
            'sla_breaches' => $slaBreaches,
            'reminders_sent' => $reminders,
        ]);

        return Command::SUCCESS;
    }
}
