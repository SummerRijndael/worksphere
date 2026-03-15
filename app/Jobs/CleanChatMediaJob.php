<?php

namespace App\Jobs;

use App\Models\Chat\Chat;
use App\Models\Chat\ChatMessage;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job to deeply clean up media associated with a chat and all its messages.
 * This ensures files are removed from disk/S3/R2 when a chat is purged.
 */
class CleanChatMediaJob implements ShouldQueue
{
    use Dispatchable, InteractsWithQueue, Queueable, SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(protected string|int $chatId)
    {
        $this->onQueue('cleanup');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $chat = Chat::find($this->chatId);

        if (!$chat) {
            Log::warning("[CleanChatMediaJob] Chat {$this->chatId} not found for cleanup.");
            return;
        }

        Log::info("[CleanChatMediaJob] Starting media cleanup for chat: {$chat->id}");

        // 1. Cleanup Message Media in Chunks
        // We use chunkById for memory efficiency even with large chats
        ChatMessage::where('chat_id', $chat->id)
            ->chunkById(500, function ($messages) {
                foreach ($messages as $message) {
                    // This triggers Spatie Media Library file removal via Eloquent
                    $message->clearMediaCollection('chat_attachments');
                }
            });

        // 2. Cleanup Chat-level Media
        $chat->clearMediaCollection('avatar');
        $chat->clearMediaCollection('chat_attachments'); // Just in case of group-level shared files

        Log::info("[CleanChatMediaJob] Completed media cleanup for chat: {$chat->id}");
    }

    /**
     * Handle job failure.
     */
    public function failed(\Throwable $exception): void
    {
        Log::error("[CleanChatMediaJob] Media cleanup failed for chat {$this->chatId}: " . $exception->getMessage());
    }
}
