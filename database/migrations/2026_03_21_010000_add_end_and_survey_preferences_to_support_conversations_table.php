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
        Schema::table('support_conversations', function (Blueprint $table) {
            $table->boolean('survey_opt_out')->default(false)->after('ai_enabled');
            $table->timestamp('survey_opt_out_at')->nullable()->after('survey_opt_out');

            $table->timestamp('ended_at')->nullable()->after('closed_at');
            $table->string('ended_by_type', 20)->nullable()->index()->after('ended_at'); // agent|customer|guest|system
            $table->foreignId('ended_by_user_id')->nullable()->after('ended_by_type')->constrained('users')->nullOnDelete();
            $table->string('ended_by_name')->nullable()->after('ended_by_user_id');
        });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('support_conversations', function (Blueprint $table) {
            $table->dropIndex(['ended_by_type']);
            $table->dropConstrainedForeignId('ended_by_user_id');
            $table->dropColumn([
                'survey_opt_out',
                'survey_opt_out_at',
                'ended_at',
                'ended_by_type',
                'ended_by_name',
            ]);
        });
    }
};
