<?php

use Illuminate\Database\Migrations\Migration;
use Illuminate\Database\Schema\Blueprint;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Schema;

return new class extends Migration
{
    /**
     * Run the migrations.
     */
    public function up(): void
    {
        Schema::table('support_conversations', function (Blueprint $table): void {
            $table->string('chat_state', 20)->default('new')->after('status')->index();
            $table->string('assignment_state', 20)->default('unassigned')->after('chat_state')->index();
            $table->string('resolution_marker', 20)->default('unresolved')->after('assignment_state')->index();
            $table->string('conversation_type', 20)->default('inquiry')->after('resolution_marker')->index();
            $table->string('end_reason', 30)->nullable()->after('ended_by_name')->index();
        });

        DB::table('support_conversations')
            ->select([
                'id',
                'status',
                'assigned_to',
                'resolved_at',
                'closed_at',
                'ended_at',
                'ended_by_type',
            ])
            ->orderBy('id')
            ->chunkById(500, function ($rows): void {
                foreach ($rows as $row) {
                    $status = (string) ($row->status ?? 'open');
                    $isEnded = in_array($status, ['resolved', 'closed'], true)
                        || ! empty($row->closed_at)
                        || ! empty($row->ended_at);
                    $isResolved = $status === 'resolved' || ! empty($row->resolved_at);

                    $chatState = $isEnded ? 'chat_ended' : 'new';
                    $assignmentState = (! empty($row->assigned_to) || $status === 'assigned') ? 'assigned' : 'unassigned';
                    $resolutionMarker = $isResolved ? 'resolved' : 'unresolved';

                    $endReason = null;
                    if ($isEnded) {
                        $endedByType = (string) ($row->ended_by_type ?? '');
                        if ($endedByType === 'agent') {
                            $endReason = 'agent_ended';
                        } elseif (in_array($endedByType, ['customer', 'guest'], true)) {
                            $endReason = 'user_ended';
                        } elseif ($endedByType === 'system') {
                            $endReason = 'system_ended';
                        } else {
                            $endReason = 'system_ended';
                        }
                    }

                    DB::table('support_conversations')
                        ->where('id', $row->id)
                        ->update([
                            'chat_state' => $chatState,
                            'assignment_state' => $assignmentState,
                            'resolution_marker' => $resolutionMarker,
                            'conversation_type' => 'inquiry',
                            'end_reason' => $endReason,
                            'updated_at' => now(),
                        ]);
                }
            });
    }

    /**
     * Reverse the migrations.
     */
    public function down(): void
    {
        Schema::table('support_conversations', function (Blueprint $table): void {
            $table->dropIndex(['chat_state']);
            $table->dropIndex(['assignment_state']);
            $table->dropIndex(['resolution_marker']);
            $table->dropIndex(['conversation_type']);
            $table->dropIndex(['end_reason']);
            $table->dropColumn([
                'chat_state',
                'assignment_state',
                'resolution_marker',
                'conversation_type',
                'end_reason',
            ]);
        });
    }
};
