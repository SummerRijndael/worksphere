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
            $table->dateTime('actual_start_time')->nullable()->after('end_time');
            $table->dateTime('actual_end_time')->nullable()->after('actual_start_time');
            $table->integer('unique_participant_count')->default(0)->after('actual_end_time');
            $table->integer('peak_participant_count')->default(0)->after('unique_participant_count');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('meetings', function (Blueprint $table) {
            $table->dropColumn([
                'actual_start_time',
                'actual_end_time',
                'unique_participant_count',
                'peak_participant_count',
            ]);
        });
    }
};
