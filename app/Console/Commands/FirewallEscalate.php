<?php

namespace App\Console\Commands;

use App\Models\FirewallIp;
use Illuminate\Console\Command;
use Illuminate\Support\Carbon;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;

/**
 * Escalate firewall blocks for repeat offenders.
 *
 * Logic:
 *  - 1st block:  30 minutes (default Akaunting behavior)
 *  - 2nd block within 24h: escalate to 2 hours
 *  - 3rd block within 24h: escalate to 12 hours
 *  - 4th+ block within 24h: permanent block (no expiry)
 *
 * This command runs every minute alongside firewall:unblockip.
 * It checks currently blocked IPs against the firewall_logs history
 * and escalates the block duration if the IP is a repeat offender.
 */
class FirewallEscalate extends Command
{
    protected $signature = 'firewall:escalate';

    protected $description = 'Escalate block duration for repeat-offender IPs based on firewall log history';

    /**
     * Escalation tiers: [min_blocks_in_24h => block_duration_in_minutes]
     * null = permanent (no expiry)
     */
    protected array $tiers = [
        2 => 120,      // 2nd offense → 2 hours
        3 => 720,      // 3rd offense → 12 hours
        4 => null,      // 4th+ offense → permanent
    ];

    public function handle(): int
    {
        $blockedIps = FirewallIp::where('blocked', true)->get();

        if ($blockedIps->isEmpty()) {
            $this->components->info('No blocked IPs to evaluate.');

            return self::SUCCESS;
        }

        $escalated = 0;
        $cutoff = Carbon::now()->subHours(24);

        foreach ($blockedIps as $blockedIp) {
            // Count distinct block incidents in the last 24 hours from firewall_logs
            $recentBlockCount = DB::table('firewall_logs')
                ->where('ip', $blockedIp->ip)
                ->where('created_at', '>=', $cutoff)
                ->count();

            if ($recentBlockCount < 2) {
                continue; // First offense, leave default TTL alone
            }

            // Determine the appropriate tier
            $newDuration = $this->getDuration($recentBlockCount);
            $newExpiresAt = $newDuration ? Carbon::now()->addMinutes($newDuration) : null;

            // Only escalate — never reduce an existing block
            if ($blockedIp->expires_at === null) {
                continue; // Already permanently blocked
            }

            if ($newExpiresAt === null || $newExpiresAt->greaterThan($blockedIp->expires_at)) {
                $previousExpiry = $blockedIp->expires_at?->toDateTimeString() ?? 'never';

                $blockedIp->update([
                    'expires_at' => $newExpiresAt,
                    'reason' => $this->buildReason($recentBlockCount, $newDuration),
                ]);

                $escalated++;

                Log::channel('security')->warning('Firewall block escalated for repeat offender', [
                    'ip' => $blockedIp->ip,
                    'block_count_24h' => $recentBlockCount,
                    'previous_expires' => $previousExpiry,
                    'new_expires' => $newExpiresAt?->toDateTimeString() ?? 'PERMANENT',
                    'tier' => $newDuration ? "{$newDuration}min" : 'permanent',
                ]);
            }
        }

        if ($escalated > 0) {
            $this->components->warn("Escalated {$escalated} IP(s) based on repeat offenses.");
        } else {
            $this->components->info('No repeat offenders detected.');
        }

        return self::SUCCESS;
    }

    /**
     * Get block duration in minutes for the given block count.
     * Returns null for permanent blocks.
     */
    protected function getDuration(int $blockCount): ?int
    {
        $duration = 30; // default

        foreach ($this->tiers as $threshold => $minutes) {
            if ($blockCount >= $threshold) {
                $duration = $minutes;
            }
        }

        return $duration;
    }

    /**
     * Build a human-readable reason for the escalation.
     */
    protected function buildReason(int $blockCount, ?int $durationMinutes): string
    {
        if ($durationMinutes === null) {
            return "Permanent block — {$blockCount} offenses in 24h";
        }

        $hours = round($durationMinutes / 60, 1);

        return "Escalated to {$hours}h — {$blockCount} offenses in 24h";
    }
}
