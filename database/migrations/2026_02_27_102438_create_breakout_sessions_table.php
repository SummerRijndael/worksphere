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
        Schema::create('breakout_sessions', function (Illuminate\Database\Schema\Blueprint $table) {
            $table->id();
            $table->string('public_id')->unique();
            $table->foreignId('meeting_id')->constrained()->onDelete('cascade');
            $table->string('status')->default('active'); // active, ended
            $table->json('rooms_config'); // Stores the initial room structure/naming
            $table->integer('duration_minutes')->nullable();
            $table->timestamp('started_at')->useCurrent();
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('breakout_sessions');
    }
};
