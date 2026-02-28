<?php

namespace App\Jobs;

use App\Events\Meetings\MeetingSignal;
use App\Models\MeetingMessage;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Queue\Queueable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

class ProcessMeetingMessage implements ShouldQueue
{
    use InteractsWithQueue, Queueable, SerializesModels;

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
        $message = MeetingMessage::with(['meeting'])->find($this->messageId);

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
                'participant_public_id' => $message->participant_public_id,
                'body' => $message->body,
                'created_at' => $message->created_at?->toIso8601String(),
            ]
        ));
    }
}
