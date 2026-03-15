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
        $tables = config('chat.tables');

        Schema::create($tables['chats'], function (Blueprint $table) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->string('name')->nullable();
            $table->string('type')->default('dm');
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create($tables['messages'], function (Blueprint $table) use ($tables) {
            $table->id();
            $table->ulid('public_id')->unique();
            $table->foreignId('chat_id')->constrained($tables['chats'])->cascadeOnDelete();
            $table->unsignedBigInteger('user_id'); 
            $table->text('content')->nullable();
            $table->string('type')->default('user');
            $table->json('metadata')->nullable();
            $table->timestamps();
            $table->softDeletes();
        });

        Schema::create($tables['participants'], function (Blueprint $table) use ($tables) {
            $table->id();
            $table->foreignId('chat_id')->constrained($tables['chats'])->cascadeOnDelete();
            $table->unsignedBigInteger('user_id');
            $table->string('role')->default('member');
            $table->unsignedBigInteger('last_read_message_id')->nullable();
            $table->timestamps();

            $table->unique(['chat_id', 'user_id']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        $tables = config('chat.tables');

        Schema::dropIfExists($tables['participants']);
        Schema::dropIfExists($tables['messages']);
        Schema::dropIfExists($tables['chats']);
    }
};
