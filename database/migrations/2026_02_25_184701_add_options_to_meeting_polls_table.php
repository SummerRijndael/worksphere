<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (!Schema::hasColumn('meeting_polls', 'allow_multiple')) {
            Schema::table('meeting_polls', function (Blueprint $table) {
                $table->boolean('allow_multiple')->default(false)->after('is_active');
                $table->boolean('allow_change_vote')->default(false)->after('allow_multiple');
                $table->boolean('anonymous')->default(false)->after('allow_change_vote');
            });
        }

        // Drop old unique constraint (1 vote per participant per poll)
        // and replace with one that allows multiple options per participant
        Schema::table('meeting_poll_votes', function (Blueprint $table) {
            $table->dropForeign(['poll_id']);
            $table->dropUnique(['poll_id', 'participant_id']);
            $table->unique(['poll_id', 'participant_id', 'option_index']);
            $table->foreign('poll_id')->references('id')->on('meeting_polls')->onDelete('cascade');
        });
    }

    public function down(): void
    {
        Schema::table('meeting_polls', function (Blueprint $table) {
            $table->dropColumn(['allow_multiple', 'allow_change_vote', 'anonymous']);
        });

        Schema::table('meeting_poll_votes', function (Blueprint $table) {
            $table->dropUnique(['poll_id', 'participant_id', 'option_index']);
            $table->unique(['poll_id', 'participant_id']);
        });
    }
};
