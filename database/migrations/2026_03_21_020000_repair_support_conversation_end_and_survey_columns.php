<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        if (! Schema::hasColumn('support_conversations', 'survey_opt_out')) {
            Schema::table('support_conversations', function (Blueprint $table): void {
                $table->boolean('survey_opt_out')->default(false)->after('ai_enabled');
            });
        }

        if (! Schema::hasColumn('support_conversations', 'survey_opt_out_at')) {
            Schema::table('support_conversations', function (Blueprint $table): void {
                $table->timestamp('survey_opt_out_at')->nullable()->after('survey_opt_out');
            });
        }

        if (! Schema::hasColumn('support_conversations', 'ended_at')) {
            Schema::table('support_conversations', function (Blueprint $table): void {
                $table->timestamp('ended_at')->nullable()->after('closed_at');
            });
        }

        if (! Schema::hasColumn('support_conversations', 'ended_by_type')) {
            Schema::table('support_conversations', function (Blueprint $table): void {
                $table->string('ended_by_type', 20)->nullable()->index()->after('ended_at');
            });
        }

        if (! Schema::hasColumn('support_conversations', 'ended_by_user_id')) {
            Schema::table('support_conversations', function (Blueprint $table): void {
                $table->foreignId('ended_by_user_id')->nullable()->after('ended_by_type')->constrained('users')->nullOnDelete();
            });
        }

        if (! Schema::hasColumn('support_conversations', 'ended_by_name')) {
            Schema::table('support_conversations', function (Blueprint $table): void {
                $table->string('ended_by_name')->nullable()->after('ended_by_user_id');
            });
        }
    }

    public function down(): void
    {
        if (Schema::hasColumn('support_conversations', 'ended_by_user_id')) {
            Schema::table('support_conversations', function (Blueprint $table): void {
                $table->dropConstrainedForeignId('ended_by_user_id');
            });
        }

        if (Schema::hasColumn('support_conversations', 'ended_by_type')) {
            Schema::table('support_conversations', function (Blueprint $table): void {
                try {
                    $table->dropIndex(['ended_by_type']);
                } catch (\Throwable) {
                    // Ignore when the index does not exist.
                }
            });
        }

        $dropColumns = [];
        foreach ([
            'survey_opt_out',
            'survey_opt_out_at',
            'ended_at',
            'ended_by_type',
            'ended_by_name',
        ] as $column) {
            if (Schema::hasColumn('support_conversations', $column)) {
                $dropColumns[] = $column;
            }
        }

        if (! empty($dropColumns)) {
            Schema::table('support_conversations', function (Blueprint $table) use ($dropColumns): void {
                $table->dropColumn($dropColumns);
            });
        }
    }
};

