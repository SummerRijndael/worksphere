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
        Schema::create('support_survey_invites', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique()->index();
            $table->foreignId('conversation_id')->constrained('support_conversations')->cascadeOnDelete();
            $table->foreignId('requester_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('issued_by_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('survey_type', 20)->index(); // csat|nps
            $table->string('status', 20)->default('pending')->index(); // pending|responded|expired|revoked
            $table->string('token_hash', 64)->unique();
            $table->timestamp('issued_at')->nullable();
            $table->timestamp('expires_at')->nullable()->index();
            $table->timestamp('responded_at')->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'survey_type', 'status']);
        });

        Schema::create('support_survey_responses', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique()->index();
            $table->foreignId('invite_id')->constrained('support_survey_invites')->cascadeOnDelete()->unique();
            $table->foreignId('conversation_id')->constrained('support_conversations')->cascadeOnDelete();
            $table->foreignId('requester_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('rated_agent_user_id')->nullable()->constrained('users')->nullOnDelete();
            $table->string('survey_type', 20)->index(); // csat|nps
            $table->unsignedTinyInteger('score');
            $table->string('label', 32)->nullable()->index();
            $table->text('comment')->nullable();
            $table->string('channel', 64)->nullable();
            $table->string('submitted_from_ip', 64)->nullable();
            $table->string('submitted_user_agent', 512)->nullable();
            $table->json('metadata')->nullable();
            $table->timestamps();

            $table->index(['conversation_id', 'survey_type']);
            $table->index(['rated_agent_user_id', 'survey_type']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('support_survey_responses');
        Schema::dropIfExists('support_survey_invites');
    }
};

