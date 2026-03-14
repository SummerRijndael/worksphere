<?php

namespace App\Jobs;

use App\Models\User;
use App\Services\Chat\ChatCache;
use App\Services\Chat\ChatEngine;
use App\Services\Chat\ChatEvents;
use Illuminate\Bus\Queueable;
use Illuminate\Contracts\Queue\ShouldQueue;
use Illuminate\Foundation\Bus\Dispatchable;
use Illuminate\Queue\InteractsWithQueue;
use Illuminate\Queue\SerializesModels;
use Illuminate\Support\Facades\Log;

/**
 * Job to update a user's unread badge count and broadcast it.
 * This is decoupled from ProcessChatMessage to ensure fast message delivery.
 */
class UpdateUserBadgeJob implements ShouldQueue
{
    use Dispatchable;
    use InteractsWithQueue;
    use Queueable;
    use SerializesModels;

    /**
     * Create a new job instance.
     */
    public function __construct(
        protected int $userId
    ) {
        // Run on default or notifications queue to avoid blocking high-priority chat delivery
        $this->onQueue('notifications');
    }

    /**
     * Execute the job.
     */
    public function handle(): void
    {
        $user = User::find($this->userId, ['*']);
        if (!$user) {
            return;
        }

        // Calculate unread count (SSOT)
        $unreadCount = ChatEngine::unreadFor($user);

        // Update Redis cache
        ChatCache::put($user->id, $unreadCount);

        // Broadcast to user's private channel
        ChatEvents::unreadBadge($user, $unreadCount);

        Log::debug('[UpdateUserBadgeJob] Badge updated for user', [
            'user_id' => $user->id,
            'unread_count' => $unreadCount,
        ]);
    }
}
