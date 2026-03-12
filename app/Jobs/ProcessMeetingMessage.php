<?php

namespace App\Jobs;

use App\Events\Meetings\MeetingSignal;
use App\Models\MeetingMessage;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessMeetingMessage implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    public int $maxExceptions = 1;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected int $messageId
    ) {
        // Run on the high-priority meetings queue like WebRTC signaling
        $this->onQueue('meetings');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $message = MeetingMessage::with([
            'participant:id,user_id,public_id,metadata',
            'participant.user:id,name',
            'meeting',
            'media',
            'replyTo:id,participant_public_id,body,created_at',
            'threadRoot:id,participant_public_id,body,created_at',
            'pinnedByParticipant:id,user_id,public_id,metadata',
            'pinnedByParticipant.user:id,name',
        ])->withCount('replies')->find($this->messageId);

        if (! $message) {
            Log::error('[ProcessMeetingMessage] Message not found', [
                'message_id' => $this->messageId,
            ]);

            return;
        }

        if (! $message->meeting) {
            return;
        }

        // Broadcast to everyone in the meeting room
        broadcast(new MeetingSignal(
            $message->meeting,
            $message->participant_public_id, // sender
            'chat-message',                  // signalType
            [
                'id' => $message->id,
                'public_id' => $message->public_id,
                'participant_public_id' => $message->participant_public_id,
                'participant_name' => $message->participant?->display_name,
                'body' => $message->body,
                'temp_id' => $message->temp_id,
                'metadata' => $message->metadata,
                'attachments' => $message->toAttachmentPayload(),
                'reply_to_id' => $message->reply_to_message_id,
                'thread_root_id' => $message->thread_root_message_id,
                'reply_to' => $message->replyTo ? [
                    'id' => $message->replyTo->id,
                    'participant_public_id' => $message->replyTo->participant_public_id,
                    'body' => $message->replyTo->body,
                    'created_at' => $message->replyTo->created_at?->toIso8601String(),
                ] : null,
                'thread_root' => $message->threadRoot ? [
                    'id' => $message->threadRoot->id,
                    'participant_public_id' => $message->threadRoot->participant_public_id,
                    'body' => $message->threadRoot->body,
                    'created_at' => $message->threadRoot->created_at?->toIso8601String(),
                ] : null,
                'replies_count' => (int) ($message->replies_count ?? 0),
                'is_pinned' => (bool) $message->is_pinned,
                'pinned_at' => $message->pinned_at?->toIso8601String(),
                'pinned_by_participant_public_id' => $message->pinned_by_participant_public_id,
                'pinned_by_name' => $message->pinnedByParticipant?->display_name,
                'created_at' => $message->created_at?->toIso8601String(),
            ]
        ));
    }
}
