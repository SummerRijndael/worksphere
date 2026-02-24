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
        Schema::table('meeting_participants', function (Blueprint $table) {
            $table->boolean('is_muted_by_host')->default(false);
            $table->boolean('is_camera_disabled_by_host')->default(false);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('meeting_participants', function (Blueprint $table) {
            $table->dropColumn(['is_muted_by_host', 'is_camera_disabled_by_host']);
        });
    }
};
