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
        Schema::create('team_events', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('team_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete(); // Creator
            $table->string('title');
            $table->text('description')->nullable();
            $table->dateTime('start_time');
            $table->dateTime('end_time')->nullable();
            $table->boolean('is_all_day')->default(false);
            $table->integer('reminder_minutes_before')->nullable();
            $table->timestamp('notification_sent_at')->nullable();
            $table->string('color', 7)->default('#8B5CF6'); // Purple default
            $table->string('location')->nullable();
            $table->timestamps();

            $table->index(['team_id', 'start_time']);
        });

        Schema::create('team_event_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('team_event_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->enum('status', ['pending', 'accepted', 'rejected', 'tentative'])->default('pending');
            $table->timestamps();

            $table->unique(['team_event_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('team_event_user');
        Schema::dropIfExists('team_events');
    }
};
