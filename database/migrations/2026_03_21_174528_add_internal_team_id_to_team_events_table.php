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
        Schema::table('team_events', function (Blueprint $table) {
            $table->foreignId('team_id')->nullable()->change();
            $table->foreignId('internal_team_id')->after('team_id')->nullable()->constrained()->cascadeOnDelete();
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('team_events', function (Blueprint $table) {
            $table->dropForeign(['internal_team_id']);
            $table->dropColumn('internal_team_id');
            $table->foreignId('team_id')->nullable(false)->change();
        });
    }
};
