<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('meeting_participants', function (Illuminate\Database\Schema\Blueprint $table) {
            $table->string('assigned_room_id')->nullable();
            $table->string('current_room_id')->nullable();
        });
    }

    public function down(): void
    {
        Schema::table('meeting_participants', function (Illuminate\Database\Schema\Blueprint $table) {
            $table->dropColumn(['assigned_room_id', 'current_room_id']);
        });
    }
};
