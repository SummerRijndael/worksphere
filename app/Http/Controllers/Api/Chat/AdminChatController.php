<?php

namespace App\Http\Controllers\Api\Chat;

use App\Http\Controllers\Controller;
use App\Models\Chat\Chat;
use App\Services\Chat\GroupChatService;
use Illuminate\Http\JsonResponse;

class AdminChatController extends Controller
{
    public function __construct(
        protected GroupChatService $groupChatService
    ) {}

    /**
     * List all chats flagged for deletion.
     */
    public function storedFlaggedChats(): JsonResponse
    {
        $this->authorize('chats.manage');

        $chats = Chat::query()
            ->whereNotNull('marked_for_deletion_at')
            ->with(['creator:id,name,public_id', 'owner:id,name,public_id']) // Assuming owner relation helper works or returns collection
            ->latest('marked_for_deletion_at')
            ->get()
            ->map(function ($chat) {
                // Determine owner safely
                $owner = $chat->participants->firstWhere('pivot.role', 'owner');

                return [
                    'id' => $chat->public_id,
                    'name' => $chat->name,
                    'type' => $chat->type,
                    'marked_for_deletion_at' => $chat->marked_for_deletion_at->toIso8601String(),
                    'participants_count' => $chat->participants->count(),
                    'created_by' => $chat->creator->name ?? 'System',
                    'owner_name' => $owner->name ?? 'Unknown',
                ];
            });

        return response()->json(['data' => $chats]);
    }

    /**
     * Restore a flagged chat.
     */
    public function restore(Chat $chat): JsonResponse
    {
        $this->authorize('chats.manage');

        if (! $chat->marked_for_deletion_at) {
            return response()->json(['message' => 'Chat is not marked for deletion.'], 400);
        }

        $this->groupChatService->restoreMarkedGroup($chat);

        return response()->json(['message' => 'Chat restored successfully.']);
    }
}
