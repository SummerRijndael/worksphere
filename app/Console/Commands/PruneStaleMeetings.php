<?php

namespace App\Console\Commands;

use App\Events\Meetings\MeetingSignal;
use App\Events\Meetings\MeetingStatusUpdated;
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
    protected $description = 'Automatically close abandoned or crashed meetings';

    /**
     * Execute the console command.
     */
    public function handle(PresenceService $presenceService)
    {
        // Find active meetings that started more than 5 minutes ago
        $meetings = Meeting::where('status', 'active')
            ->where('actual_start_time', '<=', now()->subMinutes(5))
            ->get();

        $pruned = 0;

        foreach ($meetings as $meeting) {
            $activeCount = count($presenceService->getActiveMeetingParticipantIds($meeting->public_id));

            if ($activeCount === 0) {
                $meeting->update([
                    'status' => 'ended',
                    'actual_end_time' => now()->subMinutes(5), // End time reflects when the last person likely dropped (with leeway)
                ]);

                broadcast(new MeetingSignal(
                    $meeting,
                    'system',
                    'meeting-ended',
                    ['ended_by' => 'system']
                ));

                broadcast(new MeetingStatusUpdated($meeting));

                $pruned++;
            }
        }

        $this->info("Pruned {$pruned} stale meetings.");
        if ($pruned > 0) {
            Log::info("Pruned {$pruned} stale meetings.");
        }
    }
}
