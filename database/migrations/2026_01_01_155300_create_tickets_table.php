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
        Schema::create('tickets', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->string('ticket_number')->nullable()->index();
            $table->string('slug', 100)->unique()->nullable();

            // Core fields
            $table->string('title', 255);
            $table->longText('description')->nullable();

            // Status and categorization
            $table->string('status')->default('open');
            $table->string('priority')->default('medium');
            $table->string('type')->default('task');
            $table->json('tags')->nullable();

            // Relationships
            $table->foreignId('reporter_id')->nullable()->constrained('users')->cascadeOnDelete();
            $table->string('guest_name')->nullable();
            $table->string('guest_email')->nullable();

            $table->foreignId('assigned_to')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->constrained('teams')->nullOnDelete();
            $table->foreignId('parent_id')->nullable()->constrained('tickets')->nullOnDelete();

            // SLA Fields
            $table->unsignedInteger('sla_response_hours')->nullable();
            $table->unsignedInteger('sla_resolution_hours')->nullable();
            $table->timestamp('first_response_at')->nullable();
            $table->boolean('sla_breached')->default(false);

            // Deadline Fields
            $table->timestamp('due_date')->nullable();
            $table->timestamp('deadline_reminded_at')->nullable();

            // Status timestamps
            $table->timestamp('resolved_at')->nullable();
            $table->timestamp('closed_at')->nullable();
            $table->timestamp('archived_at')->nullable();
            $table->string('archive_reason')->nullable();

            $table->timestamps();

            // Indexes for common queries
            $table->index('status');
            $table->index('priority');
            $table->index('assigned_to');
            $table->index('reporter_id');
            $table->index('team_id');
            $table->index('due_date');
            $table->index('sla_breached');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('tickets');
    }
};
