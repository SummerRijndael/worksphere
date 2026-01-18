<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::create('email_sync_logs', function (Blueprint $table) {
            $table->id();
            $table->foreignId('email_account_id')->constrained()->onDelete('cascade');
            $table->string('action'); // chunk_started, chunk_completed, error, sync_completed, seed_started, etc.
            $table->string('folder')->nullable();
            $table->json('details')->nullable(); // {offset, fetched_count, duration_ms, error_message, etc.}
            $table->timestamps();

            $table->index(['email_account_id', 'created_at']);
            $table->index(['action', 'created_at']);
        });
    }

    public function down(): void
    {
        Schema::dropIfExists('email_sync_logs');
    }
};
