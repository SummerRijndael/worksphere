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
        Schema::create('chat_participants', function (Blueprint $table) {
            // We need an ID for participants to have a public_id cleanly, or accept it's a pivot with metadata.
            // The incremental migration added public_id, so we should too.
            // HOWEVER, the original table didn't have 'id' primary key, it had composite primary.
            // Adding a 'public_id' to a pivot table without 'id' is fine.
            $table->ulid('public_id')->unique();

            $table->foreignId('chat_id')->constrained()->cascadeOnDelete();
            $table->foreignId('user_id')->constrained()->cascadeOnDelete();
            $table->foreignId('last_read_message_id')->nullable()->constrained('chat_messages')->nullOnDelete();
            $table->string('role')->default('member'); // member, admin, owner

            // Group Management
            $table->timestamp('left_at')->nullable()->index();
            $table->foreignId('kicked_by')->nullable()->constrained('users');

            $table->timestamps();

            // Composite primary key
            $table->primary(['chat_id', 'user_id']);

            // Indexes for efficient queries
            $table->index('user_id');
            $table->index('last_read_message_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('chat_participants');
    }
};
