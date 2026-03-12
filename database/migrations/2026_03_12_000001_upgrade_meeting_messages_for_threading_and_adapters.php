<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('meeting_messages', function (Blueprint $table) {
            $table->char('public_id', 26)->nullable()->after('id');
            $table->string('temp_id')->nullable()->after('body');
            $table->json('metadata')->nullable()->after('temp_id');
            $table->unsignedBigInteger('reply_to_message_id')->nullable()->after('metadata');
            $table->unsignedBigInteger('thread_root_message_id')->nullable()->after('reply_to_message_id');

            $table->foreign('reply_to_message_id')
                ->references('id')
                ->on('meeting_messages')
                ->nullOnDelete();
            $table->foreign('thread_root_message_id')
                ->references('id')
                ->on('meeting_messages')
                ->nullOnDelete();

            $table->index(['meeting_id', 'thread_root_message_id'], 'meeting_messages_meeting_thread_idx');
            $table->unique('public_id');
        });

        DB::table('meeting_messages')
            ->whereNull('public_id')
            ->orderBy('id')
            ->select('id')
            ->chunkById(200, function ($rows) {
                foreach ($rows as $row) {
                    DB::table('meeting_messages')
                        ->where('id', $row->id)
                        ->update(['public_id' => (string) Str::ulid()]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('meeting_messages', function (Blueprint $table) {
            $table->dropIndex('meeting_messages_meeting_thread_idx');
            $table->dropUnique(['public_id']);
            $table->dropForeign(['reply_to_message_id']);
            $table->dropForeign(['thread_root_message_id']);

            $table->dropColumn([
                'public_id',
                'temp_id',
                'metadata',
                'reply_to_message_id',
                'thread_root_message_id',
            ]);
        });
    }
};
