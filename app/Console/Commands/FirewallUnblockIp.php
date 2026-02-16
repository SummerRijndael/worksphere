<?php

namespace App\Console\Commands;

use App\Models\FirewallIp;
use Carbon\Carbon;
use Illuminate\Console\Command;

/**
 * Override Akaunting's firewall:unblockip to respect the `expires_at` column.
 *
 * The default Akaunting command ignores `expires_at` and uses config-based
 * period calculations. This version:
 *  - Uses `expires_at` if set (from our escalation system)
 *  - Falls back to config-based period if `expires_at` is null AND the IP has a log
 *  - Skips permanently blocked IPs (blocked=true, expires_at=null, reason starts with "Permanent")
 */
class FirewallUnblockIp extends Command
{
    protected $signature = 'firewall:unblockip';

    protected $description = 'Unblock IPs based on their block period (with escalation support)';

    public function handle(): int
    {
        $now = Carbon::now(config('app.timezone'));
        $unblocked = 0;

        FirewallIp::where('blocked', true)->each(function (FirewallIp $ip) use ($now, &$unblocked) {
            // Permanent block — never auto-unblock
            if ($ip->expires_at === null && str_starts_with($ip->reason ?? '', 'Permanent')) {
                return;
            }

            // If expires_at is set, use it directly
            if ($ip->expires_at !== null) {
                if ($ip->expires_at->lte($now)) {
                    $ip->update(['blocked' => false]);
                    $unblocked++;
                }
                return;
            }

            // Fallback: use Akaunting's config-based period (for non-escalated blocks)
            $log = $ip->log;
            if (empty($log)) {
                return;
            }

            $period = config("firewall.middleware.{$log->middleware}.auto_block.period", 1800);

            if ($ip->created_at->addSeconds($period)->lte($now)) {
                $ip->update(['blocked' => false]);
                $unblocked++;
            }
        });

        if ($unblocked > 0) {
            $this->components->info("Unblocked {$unblocked} IP(s).");
        }

        return self::SUCCESS;
    }
}
