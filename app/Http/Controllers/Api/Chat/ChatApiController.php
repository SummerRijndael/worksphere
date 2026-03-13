<?php

namespace App\Http\Controllers\Api\Chat;

use App\Http\Controllers\Controller;
use App\Models\Chat\Chat;
use App\Models\Chat\ChatInvite;
use App\Models\Chat\ChatMessage;
use App\Models\User;
use App\Services\Chat\ChatConnectionManager;
use App\Services\Chat\ChatEngine;
use App\Services\Chat\ChatMediaService;
use App\Services\Chat\ChatTransport;
use App\Services\Chat\PresenceService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ChatApiController extends Controller
{
    public function __construct(
        protected \App\Services\Chat\GroupChatService $groupChatService
    ) {}

    private const SEND_WINDOW_SECONDS = 60;

    private const SEND_MAX_PER_WINDOW = 20;

    /**
     * @var list<string>
     */
    private const REACTION_KEYS = [
        'like',
        'laugh',
        'hundred',
        'sad',
        'love',
        'angry',
        'scared',
        'care',
    ];

    /**
     * Find a chat by ID and verify the user is a participant.
     */
    protected function findChatOrFail(int $chatId): Chat
    {
        abort_if($chatId <= 0, 404, 'Chat not found.');

        $chat = Chat::with([
            'participants' => function ($query) {
                $query->select('users.id', 'users.name', 'users.username', 'users.public_id')
                    ->with('media');
            },
            'lastMessage.user:id,name,public_id',
            'lastMessage.media',
            'team:id,owner_id',
        ])->findOrFail($chatId);

        abort_if(! $chat->participants->contains(Auth::id()), 404);

        return $chat;
    }

    /**
     * Get the role of a user in a chat.
     */
    protected function participantRole(Chat $chat, int $userId): ?string
    {
        return DB::table('chat_participants')
            ->where('chat_id', $chat->id)
            ->where('user_id', $userId)
            ->value('role');
    }

    /**
     * Map a chat to a standardized array format.
     * Includes participant presence, role, and last message details.
     *
     * @return array{
     *   id: string,
     *   public_id: string,
     *   name: string|null,
     *   type: string,
     *   avatar_url: string|null,
     *   created_at: string|null,
     *   participants: array<int, array{id: string, name: string, public_id: string, avatar: string|null, role: string|null, is_online: bool, presence_status: string}>,
     *   last_message: array{
     *     id: string|int,
     *     user_name: string|null,
     *     content: string|null,
     *     created_at: string|null,
     *     has_media: bool,
     *     preview?: string
     *   }|null,
     *   updated_at: string|null,
     *   team_owner_id: int|null
     * }
     */
    protected function mapChat(Chat $chat, array $activeIds = []): array
    {
        $viewer = Auth::user();
        $last = $this->resolveVisibleLastMessage($chat, $viewer);
        $activeLookup = collect($activeIds)->mapWithKeys(fn ($id) => [$id => true]);
        $presence = app(PresenceService::class);

        return [
            'id' => $chat->public_id, // Expose Public ID as the main identifier
            'public_id' => $chat->public_id,
            'name' => $chat->name ?? null,
            'type' => $chat->type ?? 'dm',
            'avatar_url' => $chat->avatar_url,
            'created_at' => $chat->created_at?->toIso8601String(),
            'participants' => $chat->participants->map(function ($p) use ($activeLookup, $presence) {
                $status = $presence->presenceStatus($p->id);
                if ($status === 'offline' && $activeLookup->has($p->id)) {
                    $status = 'online';
                }

                return [
                    'id' => $p->public_id, // Use public_id for frontend
                    'name' => $p->name,
                    'public_id' => $p->public_id,
                    'avatar' => $p->avatar_url, // Use accessor that handles media library
                    'role' => $p->pivot->role ?? null,
                    'is_online' => $status !== 'offline',
                    'presence_status' => $status,
                ];
            })->values(),
            'last_message' => $last ? [
                'id' => $last->public_id ?? $last->id, // Prefer Public ID if available (migrated messages)
                'user_name' => $last->user?->name,
                'content' => $last->content,
                'created_at' => $last->created_at?->toIso8601String(),
                'has_media' => $last->media?->isNotEmpty() ?? false,
                'preview' => $this->buildLastMessagePreview($last),
            ] : null,
            'updated_at' => $chat->updated_at?->toIso8601String(),
            'team_owner_id' => $chat->team?->owner_id,
        ];
    }

    protected function buildLastMessagePreview(ChatMessage $message): string
    {
        $metadata = is_array($message->metadata) ? $message->metadata : [];
        if (($metadata['is_deleted'] ?? false) === true) {
            return 'Message deleted';
        }

        $content = trim(preg_replace('/\s+/', ' ', (string) ($message->content ?? '')) ?? '');
        if ($content !== '') {
            return Str::limit($content, 120, '...');
        }

        if (
            $message->type === 'system'
            && ($metadata['system_type'] ?? null) === 'call_event'
        ) {
            $event = (string) ($metadata['event'] ?? '');
            $callType = (string) ($metadata['type'] ?? 'video');

            return match ($event) {
                'started' => sprintf(
                    '%s started a %s call',
                    (string) ($metadata['user_name'] ?? 'Someone'),
                    $callType
                ),
                'ended' => $this->buildEndedCallPreview($metadata),
                'missed', 'no_answer' => $this->buildMissedCallPreview($metadata, $callType),
                default => 'Call update',
            };
        }

        return ($message->media?->isNotEmpty() ?? false) ? '📎 Attachment' : '';
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function buildEndedCallPreview(array $metadata): string
    {
        $duration = (int) ($metadata['duration'] ?? 0);
        if ($duration <= 0) {
            return 'Call ended';
        }

        $minutes = intdiv($duration, 60);
        $seconds = $duration % 60;

        return sprintf('Call ended (%d:%02d)', $minutes, $seconds);
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function buildMissedCallPreview(array $metadata, string $callType): string
    {
        $callerName = trim((string) ($metadata['caller_name'] ?? ''));
        if ($callerName === '') {
            return sprintf('Missed %s call', $callType);
        }

        return sprintf('Missed %s call from %s', $callType, $callerName);
    }

    /**
     * Check if user can manage a group chat.
     */
    protected function canManageGroup(Chat $chat, int $userId): bool
    {
        $role = $this->participantRole($chat, $userId);
        $teamOwnerId = $chat->team?->owner_id;

        return in_array($role, ['owner', 'admin'], true)
            || ($teamOwnerId && $teamOwnerId === $userId);
    }

    /**
     * Check if user is owner or team owner.
     */
    protected function isOwnerOrTeamOwner(Chat $chat, int $userId): bool
    {
        $role = $this->participantRole($chat, $userId);
        $teamOwnerId = $chat->team?->owner_id;

        return ($role === 'owner') || ($teamOwnerId && $teamOwnerId === $userId);
    }

    /**
     * Enforce rate limit for sending messages.
     */
    protected function enforceRateLimit(int $userId): void
    {
        $key = "chat_rate_limit:{$userId}";
        $count = (int) Cache::get($key, 0);

        if ($count >= self::SEND_MAX_PER_WINDOW) {
            abort(429, 'Too many messages. Please wait before sending more.');
        }

        Cache::put($key, $count + 1, self::SEND_WINDOW_SECONDS);
    }

    // =========================================================================
    // Chat List & Details
    // =========================================================================

    /**
     * List all chats for the authenticated user.
     */
    public function index(): JsonResponse
    {
        $userId = Auth::id();
        $activeIds = app(PresenceService::class)->getActiveUserIds();

        $chats = Auth::user()
            ->chats()
            ->with([
                'participants' => function ($query) {
                    $query->select('users.id', 'users.name', 'users.username', 'users.public_id')
                        ->with('media');
                },
                'lastMessage.user:id,name,public_id',
            ])
            ->orderByDesc('updated_at')
            ->get();

        $data = $chats->map(fn ($chat) => $this->mapChat($chat, $activeIds))->values();

        return response()->json(['data' => $data]);
    }

    /**
     * Get a specific chat.
     */
    public function show(Chat $chat): JsonResponse
    {
        abort_if(! $chat->participants->contains(Auth::id()), 404);
        $activeIds = app(PresenceService::class)->getActiveUserIds();

        return response()->json([
            'data' => $this->mapChat($chat, $activeIds),
        ]);
    }

    /**
     * Heartbeat to keep connection alive.
     */
    public function heartbeat(Chat $chat): JsonResponse
    {
        abort_if(! $chat->participants->contains(Auth::id()), 403);

        app(ChatConnectionManager::class)->heartbeat($chat->id, Auth::id());

        return response()->json(['status' => 'ok']);
    }

    // =========================================================================
    // Messages
    // =========================================================================

    /**
     * Get messages for a chat.
     */
    public function messages(Request $request, Chat $chat): JsonResponse
    {
        abort_if(! $chat->participants->contains(Auth::id()), 404);

        $beforePublicId = $request->input('before');
        $limit = max(1, min(50, $request->integer('limit', 25)));

        $engine = ChatEngine::for($chat, Auth::user());

        if ($beforePublicId) {
            // Resolve public_id (ULID) to internal id (int)
            $beforeId = ChatMessage::where('public_id', $beforePublicId)
                ->where('chat_id', $chat->id)
                ->value('id');

            if ($beforeId) {
                $messages = $engine->loadMore($beforeId, $limit);
            } else {
                // Invalid cursor provided, return empty
                $messages = [];
            }
        } else {
            $messages = $engine->loadMessages($limit);
        }

        $hasMore = false;
        if (! empty($messages)) {
            // Messages are ordered Oldest -> Newest (due to reverse() in normalize)
            // So index 0 is the oldest fetched message
            $oldestPublicId = $messages[0]['id'];

            // Resolve to internal ID
            $oldestInternalId = ChatMessage::where('public_id', $oldestPublicId)
                ->where('chat_id', $chat->id)
                ->value('id');

            if ($oldestInternalId) {
                $hasMoreQuery = ChatMessage::query()
                    ->where('chat_id', $chat->id)
                    ->where('id', '<', $oldestInternalId);
                $hasMore = $this->applyViewerVisibilityScope($hasMoreQuery, Auth::user())->exists();
            }
        }

        if (app()->isLocal()) {
            \Illuminate\Support\Facades\Log::info('Chat pagination request', [
                'chat_id' => $chat->id,
                'before_public_id' => $beforePublicId,
                'resolved_id' => $beforeId ?? null,
                'limit' => $limit,
                'sql_limit_applied' => $limit, // Confirmed usage in engine
            ]);
        }

        return response()->json([
            'data' => $messages,
            'has_more' => $hasMore,
        ]);
    }

    /**
     * Search messages within a chat.
     */
    public function searchMessages(Request $request, Chat $chat): JsonResponse
    {
        abort_if(! $chat->participants->contains(Auth::id()), 404);

        $query = trim($request->input('q', ''));
        abort_if(strlen($query) < 2, 422, 'Query must be at least 2 characters.');

        // Use Scout search with chat_id filter
        $results = ChatMessage::search($query)
            ->where('chat_id', $chat->id)
            ->take(50)
            ->get();
        $viewerPublicId = strtolower(trim((string) Auth::user()?->public_id));
        if ($viewerPublicId !== '') {
            $results = $results->filter(function (ChatMessage $message) use ($viewerPublicId) {
                $metadata = is_array($message->metadata) ? $message->metadata : [];
                $hiddenFor = is_array($metadata['hidden_for_user_public_ids'] ?? null)
                    ? $metadata['hidden_for_user_public_ids']
                    : [];
                $normalized = array_values(array_unique(array_filter(
                    array_map(static fn ($id) => strtolower((string) $id), $hiddenFor),
                    static fn (string $id) => $id !== ''
                )));

                return ! in_array($viewerPublicId, $normalized, true);
            })->values();
        }

        $data = $results->map(fn (ChatMessage $m) => [
            'id' => $m->public_id,
            'content' => Str::limit($m->content, 150),
            'user_name' => $m->user?->name ?? 'Unknown',
            'user_avatar' => $m->user?->avatar_url,
            'created_at' => $m->created_at?->toIso8601String(),
        ]);

        return response()->json(['data' => $data]);
    }

    /**
     * Update chat settings (Group only).
     */
    public function update(Request $request, Chat $chat): JsonResponse
    {
        abort_unless($chat->type === Chat::TYPE_GROUP, 400, 'Only group chats can be updated.');

        $validated = $request->validate([
            'name' => 'nullable|string|max:255',
            'avatar' => 'nullable|image|max:5120', // 5MB
        ]);

        $updatedChat = $this->groupChatService->updateGroupSettings(
            $chat,
            Auth::user(),
            $validated
        );

        return response()->json([
            'data' => $this->mapChat($updatedChat),
            'message' => 'Group settings updated successfully.',
        ]);
    }

    /**
     * Get messages around a specific message (for jump-to-message).
     */
    public function messagesAround(Request $request, Chat $chat, string $messagePublicId): JsonResponse
    {
        abort_if(! $chat->participants->contains(Auth::id()), 404);

        $targetQuery = ChatMessage::query()
            ->where('public_id', $messagePublicId)
            ->where('chat_id', $chat->id);
        $target = $this->applyViewerVisibilityScope($targetQuery, Auth::user())->first();

        if (! $target) {
            return response()->json(['message' => 'Message not found.'], 404);
        }

        // Get 15 messages before
        $beforeQuery = ChatMessage::query()
            ->where('chat_id', $chat->id)
            ->where('id', '<', $target->id)
            ->with(['user:id,name,public_id', 'replyTo.user:id,name,public_id', 'media'])
            ->orderByDesc('id')
            ->take(15);
        $before = $this->applyViewerVisibilityScope($beforeQuery, Auth::user())
            ->get()
            ->reverse()
            ->values();

        // Get 15 messages after
        $afterQuery = ChatMessage::query()
            ->where('chat_id', $chat->id)
            ->where('id', '>', $target->id)
            ->with(['user:id,name,public_id', 'replyTo.user:id,name,public_id', 'media'])
            ->orderBy('id')
            ->take(15);
        $after = $this->applyViewerVisibilityScope($afterQuery, Auth::user())->get();

        // Load target with relations
        $target->load(['user:id,name,public_id', 'replyTo.user:id,name,public_id', 'media']);

        // Combine: before + target + after
        $messages = $before->push($target)->concat($after);

        $engine = ChatEngine::for($chat, Auth::user());
        $formatted = $messages->map(fn ($m) => $engine->formatSingleMessage($m))->values();

        return response()->json([
            'data' => $formatted,
            'target_id' => $messagePublicId,
            'has_more_before' => $this->applyViewerVisibilityScope(
                ChatMessage::query()->where('chat_id', $chat->id)
                ->where('id', '<', $before->first()?->id ?? $target->id)
                , Auth::user()
            )->exists(),
            'has_more_after' => $this->applyViewerVisibilityScope(
                ChatMessage::query()->where('chat_id', $chat->id)
                ->where('id', '>', $after->last()?->id ?? $target->id)
                , Auth::user()
            )->exists(),
        ]);
    }

    /**
     * Send a text message.
     */
    public function send(Request $request, Chat $chat): JsonResponse
    {
        abort_if(! $chat->participants->contains(Auth::id()), 404);
        $this->enforceRateLimit(Auth::id());

        $validated = $request->validate([
            'content' => ['nullable', 'string', 'max:'.ChatEngine::MAX_MESSAGE_LENGTH],
            'reply_to' => ['nullable', 'string'],
            'temp_id' => ['nullable', 'string', 'max:255'],
            'metadata' => ['nullable', 'array'],
        ]);

        if (empty($validated['content']) && empty($validated['metadata'])) {
            abort(422, 'Message cannot be empty.');
        }

        $replyPublicId = $validated['reply_to'] ?? null;
        $tempId = $validated['temp_id'] ?? (string) Str::uuid();
        $metadata = $validated['metadata'] ?? null;
        $replyId = null;

        if ($replyPublicId) {
            $replyMessage = ChatMessage::where('public_id', $replyPublicId)->first(['id', 'chat_id']);

            if ($replyMessage && $replyMessage->chat_id === $chat->id) {
                $replyId = $replyMessage->id;
            } else {
                abort(422, 'Reply target not found in this chat.');
            }
        }

        $msg = ChatEngine::for($chat, Auth::user())
            ->send((string) ($validated['content'] ?? ''), [], $replyId, $metadata);

        // Queue the message for broadcasting (async)
        ChatTransport::queueBroadcast($msg, $tempId);

        return response()->json([
            'data' => ChatEngine::normalizeOne($msg),
        ]);
    }

    /**
     * Send a message with file attachments.
     */
    public function upload(Request $request, Chat $chat): JsonResponse
    {
        abort_if(! $chat->participants->contains(Auth::id()), 404);
        $this->enforceRateLimit(Auth::id());

        $files = $request->file('files', []);
        if ($files instanceof UploadedFile) {
            $files = [$files];
        }

        $mediaMetadata = $request->input('media_metadata', []);
        if (is_string($mediaMetadata)) {
            $decoded = json_decode($mediaMetadata, true);
            $mediaMetadata = is_array($decoded) ? $decoded : [];
        } elseif (! is_array($mediaMetadata)) {
            $mediaMetadata = [];
        }

        $replyPublicId = $request->filled('reply_to') ? (string) $request->input('reply_to') : null;
        $replyId = null;

        if (empty($files)) {
            return response()->json(['message' => 'No files were uploaded.'], 422);
        }

        if ($replyPublicId) {
            $replyMessage = ChatMessage::where('public_id', $replyPublicId)->first(['id', 'chat_id']);

            if ($replyMessage && $replyMessage->chat_id === $chat->id) {
                $replyId = $replyMessage->id;
            } else {
                abort(422, 'Reply target not found in this chat.');
            }
        }

        try {
            app(ChatMediaService::class)->validateFiles($files, $chat);
        } catch (\InvalidArgumentException $e) {
            return response()->json(['message' => $e->getMessage()], 422);
        }

        $msg = ChatEngine::for($chat, Auth::user())
            ->send((string) $request->input('content', ''), $files, $replyId, null, $mediaMetadata);

        return response()->json([
            'data' => ChatEngine::normalizeOne($msg),
        ]);
    }

    /**
     * Edit an existing message authored by the current user.
     */
    public function updateMessage(Request $request, Chat $chat, string $messagePublicId): JsonResponse
    {
        abort_if(! $chat->participants->contains(Auth::id()), 404);

        $message = $this->resolveChatMessage($chat, $messagePublicId);
        if (! $message) {
            return response()->json(['message' => 'Message not found.'], 404);
        }

        $actor = Auth::user();
        $isOwner = (int) $message->user_id === (int) $actor->id
            || strtolower((string) ($message->user?->public_id ?? '')) === strtolower((string) $actor->public_id);
        if (! $isOwner) {
            return response()->json(['message' => 'Only the original sender can edit this message.'], 403);
        }
        if ($message->type !== 'user') {
            return response()->json(['message' => 'Only user messages can be edited.'], 422);
        }

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:'.ChatEngine::MAX_MESSAGE_LENGTH],
        ]);
        $content = trim((string) $validated['content']);
        if ($content === '') {
            return response()->json(['message' => 'Message body cannot be empty.'], 422);
        }

        $metadata = is_array($message->metadata) ? $message->metadata : [];
        if (($metadata['is_deleted'] ?? false) === true) {
            return response()->json(['message' => 'Deleted messages cannot be edited.'], 422);
        }

        if ($content === (string) ($message->content ?? '')) {
            return response()->json([
                'data' => ChatEngine::normalizeOne($message->fresh([
                    'user:id,public_id,name',
                    'media',
                    'replyTo.user:id,public_id,name',
                ])),
            ]);
        }

        $history = is_array($metadata['edit_history'] ?? null) ? $metadata['edit_history'] : [];
        $history[] = [
            'previous_content' => (string) ($message->content ?? ''),
            'edited_at' => now()->toIso8601String(),
            'edited_by_user_public_id' => (string) $actor->public_id,
            'edited_by_user_name' => (string) $actor->name,
        ];
        $metadata['edit_history'] = array_values(array_slice($history, -100));
        $metadata['is_edited'] = true;
        $metadata['edited_at'] = now()->toIso8601String();
        $metadata['edited_by_user_public_id'] = (string) $actor->public_id;
        $metadata['edited_by_user_name'] = (string) $actor->name;
        $metadata['edit_count'] = count($metadata['edit_history']);

        $message->forceFill([
            'content' => $content,
            'metadata' => $metadata,
        ])->save();

        $message = $message->fresh([
            'user:id,public_id,name',
            'media',
            'replyTo.user:id,public_id,name',
            'chat',
        ]);

        \App\Services\Chat\ChatEvents::messageUpdated($message, $chat->type ?? 'dm');

        return response()->json([
            'data' => ChatEngine::normalizeOne($message),
        ]);
    }

    /**
     * Delete/unsend message. Scope:
     * - `all`: soft-delete message for everyone (content/attachments removed)
     * - `me`: hide message only for current user
     */
    public function deleteMessage(Request $request, Chat $chat, string $messagePublicId): JsonResponse
    {
        abort_if(! $chat->participants->contains(Auth::id()), 404);

        $message = $this->resolveChatMessage($chat, $messagePublicId);
        if (! $message) {
            return response()->json(['message' => 'Message not found.'], 404);
        }

        $actor = Auth::user();
        $validated = $request->validate([
            'scope' => 'nullable|string|in:me,all',
        ]);
        $scope = (string) ($validated['scope'] ?? 'all');

        $metadata = is_array($message->metadata) ? $message->metadata : [];
        $actorPublicId = strtolower((string) $actor->public_id);

        if ($scope === 'me') {
            $hiddenFor = is_array($metadata['hidden_for_user_public_ids'] ?? null)
                ? $metadata['hidden_for_user_public_ids']
                : [];
            $hiddenFor = array_values(array_unique(array_filter(
                array_map(static fn ($id) => strtolower((string) $id), $hiddenFor),
                static fn (string $id) => $id !== ''
            )));

            if ($actorPublicId !== '' && ! in_array($actorPublicId, $hiddenFor, true)) {
                $hiddenFor[] = $actorPublicId;
            }
            $metadata['hidden_for_user_public_ids'] = $hiddenFor;
            $message->forceFill(['metadata' => $metadata])->save();

            return response()->json([
                'status' => 'hidden',
                'scope' => 'me',
                'message_id' => $message->public_id,
            ]);
        }

        if ($message->type !== 'user') {
            return response()->json(['message' => 'System messages cannot be deleted for everyone.'], 422);
        }

        if (! $this->canDeleteMessageForAll($chat, $message, $actor)) {
            return response()->json(['message' => 'You are not allowed to delete this message for everyone.'], 403);
        }

        if (($metadata['is_deleted'] ?? false) !== true) {
            $metadata['is_deleted'] = true;
            $metadata['deleted_at'] = now()->toIso8601String();
            $metadata['deleted_by_user_public_id'] = (string) $actor->public_id;
            $metadata['deleted_by_user_name'] = (string) $actor->name;
            unset($metadata['is_pinned'], $metadata['pinned_at'], $metadata['pinned_by_user_public_id'], $metadata['pinned_by_user_name']);
            unset($metadata['is_edited'], $metadata['edited_at'], $metadata['edited_by_user_public_id'], $metadata['edited_by_user_name']);

            DB::transaction(function () use ($message, $metadata) {
                $message->forceFill([
                    'content' => '',
                    'metadata' => $metadata,
                ])->save();
                $message->clearMediaCollection('chat_attachments');
            });
        }

        $message = $message->fresh([
            'user:id,public_id,name',
            'media',
            'replyTo.user:id,public_id,name',
            'chat',
        ]);

        \App\Services\Chat\ChatEvents::messageUpdated($message, $chat->type ?? 'dm');

        return response()->json([
            'data' => ChatEngine::normalizeOne($message),
            'scope' => 'all',
        ]);
    }

    /**
     * Get edit history for a single message.
     */
    public function messageHistory(Request $request, Chat $chat, string $messagePublicId): JsonResponse
    {
        abort_if(! $chat->participants->contains(Auth::id()), 404);

        $message = $this->resolveChatMessage($chat, $messagePublicId);
        if (! $message) {
            return response()->json(['message' => 'Message not found.'], 404);
        }

        $metadata = is_array($message->metadata) ? $message->metadata : [];
        $history = is_array($metadata['edit_history'] ?? null) ? $metadata['edit_history'] : [];

        $normalized = collect($history)
            ->filter(fn ($entry) => is_array($entry))
            ->map(function ($entry) {
                return [
                    'previous_content' => (string) ($entry['previous_content'] ?? ''),
                    'edited_at' => is_string($entry['edited_at'] ?? null) ? $entry['edited_at'] : null,
                    'edited_by_user_public_id' => is_string($entry['edited_by_user_public_id'] ?? null)
                        ? $entry['edited_by_user_public_id']
                        : null,
                    'edited_by_user_name' => is_string($entry['edited_by_user_name'] ?? null)
                        ? $entry['edited_by_user_name']
                        : null,
                ];
            })
            ->values()
            ->all();

        return response()->json([
            'data' => $normalized,
            'meta' => [
                'count' => count($normalized),
                'is_edited' => ($metadata['is_edited'] ?? false) === true,
                'edited_at' => is_string($metadata['edited_at'] ?? null) ? $metadata['edited_at'] : null,
            ],
        ]);
    }

    /**
     * Toggle a single-reaction-per-user entry on a message.
     */
    public function toggleMessageReaction(Request $request, Chat $chat, string $messagePublicId): JsonResponse
    {
        abort_if(! $chat->participants->contains(Auth::id()), 404);

        $message = $this->resolveChatMessage($chat, $messagePublicId);
        if (! $message) {
            return response()->json(['message' => 'Message not found.'], 404);
        }

        $validated = $request->validate([
            'reaction' => 'required|string|in:like,laugh,100,hundred,sad,love,angry,scared,care',
        ]);

        $metadata = is_array($message->metadata) ? $message->metadata : [];
        if (($metadata['is_deleted'] ?? false) === true) {
            return response()->json(['message' => 'Cannot react to deleted messages.'], 422);
        }

        $reaction = (string) $validated['reaction'];
        if ($reaction === '100') {
            $reaction = 'hundred';
        }

        $reactions = is_array($metadata['reactions'] ?? null) ? $metadata['reactions'] : [];
        $actorPublicId = strtolower((string) Auth::user()?->public_id);

        if ($actorPublicId === '') {
            return response()->json(['message' => 'Unable to identify reactor.'], 422);
        }

        $currentForRequested = $this->reactionParticipantsForKey($reactions, $reaction);
        $alreadyReacted = in_array($actorPublicId, $currentForRequested, true);

        // Enforce one reaction per user: remove actor from all buckets first.
        foreach ($reactions as $key => $ids) {
            $normalized = array_values(array_filter(
                array_map(static fn ($id) => strtolower((string) $id), is_array($ids) ? $ids : []),
                static fn (string $id) => $id !== '' && $id !== $actorPublicId
            ));

            if (empty($normalized)) {
                unset($reactions[$key]);
                continue;
            }

            $reactions[$key] = array_values(array_unique($normalized));
        }

        if (! $alreadyReacted) {
            $next = is_array($reactions[$reaction] ?? null) ? $reactions[$reaction] : [];
            $next[] = $actorPublicId;
            $reactions[$reaction] = array_values(array_unique(array_filter(
                array_map(static fn ($id) => strtolower((string) $id), $next),
                static fn (string $id) => $id !== ''
            )));
        }

        if (empty($reactions)) {
            unset($metadata['reactions']);
        } else {
            // Keep only known keys to avoid unexpected payload bloat.
            $metadata['reactions'] = collect($reactions)
                ->only(self::REACTION_KEYS)
                ->map(fn ($ids) => array_values(array_unique(array_filter(
                    array_map(static fn ($id) => strtolower((string) $id), is_array($ids) ? $ids : []),
                    static fn (string $id) => $id !== ''
                ))))
                ->filter(fn ($ids) => ! empty($ids))
                ->all();
        }

        $message->forceFill(['metadata' => $metadata])->save();

        $message = $message->fresh([
            'user:id,public_id,name',
            'media',
            'replyTo.user:id,public_id,name',
            'chat',
        ]);

        \App\Services\Chat\ChatEvents::messageUpdated($message, $chat->type ?? 'dm');

        return response()->json([
            'data' => ChatEngine::normalizeOne($message),
            'meta' => [
                'reaction' => $reaction,
                'active' => ! $alreadyReacted,
            ],
        ]);
    }

    public function pinMessage(Request $request, Chat $chat, string $messagePublicId): JsonResponse
    {
        abort_if(! $chat->participants->contains(Auth::id()), 404);

        $message = $this->resolveChatMessage($chat, $messagePublicId);
        if (! $message) {
            return response()->json(['message' => 'Message not found.'], 404);
        }

        $metadata = is_array($message->metadata) ? $message->metadata : [];
        if (($metadata['is_deleted'] ?? false) === true) {
            return response()->json(['message' => 'Deleted messages cannot be pinned.'], 422);
        }
        $metadata['is_pinned'] = true;
        $metadata['pinned_at'] = now()->toIso8601String();
        $metadata['pinned_by_user_public_id'] = (string) Auth::user()?->public_id;
        $metadata['pinned_by_user_name'] = (string) Auth::user()?->name;

        $message->forceFill(['metadata' => $metadata])->save();

        $message = $message->fresh([
            'user:id,public_id,name',
            'media',
            'replyTo.user:id,public_id,name',
            'chat',
        ]);

        \App\Services\Chat\ChatEvents::messageUpdated($message, $chat->type ?? 'dm');

        return response()->json([
            'data' => ChatEngine::normalizeOne($message),
        ]);
    }

    public function unpinMessage(Request $request, Chat $chat, string $messagePublicId): JsonResponse
    {
        abort_if(! $chat->participants->contains(Auth::id()), 404);

        $message = $this->resolveChatMessage($chat, $messagePublicId);
        if (! $message) {
            return response()->json(['message' => 'Message not found.'], 404);
        }

        $metadata = is_array($message->metadata) ? $message->metadata : [];
        if (($metadata['is_deleted'] ?? false) === true) {
            return response()->json(['message' => 'Deleted messages cannot be unpinned.'], 422);
        }
        unset(
            $metadata['is_pinned'],
            $metadata['pinned_at'],
            $metadata['pinned_by_user_public_id'],
            $metadata['pinned_by_user_name']
        );

        $message->forceFill(['metadata' => $metadata])->save();

        $message = $message->fresh([
            'user:id,public_id,name',
            'media',
            'replyTo.user:id,public_id,name',
            'chat',
        ]);

        \App\Services\Chat\ChatEvents::messageUpdated($message, $chat->type ?? 'dm');

        return response()->json([
            'data' => ChatEngine::normalizeOne($message),
        ]);
    }

    /**
     * Resolve a message in a chat by public identifier.
     */
    protected function resolveChatMessage(Chat $chat, string $messagePublicId): ?ChatMessage
    {
        return ChatMessage::query()
            ->where('chat_id', $chat->id)
            ->whereRaw('LOWER(public_id) = ?', [strtolower($messagePublicId)])
            ->first();
    }

    protected function resolveVisibleLastMessage(Chat $chat, ?User $viewer): ?ChatMessage
    {
        $loaded = $chat->lastMessage;
        if ($loaded && (! $viewer || ! $this->isMessageHiddenForViewer($loaded, $viewer))) {
            return $loaded;
        }

        $query = ChatMessage::query()
            ->where('chat_id', $chat->id)
            ->with(['user:id,name,public_id', 'media'])
            ->latest('id');

        if ($viewer) {
            $query = $this->applyViewerVisibilityScope($query, $viewer);
        }

        return $query->first();
    }

    protected function isMessageHiddenForViewer(ChatMessage $message, User $viewer): bool
    {
        $viewerPublicId = strtolower(trim((string) $viewer->public_id));
        if ($viewerPublicId === '') {
            return false;
        }

        $metadata = is_array($message->metadata) ? $message->metadata : [];
        $hiddenFor = is_array($metadata['hidden_for_user_public_ids'] ?? null)
            ? $metadata['hidden_for_user_public_ids']
            : [];
        $normalized = array_values(array_unique(array_filter(
            array_map(static fn ($id) => strtolower((string) $id), $hiddenFor),
            static fn (string $id) => $id !== ''
        )));

        return in_array($viewerPublicId, $normalized, true);
    }

    /**
     * Apply per-viewer visibility filter (used by "unsend for me").
     *
     * @param  Builder<ChatMessage>  $query
     * @return Builder<ChatMessage>
     */
    protected function applyViewerVisibilityScope(Builder $query, User $viewer): Builder
    {
        $viewerPublicId = strtolower(trim((string) $viewer->public_id));
        if ($viewerPublicId === '') {
            return $query;
        }

        return $query->where(function (Builder $visibility) use ($viewerPublicId) {
            $visibility->whereNull('metadata')
                ->orWhereNull('metadata->hidden_for_user_public_ids')
                ->orWhereJsonDoesntContain('metadata->hidden_for_user_public_ids', $viewerPublicId);
        });
    }

    protected function canDeleteMessageForAll(Chat $chat, ChatMessage $message, User $actor): bool
    {
        if ((int) $message->user_id === (int) $actor->id) {
            return true;
        }

        return $this->canManageGroup($chat, (int) $actor->id);
    }

    /**
     * @param  array<string, mixed>  $reactions
     * @return list<string>
     */
    protected function reactionParticipantsForKey(array $reactions, string $reaction): array
    {
        $buckets = [];
        if (is_array($reactions[$reaction] ?? null)) {
            $buckets = array_merge($buckets, $reactions[$reaction]);
        }
        if ($reaction === 'hundred' && is_array($reactions['100'] ?? null)) {
            $buckets = array_merge($buckets, $reactions['100']);
        }

        return array_values(array_unique(array_filter(
            array_map(static fn ($id) => strtolower((string) $id), $buckets),
            static fn (string $id) => $id !== ''
        )));
    }

    /**
     * Send typing indicator.
     */
    public function typing(Request $request, Chat $chat): JsonResponse
    {
        abort_if(! $chat->participants->contains(Auth::id()), 404);

        broadcast(new \App\Events\Chat\UserTyping(
            chatPublicId: $chat->public_id,
            user: Auth::user(),
            chatType: $chat->type
        ))->toOthers();

        return response()->json(['status' => 'ok']);
    }

    /**
     * Mark chat as read.
     */
    public function markRead(Chat $chat): JsonResponse
    {
        abort_if(! $chat->participants->contains(Auth::id()), 404);
        ChatEngine::for($chat, Auth::user())->markRead();

        return response()->json(['status' => 'ok']);
    }

    // =========================================================================
    // People Discovery & DM
    // =========================================================================

    /**
     * List people available for chat.
     */
    public function people(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));
        $onlineOnly = $request->boolean('online', false);
        $chatPublicId = $request->input('chat_id');
        $userId = Auth::id();

        // Scope to chat participants if chat_id is provided
        $scopeUserIds = null;
        if ($chatPublicId) {
            $chat = Chat::where('public_id', $chatPublicId)->first();
            if ($chat) {
                // Ensure the requesting user is actually in this chat
                if (! $chat->participants()->where('users.id', $userId)->exists()) {
                    abort(403, 'You are not a participant of this chat.');
                }

                $scopeUserIds = $chat->participants()
                    ->pluck('users.id')
                    ->filter(fn ($id) => $id !== $userId)
                    ->values();
            }
        }

        if (strlen($q) < 2) {
            $presence = app(PresenceService::class);

            if ($scopeUserIds !== null) {
                // If scoped to a chat, only show those users (even if offline, though usually we want to see who is in the chat)
                // For "empty query", typically we show online users or recent contacts.
                // If scoped, let's show all chat participants.
                $users = User::whereIn('id', $scopeUserIds)->get();
            } else {
                // Global context: show active users
                $users = $presence
                    ->getActiveUsers()
                    ->where('id', '!=', $userId)
                    ->values();
            }

            $data = $users->map(function ($u) use ($presence) {
                $status = $presence->presenceStatus($u->id);

                return [
                    'id' => $u->public_id, // Use public_id for frontend
                    'name' => $u->name ?? $u->email ?? $u->public_id,
                    'email' => $u->email,
                    'public_id' => $u->public_id,
                    'avatar_url' => $u->getAvatarData()->getUrl(),
                    'color' => $u->getAvatarData()->color,
                    'is_online' => $status === 'online',
                    'presence_status' => $status,
                ];
            })->values();

            return response()->json(['data' => $data]);
        }

        $escapedQ = str_replace(['%', '_'], ['\%', '\_'], $q);
        $query = User::query()
            ->where('id', '!=', $userId)
            ->where(function ($query) use ($escapedQ) {
                $query->where('name', 'like', "%{$escapedQ}%")
                    ->orWhere('email', 'like', "%{$escapedQ}%")
                    ->orWhere('public_id', 'like', "%{$escapedQ}%");
            })
            ->limit(12);

        // Apply Chat Scope if present
        if ($scopeUserIds !== null) {
            $query->whereIn('id', $scopeUserIds);
        }

        if ($onlineOnly) {
            $onlineIds = app(PresenceService::class)->getActiveUserIds();
            $query->whereIn('id', $onlineIds ?: [0]);
        }

        if ($onlineOnly) {
            $onlineIds = app(PresenceService::class)->getActiveUserIds();
            $query->whereIn('id', $onlineIds ?: [0]);
        }

        $users = $query->get(['id', 'name', 'email', 'public_id']);

        $data = $users->map(fn ($u) => [
            'id' => $u->public_id, // Use public_id for frontend
            'name' => $u->name ?? $u->email ?? $u->public_id,
            'email' => $u->email,
            'public_id' => $u->public_id,
            'avatar' => $u->avatar_url,
            'is_online' => false,
            'presence_status' => 'unknown',
        ])->values();

        return response()->json(['data' => $data]);
    }

    /**
     * Ensure a DM exists or indicate invite required.
     */
    public function ensureDm(Request $request): JsonResponse
    {
        $userId = Auth::id();

        $request->validate([
            'user_id' => ['nullable', 'integer', 'exists:users,id', 'not_in:'.Auth::id()],
            'public_id' => ['nullable', 'string', 'exists:users,public_id'],
        ]);

        if ($request->filled('user_id')) {
            $otherId = (int) $request->input('user_id');
        } elseif ($request->filled('public_id')) {
            $otherId = User::where('public_id', $request->input('public_id'))->value('id');
            abort_if($otherId === $userId, 422, 'You cannot DM yourself.');
        } else {
            abort(422, 'Either user_id or public_id is required.');
        }

        $chat = Chat::where('type', 'dm')
            ->whereHas('participants', fn ($q) => $q->where('user_id', $userId))
            ->whereHas('participants', fn ($q) => $q->where('user_id', $otherId))
            ->with(['participants' => function ($query) {
                $query->select('users.id', 'users.name', 'users.username', 'users.public_id')
                    ->with('media');
            }, 'lastMessage.user:id,name,public_id'])
            ->first();

        if ($chat) {
            return response()->json([
                'status' => 'chat_exists',
                'chat_public_id' => $chat->public_id, // Return public_id as the identifier
                'data' => $this->mapChat($chat),
            ]);
        }

        return response()->json([
            'status' => 'invite_required',
            'message' => 'No chat exists. Send an invite first.',
        ], 202);
    }

    // =========================================================================
    // Invites
    // =========================================================================

    /**
     * List pending invites for the user.
     */
    public function invites(): JsonResponse
    {
        ChatInvite::purgeExpired();

        $invites = ChatInvite::pending()
            ->where('invitee_id', Auth::id())
            ->with(['inviter:id,name,public_id', 'chat:id,name,type,public_id'])
            ->latest()
            ->get()
            ->map(fn ($invite) => [
                'id' => $invite->public_id, // Use public_id for security
                'inviter_name' => $invite->inviter?->name ?? 'User',
                'inviter_public_id' => $invite->inviter?->public_id,
                'avatar_url' => $invite->inviter?->avatar_url ?? null,
                'sent_at' => $invite->created_at?->shortRelativeDiffForHumans(),
                'type' => $invite->chat_id ? 'group' : 'dm',
                'chat_name' => $invite->chat?->name,
                'chat_public_id' => $invite->chat?->public_id,
            ])
            ->values();

        return response()->json(['data' => $invites]);
    }

    /**
     * Send a DM invite.
     */
    public function sendInvite(Request $request): JsonResponse
    {
        $request->validate([
            'invitee_public_id' => ['required', 'string', 'exists:users,public_id'],
        ]);

        $userId = Auth::id();

        // Resolve invitee by public_id
        $invitee = User::where('public_id', $request->input('invitee_public_id'))->first();
        abort_if(! $invitee, 404, 'Invitee not found.');
        abort_if($invitee->id === $userId, 422, 'You cannot invite yourself.');

        // Check for existing chat
        $existingChat = Chat::where('type', 'dm')
            ->whereHas('participants', fn ($q) => $q->where('user_id', $userId))
            ->whereHas('participants', fn ($q) => $q->where('user_id', $invitee->id))
            ->first();

        if ($existingChat) {
            return response()->json([
                'status' => 'chat_exists',
                'chat_id' => $existingChat->public_id, // Return public_id
                'message' => 'Chat already exists.',
            ]);
        }

        // Check for pending invite
        $existingInvite = ChatInvite::pending()
            ->between($userId, $invitee->id)
            ->first();

        if ($existingInvite) {
            return response()->json([
                'status' => 'invite_pending',
                'invite_id' => $existingInvite->public_id, // Return public_id
                'message' => 'Invite already pending.',
            ], 202);
        }

        $invite = ChatInvite::create([
            'inviter_id' => $userId,
            'invitee_id' => $invitee->id,
            'type' => 'dm',
            'expires_at' => now()->addDays(7),
        ]);

        broadcast(new \App\Events\Chat\InviteSent([
            'id' => $invite->public_id,
            'inviter_name' => Auth::user()->name,
            'inviter_public_id' => Auth::user()->public_id,
            'avatar_url' => Auth::user()->avatar_url,
            'sent_at' => 'just now',
            'type' => 'dm',
            'chat_name' => null,
            'chat_public_id' => null,
            'invitee_id' => $invitee->id, // Legacy support if needed
            'invitee_public_id' => $invitee->public_id, // For channel broadcasting
        ]));

        return response()->json([
            'status' => 'invite_sent',
            'invite_id' => $invite->public_id, // Return public_id
        ], 201);
    }

    /**
     * Accept an invite.
     */
    /**
     * Accept an invite.
     */
    public function acceptInvite(string $invitePublicId): JsonResponse
    {
        $result = DB::transaction(function () use ($invitePublicId) {
            $invite = ChatInvite::where('public_id', $invitePublicId)
                ->lockForUpdate() // Prevent race conditions on the invite itself
                ->with(['invitee:id,name', 'inviter:id,name,public_id'])
                ->first();

            if (! $invite) {
                return response()->json(['message' => 'Invite not found.'], 404);
            }

            // Idempotency: If already accepted, return success
            if ($invite->status === ChatInvite::STATUS_ACCEPTED) {
                // Retrieve existing chat ID to return consistent response
                $chatPublicId = null;
                if ($invite->chat_id) {
                    $chatPublicId = Chat::where('id', $invite->chat_id)->value('public_id');
                } else {
                    // Try to find the DM chat
                    $chatPublicId = Chat::where('type', 'dm')
                        ->whereHas('participants', fn ($q) => $q->where('user_id', $invite->inviter_id))
                        ->whereHas('participants', fn ($q) => $q->where('user_id', $invite->invitee_id))
                        ->value('public_id');
                }

                return response()->json([
                    'status' => 'already_accepted',
                    'chat_public_id' => $chatPublicId,
                    'message' => 'Invite already accepted.',
                ]);
            }

            if ($invite->status !== ChatInvite::STATUS_PENDING) {
                return response()->json(['message' => 'Invite is no longer valid.'], 410);
            }

            $chatId = null;
            $chatPublicId = null;

            if ($invite->chat_id) {
                // Group invite
                $chat = Chat::with('participants:id')->find($invite->chat_id);
                if (! $chat || ! in_array($chat->type, ['group', 'team'], true)) {
                    $invite->markRejected();

                    return response()->json(['message' => 'Invite expired.'], 410);
                }

                // Use syncWithoutDetaching on allParticipants ensures we include soft-deleted (left) members
                // This updates existing rows if found, or inserts if not.
                $chat->allParticipants()->syncWithoutDetaching([
                    $invite->invitee_id => [
                        'role' => 'member',
                        'public_id' => (string) Str::ulid(),
                        'created_at' => now(),
                        'updated_at' => now(),
                        'left_at' => null, // CAUTION: Must reset left_at to null to "rejoin"
                        'kicked_by' => null, // Reset kicked status
                    ],
                ]);

                // We can't easily check 'wasRecentlyCreated' on pivot with syncWithoutDetaching
                // blocking logic relies on preventing double-system-messages
                // Checking existence before system message creation:

                // Refresh participants to check if we should send system message (only if freshly added?)
                // Actually, if we are in this block and status was PENDING (locked), we are the first one processing this invite.
                // So we can assume we added them. The DB lock on invite ensures we only run this once per invite.

                ChatEngine::for($chat, $invite->invitee)
                    ->createSystemMessage(
                        $chat,
                        "{$invite->inviter->name} added {$invite->invitee->name} to the group.",
                        $invite->inviter_id
                    );

                $invite->markAccepted();
                $chatId = $chat->id;
                $chatPublicId = $chat->public_id;
            } else {
                // DM invite
                $userId = Auth::id();
                $otherId = $invite->inviter_id;

                $chatId = Chat::where('type', 'dm')
                    ->whereHas('participants', fn ($q) => $q->where('user_id', $userId))
                    ->whereHas('participants', fn ($q) => $q->where('user_id', $otherId))
                    ->value('id');

                if (! $chatId) {
                    $chat = Chat::create([
                        'type' => 'dm',
                        'public_id' => (string) Str::ulid(),
                    ]);
                    $chat->participants()->attach([
                        $invite->inviter_id => [
                            'role' => 'member',
                            'public_id' => (string) Str::ulid(),
                        ],
                        $invite->invitee_id => [
                            'role' => 'member',
                            'public_id' => (string) Str::ulid(),
                        ],
                    ]);
                    $chatId = $chat->id;
                    $chatPublicId = $chat->public_id;
                } else {
                    $chatPublicId = Chat::where('id', $chatId)->value('public_id');
                }

                $invite->markAccepted();
            }

            $chatModel = Chat::find($chatId);

            return [$chatModel, $invite, $chatPublicId];
        });

        // Unpack transaction result
        if ($result instanceof JsonResponse) {
            return $result;
        }

        [$chatModel, $invite, $chatPublicId] = $result;

        // ... existing broadcast logic (lines 803+) ...

        broadcast(new \App\Events\Chat\InviteAccepted(
            [
                'id' => $invite->public_id,
                'inviter_id' => $invite->inviter_id,
                'inviter_public_id' => $invite->inviter?->public_id, // For channel broadcasting
                'invitee_name' => $invite->invitee?->name ?? 'User',
            ],
            $this->mapChat($chatModel)
        ));

        return response()->json([
            'status' => 'ok',
            'chat_public_id' => $chatPublicId ?? $chatModel?->public_id,
        ]);
    }

    /**
     * Decline an invite.
     */
    public function declineInvite(string $invitePublicId): JsonResponse
    {
        $invite = ChatInvite::pending()
            ->where('public_id', $invitePublicId)
            ->where('invitee_id', Auth::id())
            ->with('invitee:id,name') // Load invitee name
            ->first();

        if (! $invite) {
            return response()->json(['message' => 'Invite not found.'], 404);
        }

        $invite->markRejected();

        broadcast(new \App\Events\Chat\InviteDeclined([
            'id' => $invite->public_id,
            'inviter_id' => $invite->inviter_id,
            'invitee_id' => $invite->invitee_id,
            'invitee_name' => $invite->invitee?->name ?? 'User',
        ]));

        return response()->json(['status' => 'ok']);
    }

    // =========================================================================
    // Groups
    // =========================================================================

    /**
     * Create a new group chat.
     */
    public function createGroup(Request $request): JsonResponse
    {
        $userId = Auth::id();
        abort_if(! $userId, 401);

        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:80'],
        ]);

        $chat = Chat::create([
            'public_id' => (string) Str::ulid(),
            'name' => $validated['name'] ?? 'New group chat',
            'type' => 'group',
            'created_by' => $userId,
        ]);

        $chat->participants()->attach([
            $userId => [
                'role' => 'owner',
                'public_id' => (string) Str::ulid(),
            ],
        ]);

        $chat->load([
            'participants' => function ($query) {
                $query->select('users.id', 'users.name', 'users.username', 'users.public_id')
                    ->with('media');
            },
            'lastMessage.user:id,name,public_id',
            'team:id,owner_id',
        ]);

        return response()->json([
            'data' => $this->mapChat($chat),
        ], 201);
    }

    /**
     * Rename a group chat.
     */
    public function rename(Request $request, Chat $chat): JsonResponse
    {
        abort_if(! $chat->participants->contains(Auth::id()), 404);

        abort_if(! in_array($chat->type, ['group', 'team'], true), 422, 'Only group chats can be renamed.');

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:80'],
        ]);

        abort_unless($this->canManageGroup($chat, Auth::id()), 403, 'You cannot rename this chat.');

        $chat->update(['name' => $validated['name']]);

        $chat->refresh()->load([
            'participants' => function ($query) {
                $query->select('users.id', 'users.name', 'users.username', 'users.public_id')
                    ->with('media');
            },
            'lastMessage.user:id,name,public_id',
            'team:id,owner_id',
        ]);

        return response()->json([
            'data' => $this->mapChat($chat),
        ]);
    }

    /**
     * Add a member to a group (creates invite).
     */
    public function addMember(Request $request, Chat $chat): JsonResponse
    {
        abort_if(! $chat->participants->contains(Auth::id()), 404);
        abort_if(! in_array($chat->type, ['group', 'team'], true), 422, 'Only group/team chats support members.');
        abort_unless($this->isOwnerOrTeamOwner($chat, Auth::id()), 403, 'Only owners can invite members.');

        $validated = $request->validate([
            'user_public_id' => ['required', 'string', 'exists:users,public_id'],
        ]);

        // Resolve user by public_id for security
        $user = User::where('public_id', $validated['user_public_id'])->first();
        abort_if(! $user, 404, 'User not found.');
        abort_if($user->id === Auth::id(), 422, 'Cannot invite yourself.');

        $already = $chat->participants()->where('user_id', $user->id)->exists();
        abort_if($already, 422, 'User is already a member.');

        $pendingInvite = ChatInvite::pending()
            ->forChat($chat->id)
            ->where('invitee_id', $user->id)
            ->first();

        if ($pendingInvite) {
            return response()->json(['message' => 'User already has a pending invite.'], 422);
        }

        $invite = ChatInvite::create([
            'inviter_id' => Auth::id(),
            'invitee_id' => $user->id,
            'chat_id' => $chat->id,
            'type' => 'group',
            'status' => ChatInvite::STATUS_PENDING,
            'expires_at' => now()->addDays(7),
        ]);

        return response()->json([
            'message' => 'Invite sent successfully.',
            'invite_id' => $invite->public_id, // Return public_id for security
        ], 201);
    }

    /**
     * Remove a member from a group.
     */
    public function removeMember(Request $request, Chat $chat, string $memberPublicId): JsonResponse
    {
        abort_if(! $chat->participants->contains(Auth::id()), 404);
        abort_if(! in_array($chat->type, ['group', 'team'], true), 422, 'Only group/team chats support members.');
        abort_unless($this->isOwnerOrTeamOwner($chat, Auth::id()), 403, 'Only owners can remove members.');

        // Resolve member by public_id
        $participant = $chat->participants->firstWhere('public_id', $memberPublicId);
        abort_if(! $participant, 404, 'Member not found in this chat.');
        abort_if($participant->id === Auth::id(), 422, 'Use leave chat to remove yourself.');

        $role = $participant->pivot->role ?? 'member';
        $teamOwnerId = $chat->team?->owner_id;

        abort_if($role === 'owner', 422, 'Owners cannot be removed.');
        abort_if($teamOwnerId && $teamOwnerId === $participant->id, 422, 'Team owner cannot be removed.');
        abort_if($chat->participants->count() <= 2, 422, 'Groups need at least two members.');

        $chat->participants()->detach($participant->id);

        $chat->load([
            'participants' => function ($query) {
                $query->select('users.id', 'users.name', 'users.username', 'users.public_id')
                    ->with('media');
            },
            'lastMessage.user:id,name,public_id',
            'team:id,owner_id',
        ]);

        return response()->json([
            'data' => $this->mapChat($chat),
        ]);
    }

    // =========================================================================
    // Media
    // =========================================================================

    /**
     * List media for a chat.
     */
    public function media(Request $request, Chat $chat): JsonResponse
    {
        abort_if(! $chat->participants->contains(Auth::id()), 404);
        $filter = $request->input('filter');
        $perPage = max(1, min(50, $request->integer('per_page', 24)));

        $paginator = app(ChatMediaService::class)->getChatMedia(
            $chat,
            $filter === 'all' ? null : $filter,
            $perPage
        );

        $items = collect($paginator->items())
            ->map(fn ($media) => $this->transformMediaItem($media))
            ->values();

        return response()->json([
            'data' => $items,
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    /**
     * Get storage statistics for a chat.
     */
    public function storageStats(Chat $chat): JsonResponse
    {
        abort_if(! $chat->participants->contains(Auth::id()), 404);

        $stats = app(ChatMediaService::class)->getChatStorageStats($chat);

        return response()->json([
            'data' => $stats,
        ]);
    }

    /**
     * Delete media from a chat.
     */
    /**
     * Delete media from a chat.
     */
    public function deleteMedia(Request $request, Chat $chat, int $mediaId): JsonResponse
    {
        abort_if(! $chat->participants->contains(Auth::id()), 404);
        $user = Auth::user();

        /** @var Media $media */
        $media = Media::with('model')->findOrFail($mediaId);
        abort_if($media->model_type !== ChatMessage::class, 404);

        /** @var ChatMessage|null $message */
        $message = $media->model;
        abort_if(! $message || $message->chat_id !== $chat->id, 404);

        $role = $this->participantRole($chat, $user->id);
        $teamOwnerId = $chat->team?->owner_id;

        $canDelete = $message->user_id === $user->id
            || in_array($role, ['owner', 'admin'], true)
            || ($teamOwnerId && $teamOwnerId === $user->id);

        abort_if(! $canDelete, 403, 'You cannot delete this file.');

        app(ChatMediaService::class)->deleteMedia($mediaId, $user);

        return response()->json(['status' => 'deleted']);
    }

    /**
     * Transform a media item for API response.
     *
     * @return array<string, mixed>
     */
    protected function transformMediaItem(Media $media): array
    {
        $size = (int) ($media->size ?? 0);
        $sizeLabel = $size >= 1048576
            ? number_format($size / 1048576, 1).' MB'
            : number_format(max($size, 1) / 1024, 1).' KB';
        $viewUrl = route('chat.media.view', ['mediaId' => $media->id], false);
        $downloadUrl = route('chat.media.download', ['mediaId' => $media->id], false);
        $isVoiceClip = (bool) $media->getCustomProperty('is_voice_clip', false);
        $mediaKind = (string) ($media->getCustomProperty('media_kind')
            ?: $this->inferMediaKind((string) $media->mime_type, $isVoiceClip));
        $isImage = $mediaKind === 'image';
        $isVideo = $mediaKind === 'video';

        $thumbConversion = null;
        if ($isImage && $media->hasGeneratedConversion('thumb')) {
            $thumbConversion = 'thumb';
        } elseif ($isVideo && $media->hasGeneratedConversion('video_thumb')) {
            $thumbConversion = 'video_thumb';
        }

        return [
            'id' => $media->id,
            'name' => $media->getCustomProperty('original_filename') ?? $media->file_name,
            'size' => $size,
            'size_human' => $sizeLabel,
            'mime_type' => $media->mime_type,
            'is_image' => $isImage,
            'is_audio' => $mediaKind === 'audio',
            'is_video' => $mediaKind === 'video',
            'is_voice_clip' => $isVoiceClip,
            'media_kind' => $mediaKind,
            'duration_seconds' => $this->normalizeDurationSeconds($media->getCustomProperty('duration_seconds')),
            'created_at_human' => $media->created_at?->shortRelativeDiffForHumans() ?? '',
            'url' => $viewUrl,
            'download_url' => $downloadUrl,
            'thumb_url' => $thumbConversion
                ? route('chat.media.conversion', [
                    'mediaId' => $media->id,
                    'conversion' => $thumbConversion,
                ], false)
                : null,
        ];
    }

    protected function inferMediaKind(string $mimeType, bool $isVoiceClip = false): string
    {
        if ($isVoiceClip) {
            return 'audio';
        }

        $normalized = strtolower(trim($mimeType));
        if ($normalized === '') {
            return 'file';
        }

        if (str_starts_with($normalized, 'image/')) {
            return 'image';
        }

        if (str_starts_with($normalized, 'audio/')) {
            return 'audio';
        }

        if (str_starts_with($normalized, 'video/')) {
            return 'video';
        }

        return 'file';
    }

    protected function normalizeDurationSeconds(mixed $value): ?int
    {
        if (! is_numeric($value)) {
            return null;
        }

        $seconds = (int) round((float) $value);

        return $seconds >= 0 ? $seconds : null;
    }

    // =========================================================================
    // Group Management
    // =========================================================================

    /**
     * Leave a group chat.
     */
    public function leave(Chat $chat): JsonResponse
    {
        abort_if(! $chat->participants->contains(Auth::id()), 404);

        $this->groupChatService->leaveGroup($chat, Auth::user());

        return response()->json(['message' => 'You have left the group.']);
    }

    /**
     * Kick a member from the group.
     */
    public function kick(Chat $chat, string $userPublicId): JsonResponse
    {
        abort_if(! $chat->participants->contains(Auth::id()), 404);

        $user = User::where('public_id', $userPublicId)->firstOrFail();

        $this->groupChatService->kickMember($chat, $user, Auth::user());

        return response()->json(['message' => 'Member kicked successfully.']);
    }

    /**
     * Delete a group chat.
     */
    public function delete(Request $request, Chat $chat): JsonResponse
    {
        abort_if(! $chat->participants->contains(Auth::id()), 404);

        $request->validate(['password' => 'required|string']);

        $this->groupChatService->deleteGroup($chat, Auth::user(), $request->input('password'));

        return response()->json(['message' => 'Group deleted successfully.']);
    }

    /**
     * Rejoin a group chat.
     */
    public function rejoin(Chat $chat): JsonResponse
    {
        // Check if user is in allParticipants
        $isMember = $chat->allParticipants()->where('user_id', Auth::id())->exists();
        abort_if(! $isMember, 403, 'You are not a member of this group.');

        $this->groupChatService->rejoinGroup($chat, Auth::user());

        return response()->json([
            'message' => 'Rejoined group successfully.',
            'data' => $this->mapChat($chat),
        ]);
    }
}
