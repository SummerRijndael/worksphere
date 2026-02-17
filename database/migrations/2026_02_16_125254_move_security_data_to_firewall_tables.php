<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        // Move Blocked IPs
        // Old table: blocked_ips (ip_address, reason, blocked_by_user_id, expires_at)
        // New table: firewall_ips (ip, blocked=1, reason, user_id, expires_at)
        if (Schema::hasTable('blocked_ips')) {
            $blockedIps = DB::table('blocked_ips')->get();
            foreach ($blockedIps as $item) {
                if (! DB::table('firewall_ips')->where('ip', $item->ip_address)->exists()) {
                    DB::table('firewall_ips')->insert([
                        'ip' => $item->ip_address,
                        'blocked' => 1,
                        'reason' => $item->reason,
                        'user_id' => $item->blocked_by_user_id,
                        'expires_at' => $item->expires_at,
                        'label' => null, // Blocked IPs usually don't have label in old system
                        'created_at' => $item->created_at ?? now(),
                        'updated_at' => $item->updated_at ?? now(),
                    ]);
                }
            }
        }

        // Move Whitelisted IPs
        // Old table: whitelisted_ips (ip_address, label, added_by)
        // New table: firewall_ips (ip, blocked=0, label, user_id)
        if (Schema::hasTable('whitelisted_ips')) {
            $whitelistedIps = DB::table('whitelisted_ips')->get();
            foreach ($whitelistedIps as $item) {
                if (! DB::table('firewall_ips')->where('ip', $item->ip_address)->exists()) {
                    DB::table('firewall_ips')->insert([
                        'ip' => $item->ip_address,
                        'blocked' => 0,
                        'reason' => null,
                        'user_id' => $item->added_by,
                        'expires_at' => null, // Whitelisted IPs are usually permanent
                        'label' => $item->label,
                        'created_at' => $item->created_at ?? now(),
                        'updated_at' => $item->updated_at ?? now(),
                    ]);
                }
            }
        }
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        // No reverse action needed as we are just copying data.
        // We don't want to delete firewall_ips on rollback as new data might have been added.
    }
};
