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
        Schema::create('role_change_requests', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->enum('type', ['role_title_change', 'role_permission_change', 'role_create', 'role_delete']);
            $table->unsignedBigInteger('target_role_id')->nullable();
            $table->json('requested_changes');
            $table->text('reason');
            $table->foreignId('requested_by')->constrained('users')->cascadeOnDelete();
            $table->enum('status', ['pending', 'approved', 'rejected', 'expired'])->default('pending');
            $table->unsignedTinyInteger('required_approvals')->default(2);
            $table->timestamp('expires_at');
            $table->timestamp('completed_at')->nullable();
            $table->timestamps();

            $table->foreign('target_role_id')
                ->references('id')
                ->on('roles')
                ->nullOnDelete();

            $table->index(['status', 'expires_at']);
            $table->index('requested_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('role_change_requests');
    }
};
