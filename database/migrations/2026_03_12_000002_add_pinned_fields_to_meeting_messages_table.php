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
        Schema::table('meeting_messages', function (Blueprint $table) {
            $table->boolean('is_pinned')->default(false)->after('thread_root_message_id');
            $table->timestamp('pinned_at')->nullable()->after('is_pinned');
            $table->char('pinned_by_participant_public_id', 26)->nullable()->after('pinned_at');

            $table->index(['meeting_id', 'is_pinned', 'pinned_at'], 'meeting_messages_meeting_pinned_idx');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('meeting_messages', function (Blueprint $table) {
            $table->dropIndex('meeting_messages_meeting_pinned_idx');
            $table->dropColumn([
                'is_pinned',
                'pinned_at',
                'pinned_by_participant_public_id',
            ]);
        });
    }
};
