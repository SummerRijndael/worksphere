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
        Schema::create('chats', function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('name')->nullable();
            $table->string('avatar')->nullable();
            $table->string('type')->default('dm'); // dm, group, team
            $table->foreignId('created_by')->nullable()->constrained('users')->nullOnDelete();
            $table->foreignId('team_id')->nullable()->unique()->constrained('teams')->cascadeOnDelete();
            $table->boolean('is_primary')->default(false);

            // Management
            $table->timestamp('marked_for_deletion_at')->nullable();
            $table->timestamp('last_activity_at')->nullable();

            $table->timestamps();
            $table->softDeletes();

            $table->index('type');
            $table->index('created_by');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chats');
    }
};
