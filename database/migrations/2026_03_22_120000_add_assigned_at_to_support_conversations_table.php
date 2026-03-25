<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    public function up(): void
    {
        Schema::table('support_conversations', function (Blueprint $table): void {
            $table->timestamp('assigned_at')->nullable()->after('assigned_to')->index();
        });

        DB::table('support_conversations')
            ->select(['id', 'assigned_to', 'assigned_at', 'first_response_at', 'created_at'])
            ->whereNotNull('assigned_to')
            ->whereNull('assigned_at')
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    DB::table('support_conversations')
                        ->where('id', $row->id)
                        ->update([
                            'assigned_at' => $row->first_response_at ?? $row->created_at ?? now(),
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    public function down(): void
    {
        Schema::table('support_conversations', function (Blueprint $table): void {
            $table->dropIndex(['assigned_at']);
            $table->dropColumn('assigned_at');
        });
    }
};
