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
        Schema::create('audit_logs', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();

            // Actor information
            $table->foreignId('user_id')->nullable()->constrained()->nullOnDelete();
            $table->string('user_name')->nullable();
            $table->string('user_email')->nullable();

            // Team context (optional)
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();

            // Audit details
            $table->string('action');
            $table->string('category');
            $table->string('severity')->default('info');

            // Auditable entity
            $table->nullableMorphs('auditable');

            // Change tracking
            $table->json('old_values')->nullable();
            $table->json('new_values')->nullable();
            $table->json('metadata')->nullable();

            // Request context
            $table->string('ip_address', 45)->nullable();
            $table->text('user_agent')->nullable();
            $table->string('url', 2048)->nullable();
            $table->string('method', 10)->nullable();

            // Timestamp (no updated_at - append only)
            $table->timestamp('created_at')->useCurrent();

            // Indexes for common queries
            // Note: nullableMorphs already creates an index on auditable_type + auditable_id
            $table->index(['user_id', 'created_at']);
            $table->index(['action', 'created_at']);
            $table->index(['category', 'created_at']);
            $table->index(['severity', 'created_at']);
            $table->index(['team_id', 'created_at']);
            $table->index('created_at');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('audit_logs');
    }
};
