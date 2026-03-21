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
        Schema::create('support_skills', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique()->index();
            $table->string('name');
            $table->string('slug')->unique();
            $table->text('description')->nullable();
            $table->string('department')->nullable()->index();
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('priority')->default(100);
            $table->json('settings')->nullable();
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->timestamps();
        });

        Schema::create('support_skill_user', function (Blueprint $table) {
            $table->id();
            $table->foreignId('support_skill_id')->constrained('support_skills')->cascadeOnDelete();
            $table->foreignId('user_id')->constrained('users')->cascadeOnDelete();
            $table->string('membership_role')->default('agent')->index(); // team_lead|sme|qa|agent
            $table->boolean('is_primary')->default(false);
            $table->boolean('is_active')->default(true)->index();
            $table->unsignedSmallInteger('capacity')->nullable();
            $table->json('settings')->nullable();
            $table->timestamps();

            $table->unique(['support_skill_id', 'user_id'], 'support_skill_user_unique');
            $table->index(['user_id', 'membership_role'], 'support_skill_user_role_lookup');
        });

        Schema::table('support_conversations', function (Blueprint $table) {
            $table->foreignId('support_skill_id')
                ->nullable()
                ->after('assigned_to')
                ->constrained('support_skills')
                ->nullOnDelete();
            $table->string('routing_scope')->default('global')->after('support_skill_id')->index();
            $table->index(['support_skill_id', 'status'], 'support_conversation_skill_status_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('support_conversations', function (Blueprint $table) {
            $table->dropIndex('support_conversation_skill_status_idx');
            $table->dropConstrainedForeignId('support_skill_id');
            $table->dropColumn('routing_scope');
        });

        Schema::dropIfExists('support_skill_user');
        Schema::dropIfExists('support_skills');
    }
};

