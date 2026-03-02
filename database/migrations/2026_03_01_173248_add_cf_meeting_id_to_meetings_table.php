<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            // Cloudflare RealtimeKit meeting ID — set when a PRO recording session is bootstrapped.
            // Null for free-tier meetings (they never touch RealtimeKit).
            $table->string('cf_meeting_id')->nullable()->after('app_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn('cf_meeting_id');
        });
    }
};
