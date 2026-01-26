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
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->string('google_channel_id')->nullable()->after('token_expires_at');
            $table->string('google_resource_id')->nullable()->after('google_channel_id');
            $table->dateTime('google_channel_expiration')->nullable()->after('google_resource_id');
            $table->text('google_sync_token')->nullable()->after('google_channel_expiration');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('social_accounts', function (Blueprint $table) {
            $table->dropColumn([
                'google_channel_id',
                'google_resource_id',
                'google_channel_expiration',
                'google_sync_token',
            ]);
        });
    }
};
