<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('meeting_polls', function (Blueprint $table) {
            $table->id();
            $table->string('public_id', 26)->unique();
            $table->foreignId('meeting_id')->constrained()->onDelete('cascade');
            $table->foreignId('created_by')->constrained('meeting_participants')->onDelete('cascade');
            $table->string('question');
            $table->json('options'); // ["Option A", "Option B", ...]
            $table->boolean('is_active')->default(true);
            $table->timestamp('ended_at')->nullable();
            $table->timestamps();
        });

        Schema::create('meeting_poll_votes', function (Blueprint $table) {
            $table->id();
            $table->foreignId('poll_id')->constrained('meeting_polls')->onDelete('cascade');
            $table->foreignId('participant_id')->constrained('meeting_participants')->onDelete('cascade');
            $table->unsignedTinyInteger('option_index');
            $table->timestamps();

            // One vote per participant per poll
            $table->unique(['poll_id', 'participant_id']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('meeting_poll_votes');
        Schema::dropIfExists('meeting_polls');
    }
};
