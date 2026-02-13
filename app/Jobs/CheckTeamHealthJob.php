<?php

namespace App\Jobs;

use App\Models\Team;
use App\Services\AppSettingsService;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class CheckTeamHealthJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Execute the job.
     */
    public function handle(AppSettingsService $settings): void
    {
        $dormantDays = (int) $settings->get('teams.dormant_days', 90);
        $graceDays = (int) $settings->get('teams.deletion_grace_days', 30);
        $autoDeleteEnabled = (bool) $settings->get('teams.auto_delete', false);

        $stats = [
            'marked_dormant' => 0,
            'marked_pending_deletion' => 0,
            'auto_deleted' => 0,
            'notifications_sent' => 0,
        ];

        // 1. Find active teams that should be marked dormant
        $dormantThreshold = now()->subDays($dormantDays);
        Team::query()
            ->lifecycleActive()
            ->where(function ($query) use ($dormantThreshold) {
                $query->where('last_activity_at', '<', $dormantThreshold)
                    ->orWhereNull('last_activity_at');
            })
            ->chunkById(50, function ($teams) use (&$stats) {
                foreach ($teams as $team) {
                    $team->markDormant();
                    $stats['notifications_sent']++;
                    $stats['marked_dormant']++;
                }
            });

        // 2. Find dormant teams that should be marked pending deletion
        $pendingDeletionThreshold = now()->subDays($graceDays);
        Team::query()
            ->dormant()
            ->where('dormant_notified_at', '<', $pendingDeletionThreshold)
            ->chunkById(50, function ($teams) use (&$stats) {
                foreach ($teams as $team) {
                    $team->markPendingDeletion();
                    $stats['notifications_sent']++;
                    $stats['marked_pending_deletion']++;
                }
            });

        // 3. Auto-delete teams past the grace period (if enabled)
        if ($autoDeleteEnabled) {
            $autoDeleteThreshold = now()->subDays($graceDays);
            Team::query()
                ->pendingDeletion()
                ->where('deletion_scheduled_at', '<', $autoDeleteThreshold)
                ->chunkById(50, function ($teams) use (&$stats) {
                    foreach ($teams as $team) {
                        Log::warning('Auto-deleting team due to inactivity', [
                            'team_id' => $team->id,
                            'team_name' => $team->name,
                        ]);
                        $team->delete();
                        $stats['auto_deleted']++;
                    }
                });
        }

        Log::info('Team health check job completed', $stats);
    }
}
