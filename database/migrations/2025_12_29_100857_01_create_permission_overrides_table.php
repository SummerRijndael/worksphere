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
        Schema::create('permission_overrides', function (Blueprint $table) {
            $table->id();
            $table->uuid('public_id')->unique();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->string('permission');
            $table->enum('type', ['grant', 'block']);
            $table->enum('scope', ['global', 'team'])->default('global');
            $table->foreignId('team_id')->nullable()->constrained()->nullOnDelete();
            $table->boolean('is_temporary')->default(false);
            $table->timestamp('expires_at')->nullable();
            $table->enum('expiry_behavior', ['auto_revoke', 'grace_period'])->nullable();
            $table->unsignedSmallInteger('grace_period_days')->nullable();
            $table->text('reason');
            $table->foreignId('granted_by')->constrained('users')->cascadeOnDelete();
            $table->timestamp('approved_at')->nullable();
            $table->timestamp('revoked_at')->nullable();
            $table->foreignId('revoked_by')->nullable()->constrained('users')->nullOnDelete();
            $table->text('revoke_reason')->nullable();
            $table->timestamps();

            $table->index(['user_id', 'permission']);
            $table->index(['user_id', 'scope', 'team_id']);
            $table->index(['expires_at', 'is_temporary']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('permission_overrides');
    }
};
