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
        Schema::create('analytics_daily_stats', function (Blueprint $table) {
            $table->id();
            $table->date('date')->unique();
            $table->unsignedBigInteger('total_views')->default(0);
            $table->unsignedBigInteger('unique_visitors')->default(0);
            $table->float('avg_session_duration')->default(0);
            $table->float('bounce_rate')->default(0);
            $table->json('device_stats')->nullable();
            $table->json('browser_stats')->nullable();
            $table->json('page_stats')->nullable();
            $table->json('referer_stats')->nullable();
            $table->timestamps();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('analytics_daily_stats');
    }
};
