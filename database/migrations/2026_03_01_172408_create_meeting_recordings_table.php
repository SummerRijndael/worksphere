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
        Schema::create('meeting_recordings', function (Blueprint $table) {
            $table->uuid('id')->primary();

            // Link to the meeting in our system
            $table->foreignId('meeting_id')->constrained('meetings')->cascadeOnDelete();

            // The RealtimeKit meeting ID used at the Cloudflare side (cf_meeting_id stored on meeting)
            $table->string('cf_meeting_id');

            // Recording ID returned by Cloudflare RealtimeKit when the recording starts
            $table->string('cf_recording_id')->nullable();

            // Who started the recording (host/co-host)
            $table->foreignId('started_by')->nullable()->constrained('users')->nullOnDelete();

            // Recording lifecycle
            $table->enum('status', ['pending', 'recording', 'processing', 'completed', 'failed'])
                  ->default('pending');

            // Download URL provided by Cloudflare after recording completes (valid ~7 days)
            $table->string('download_url', 1024)->nullable();

            // Duration in seconds (set when recording completes)
            $table->unsignedInteger('duration_seconds')->nullable();

            // Extra metadata from Cloudflare (file size, codec, etc.)
            $table->json('cf_metadata')->nullable();

            $table->timestamps();
            $table->index(['meeting_id', 'status']);
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::dropIfExists('meeting_recordings');
    }
};
