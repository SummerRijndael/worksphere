<?php

namespace App\Console\Commands;

use App\Models\Meeting;
use App\Services\Chat\PresenceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class PruneStaleMeetings extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'meetings:prune';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Detect abandoned meetings without auto-ending them';

    /**
     * Execute the console command.
     */
    public function handle(PresenceService $presenceService)
    {
        // Find active meetings that started more than 5 minutes ago
        $meetings = Meeting::where('status', 'active')
            ->where('actual_start_time', '<=', now()->subMinutes(5))
            ->get();

        $abandoned = 0;

        foreach ($meetings as $meeting) {
            $activeCount = count($presenceService->getActiveMeetingParticipantIds($meeting->public_id));

            if ($activeCount === 0) {
                $abandoned++;
                Log::info('[meetings:prune] Active meeting has no connected participants. Auto-end is disabled.', [
                    'meeting' => $meeting->public_id,
                    'active_count' => $activeCount,
                ]);
            }
        }

        $this->info("Checked {$meetings->count()} active meetings. {$abandoned} currently have no active participants.");

        return self::SUCCESS;
    }
}
