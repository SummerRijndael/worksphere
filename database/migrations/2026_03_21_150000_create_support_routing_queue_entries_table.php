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
        Schema::create('support_routing_queue_entries', function (Blueprint $table): void {
            $table->id();
            $table->foreignId('conversation_id')->unique()->constrained('support_conversations')->cascadeOnDelete();
            $table->foreignId('support_skill_id')->nullable()->constrained('support_skills')->nullOnDelete();
            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->string('state', 20)->default('pending')->index(); // pending|routing|assigned|failed|cancelled
            $table->string('enqueue_reason', 40)->default('conversation_opened')->index();
            $table->unsignedSmallInteger('priority')->default(100)->index();
            $table->unsignedSmallInteger('attempts')->default(0);
            $table->unsignedSmallInteger('max_attempts')->default(20);
            $table->text('last_error')->nullable();
            $table->json('meta')->nullable();
            $table->timestamp('next_attempt_at')->nullable()->index();
            $table->timestamp('last_routed_at')->nullable();
            $table->timestamps();

            $table->index(
                ['state', 'next_attempt_at', 'priority', 'created_at'],
                'support_routing_queue_state_idx'
            );
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_routing_queue_entries');
    }
};

