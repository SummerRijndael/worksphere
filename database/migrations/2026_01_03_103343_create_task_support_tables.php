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
        // Task Templates
        Schema::create('task_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique()->index();
            $table->foreignId('team_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->string('default_priority')->default('medium');
            $table->decimal('default_estimated_hours', 8, 2)->nullable();
            $table->json('checklist_template')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['team_id', 'is_active']);
        });

        // Add template reference to tasks
        Schema::table('tasks', function (Blueprint $table) {
            $table->foreignId('task_template_id')
                ->nullable()
                ->after('parent_id')
                ->constrained('task_templates')
                ->onDelete('set null');
        });

        // Task Status History
        Schema::create('task_status_history', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_id')->constrained()->onDelete('cascade');
            $table->string('from_status')->nullable();
            $table->string('to_status');
            $table->text('notes')->nullable();
            $table->foreignId('changed_by')->constrained('users')->onDelete('cascade');
            $table->timestamp('created_at');

            $table->index(['task_id', 'created_at']);
        });

        // QA Check Templates
        Schema::create('qa_check_templates', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique()->index();
            $table->foreignId('team_id')->constrained()->onDelete('cascade');
            $table->string('name');
            $table->text('description')->nullable();
            $table->boolean('is_active')->default(true);
            $table->foreignId('created_by')->constrained('users')->onDelete('cascade');
            $table->timestamps();
            $table->softDeletes();

            $table->index(['team_id', 'is_active']);
        });

        // QA Check Items
        Schema::create('qa_check_items', function (Blueprint $table) {
            $table->id();
            $table->foreignId('qa_check_template_id')->constrained()->onDelete('cascade');
            $table->string('label');
            $table->text('description')->nullable();
            $table->unsignedInteger('order')->default(0);
            $table->boolean('is_required')->default(true);
            $table->timestamps();

            $table->index(['qa_check_template_id', 'order']);
        });

        // Task QA Reviews
        Schema::create('task_qa_reviews', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique()->index();
            $table->foreignId('task_id')->constrained()->onDelete('cascade');
            $table->foreignId('qa_check_template_id')->nullable()->constrained()->onDelete('set null');
            $table->foreignId('reviewer_id')->constrained('users')->onDelete('cascade');
            $table->string('status')->default('in_progress'); // in_progress, passed, failed
            $table->text('notes')->nullable();
            $table->timestamp('reviewed_at')->nullable();
            $table->timestamps();

            $table->index(['task_id', 'status']);
            $table->index(['reviewer_id', 'status']);
        });

        // Task QA Check Results
        Schema::create('task_qa_check_results', function (Blueprint $table) {
            $table->id();
            $table->foreignId('task_qa_review_id')->constrained()->onDelete('cascade');
            $table->foreignId('qa_check_item_id')->constrained()->onDelete('cascade');
            $table->boolean('passed')->default(false);
            $table->text('notes')->nullable();
            $table->timestamps();

            $table->unique(['task_qa_review_id', 'qa_check_item_id']);
        });

        // Task Comments
        Schema::create('task_comments', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique()->index();
            $table->foreignId('task_id')->constrained()->onDelete('cascade');
            $table->foreignId('user_id')->constrained()->onDelete('cascade');
            $table->text('content');
            $table->boolean('is_internal')->default(false);
            $table->timestamps();
            $table->softDeletes();

            $table->index(['task_id', 'created_at']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('task_comments');
        Schema::dropIfExists('task_qa_check_results');
        Schema::dropIfExists('task_qa_reviews');
        Schema::dropIfExists('qa_check_items');
        Schema::dropIfExists('qa_check_templates');
        Schema::dropIfExists('task_status_history');

        Schema::table('tasks', function (Blueprint $table) {
            $table->dropForeign(['task_template_id']);
            $table->dropColumn('task_template_id');
        });

        Schema::dropIfExists('task_templates');
    }
};
