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
        Schema::table('firewall_ips', function (Blueprint $table) {
            $table->text('reason')->nullable()->after('blocked');
            $table->unsignedBigInteger('user_id')->nullable()->after('reason'); // Normalized for blocked_by/added_by
            $table->timestamp('expires_at')->nullable()->after('user_id');
            $table->string('label')->nullable()->after('expires_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('firewall_ips', function (Blueprint $table) {
            $table->dropColumn(['reason', 'user_id', 'expires_at', 'label']);
        });
    }
};
