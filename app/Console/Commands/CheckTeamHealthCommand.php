<?php

namespace App\Console\Commands;

use App\Models\Team;
use App\Services\AppSettingsService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class CheckTeamHealthCommand extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'teams:check-health 
                            {--dry-run : Run without making changes}
                            {--force : Force run even if auto-delete is disabled}';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check team health and manage lifecycle status (dormant, pending deletion, auto-delete)';

    /**
     * Execute the console command.
     */
    public function handle(AppSettingsService $settings): int
    {
        $dryRun = $this->option('dry-run');
        $force = $this->option('force');

        $dormantDays = (int) $settings->get('teams.dormant_days', 90);
        $graceDays = (int) $settings->get('teams.deletion_grace_days', 30);
        $autoDeleteEnabled = $force || (bool) $settings->get('teams.auto_delete', false);

        $this->info('Team Health Check Started');
        $this->info("  Dormant after: {$dormantDays} days");
        $this->info("  Deletion grace: {$graceDays} days");
        $this->info('  Auto-delete: '.($autoDeleteEnabled ? 'enabled' : 'disabled'));

        if ($dryRun) {
            $this->warn('DRY RUN MODE - No changes will be made');
        }

        $stats = [
            'marked_dormant' => 0,
            'marked_pending_deletion' => 0,
            'auto_deleted' => 0,
            'notifications_sent' => 0,
        ];

        // 1. Find active teams that should be marked dormant
        $dormantThreshold = now()->subDays($dormantDays);
        $queryDormant = Team::query()
            ->lifecycleActive()
            ->where(function ($query) use ($dormantThreshold) {
                $query->where('last_activity_at', '<', $dormantThreshold)
                    ->orWhereNull('last_activity_at');
            });

        $queryDormant->chunkById(50, function ($teams) use (&$stats, $dryRun) {
            foreach ($teams as $team) {
                $this->line("  → Marking dormant: {$team->name} (last activity: ".($team->last_activity_at?->diffForHumans() ?? 'never').')');

                if (! $dryRun) {
                    $team->markDormant();
                    // TODO: Send dormant notification to owner
                    // $team->owner->notify(new TeamDormantNotification($team));
                    $stats['notifications_sent']++;
                }
                $stats['marked_dormant']++;
            }
        });

        // 2. Find dormant teams that should be marked pending deletion
        $pendingDeletionThreshold = now()->subDays($graceDays);
        $queryPending = Team::query()
            ->dormant()
            ->where('dormant_notified_at', '<', $pendingDeletionThreshold);

        $queryPending->chunkById(50, function ($teams) use (&$stats, $dryRun) {
            foreach ($teams as $team) {
                $this->line("  → Marking pending deletion: {$team->name} (dormant since: ".$team->dormant_notified_at?->diffForHumans().')');

                if (! $dryRun) {
                    $team->markPendingDeletion();
                    // TODO: Send pending deletion notification to owner
                    // $team->owner->notify(new TeamPendingDeletionNotification($team));
                    $stats['notifications_sent']++;
                }
                $stats['marked_pending_deletion']++;
            }
        });

        // 3. Auto-delete teams past the grace period (if enabled)
        if ($autoDeleteEnabled) {
            $autoDeleteThreshold = now()->subDays($graceDays);
            $queryDelete = Team::query()
                ->pendingDeletion()
                ->where('deletion_scheduled_at', '<', $autoDeleteThreshold);

            $queryDelete->chunkById(50, function ($teams) use (&$stats, $dryRun) {
                foreach ($teams as $team) {
                    $this->warn("  → AUTO-DELETING: {$team->name}");

                    if (! $dryRun) {
                        Log::warning('Auto-deleting team due to inactivity', [
                            'team_id' => $team->id,
                            'team_name' => $team->name,
                            'owner_id' => $team->owner_id,
                            'last_activity_at' => $team->last_activity_at,
                            'dormant_notified_at' => $team->dormant_notified_at,
                            'deletion_scheduled_at' => $team->deletion_scheduled_at,
                        ]);

                        $team->delete();
                    }
                    $stats['auto_deleted']++;
                }
            });
        }

        // Summary
        $this->newLine();
        $this->info('Team Health Check Complete');
        $this->table(
            ['Action', 'Count'],
            [
                ['Marked Dormant', $stats['marked_dormant']],
                ['Marked Pending Deletion', $stats['marked_pending_deletion']],
                ['Auto-Deleted', $stats['auto_deleted']],
                ['Notifications Sent', $stats['notifications_sent']],
            ]
        );

        Log::info('Team health check completed', $stats);

        return Command::SUCCESS;
    }
}
