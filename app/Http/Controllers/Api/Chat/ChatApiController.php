<?php

namespace App\Http\Controllers\Api\Chat;

use App\Http\Controllers\Controller;
use App\Http\Requests\Chat\SendMessageRequest;
use App\Http\Requests\Chat\UpdateGroupRequest;
use App\Http\Requests\Chat\UploadMediaRequest;
use App\Http\Resources\Chat\ChatMessageResource;
use App\Http\Resources\Chat\ChatResource;
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
use Illuminate\Foundation\Auth\Access\AuthorizesRequests;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\MediaLibrary\MediaCollections\Models\Media;

class ChatApiController extends Controller
{
    use AuthorizesRequests;
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

    // =========================================================================
    // Chat List & Details
    // =========================================================================

    /**
     * List all chats for the authenticated user.
     */
    public function index(): AnonymousResourceCollection
    {
        $chats = Auth::user()->chats()
            ->with(['participants', 'latestVisibleMessage.user', 'latestVisibleMessage.media'])
            ->latest('updated_at')
            ->get();

        return ChatResource::collection($chats);
    }

    /**
     * Get a specific chat.
     */
    public function show(Chat $chat): ChatResource
    {
        if (! $chat->participants()->where('user_id', Auth::id())->exists()) {
            abort(404);
        }

        $chat->load(['participants', 'messages' => fn($q) => $q->visibleTo(Auth::user())->latest()->limit(50)]);

        return new ChatResource($chat);
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
    public function messages(Request $request, Chat $chat): AnonymousResourceCollection
    {
        $this->authorize('view', $chat);

        $beforePublicId = $request->input('before');
        $limit = max(1, min(50, $request->integer('limit', 25)));

        $engine = ChatEngine::for($chat, Auth::user());
        $query = $engine->queryMessages();

        if ($beforePublicId) {
            $beforeId = ChatMessage::where('public_id', $beforePublicId)
                ->where('chat_id', $chat->id)
                ->value('id');

            if ($beforeId) {
                $query->where('id', '<', $beforeId);
            } else {
                return ChatMessageResource::collection(collect());
            }
        }

        $messages = $query->orderByDesc('id')->limit($limit)->get()->reverse()->values();

        $hasMore = false;
        if ($messages->isNotEmpty()) {
            $oldestId = $messages->first()->id;
            $hasMore = $engine->queryMessages()->where('id', '<', $oldestId)->exists();
        }

        $seenThreshold = $engine->recipientLastSeenMessageId();

        return ChatMessageResource::collection($messages->load(['user', 'media', 'replyTo.user', 'replyTo.media']))->additional([
            'has_more' => $hasMore,
            'meta' => [
                'seen_threshold' => $seenThreshold,
            ]
        ]);
    }

    /**
     * Search messages within a chat.
     */
    public function searchMessages(Request $request, Chat $chat): AnonymousResourceCollection
    {
        $this->authorize('view', $chat);

        $queryStr = trim($request->input('q', ''));
        abort_if(strlen($queryStr) < 2, 422, 'Query must be at least 2 characters.');

        $viewer = Auth::user();
        $viewerPublicId = strtolower(trim((string) $viewer->public_id));

        $results = ChatMessage::search($queryStr)
            ->where('chat_id', $chat->id)
            ->take(50)
            ->get();

        if ($viewerPublicId !== '') {
            $results = $results->filter(function (ChatMessage $message) use ($viewerPublicId) {
                $metadata = is_array($message->metadata) ? $message->metadata : [];
                $hiddenFor = is_array($metadata['hidden_for_user_public_ids'] ?? null)
                    ? $metadata['hidden_for_user_public_ids']
                    : [];
                
                return ! in_array($viewerPublicId, array_map('strtolower', $hiddenFor), true);
            })->values();
        }

        return ChatMessageResource::collection($results->load(['user', 'media', 'replyTo.user', 'replyTo.media']));
    }

    /**
     * Update chat settings (Group only).
     */
    public function update(UpdateGroupRequest $request, Chat $chat): ChatResource
    {
        $this->authorize('update', $chat);

        $updatedChat = $this->groupChatService->updateGroupSettings(
            $chat,
            Auth::user(),
            $request->validated()
        );

        return new ChatResource($updatedChat->load(['participants', 'latestVisibleMessage.user', 'latestVisibleMessage.media']));
    }

    /**
     * Send a text message.
     */
    public function send(SendMessageRequest $request, Chat $chat): ChatMessageResource
    {
        $this->authorize('send', $chat);
        $this->enforceRateLimit(Auth::id());

        $validated = $request->validated();
        $replyId = null;

        if ($validated['reply_to_id'] ?? null) {
            $replyId = ChatMessage::where('public_id', $validated['reply_to_id'])
                ->where('chat_id', $chat->id)
                ->value('id');
        }

        $msg = ChatEngine::for($chat, Auth::user())
            ->send($validated['content'] ?? '', $validated['media_ids'] ?? [], $replyId, $validated['metadata'] ?? null);

        ChatTransport::queueBroadcast($msg, $validated['temp_id'] ?? (string) Str::uuid());

        $msg->load(['user', 'media', 'replyTo.user', 'replyTo.media']);
        return new ChatMessageResource($msg);
    }

    /**
     * Send a message with file attachments.
     */
    public function upload(UploadMediaRequest $request, Chat $chat): ChatMessageResource
    {
        $this->authorize('send', $chat);
        $this->enforceRateLimit(Auth::id());

        $validated = $request->validated();
        $files = $request->file('files', []);
        
        $mediaMetadata = is_string($validated['media_metadata'] ?? null) 
            ? json_decode($validated['media_metadata'], true) 
            : ($validated['media_metadata'] ?? []);

        $replyId = null;
        if ($validated['reply_to'] ?? null) {
            $replyId = ChatMessage::where('public_id', $validated['reply_to'])
                ->where('chat_id', $chat->id)
                ->value('id');
        }

        $msg = ChatEngine::for($chat, Auth::user())
            ->send($request->input('content', ''), $files, $replyId, null, $mediaMetadata);

        $msg->load(['user', 'media', 'replyTo.user', 'replyTo.media']);
        return new ChatMessageResource($msg);
    }

    /**
     * Edit an existing message authored by the current user.
     */
    public function updateMessage(Request $request, Chat $chat, string $messagePublicId): ChatMessageResource
    {
        $message = ChatMessage::where('public_id', $messagePublicId)->where('chat_id', $chat->id)->firstOrFail();
        
        $this->authorize('update', $message);

        if ($message->type !== 'user') {
            abort(422, 'Only user messages can be edited.');
        }

        $validated = $request->validate([
            'content' => ['required', 'string', 'max:'.ChatEngine::MAX_MESSAGE_LENGTH],
        ]);

        $content = trim((string) $validated['content']);
        $metadata = is_array($message->metadata) ? $message->metadata : [];
        if (($metadata['is_deleted'] ?? false) === true) {
            abort(422, 'Deleted messages cannot be edited.');
        }

        if ($content !== (string) ($message->content ?? '')) {
            $actor = Auth::user();
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

            $message = $message->fresh(['user', 'media', 'replyTo.user', 'chat']);
            \App\Services\Chat\ChatEvents::messageUpdated($message, $chat->type ?? 'dm');
        }

        return new ChatMessageResource($message);
    }

    /**
     * Delete/unsend message.
     */
    public function deleteMessage(Request $request, Chat $chat, string $messagePublicId): JsonResponse
    {
        $message = ChatMessage::where('public_id', $messagePublicId)->where('chat_id', $chat->id)->firstOrFail();
        
        $scope = $request->input('scope', 'all');
        $actor = Auth::user();

        if ($scope === 'me' || $scope === 'self') {
            $metadata = is_array($message->metadata) ? $message->metadata : [];
            $hiddenFor = is_array($metadata['hidden_for_user_public_ids'] ?? null) ? $metadata['hidden_for_user_public_ids'] : [];
            $actorPublicId = strtolower((string) $actor->public_id);

            if (! in_array($actorPublicId, array_map('strtolower', $hiddenFor), true)) {
                $hiddenFor[] = $actorPublicId;
                $metadata['hidden_for_user_public_ids'] = $hiddenFor;
                $message->forceFill(['metadata' => $metadata])->save();
            }

            return response()->json([
                'status' => 'ok',
                'scope' => $scope,
                'data' => new ChatMessageResource($message->fresh()),
            ]);
        }

        $this->authorize('delete', [$message, true]);

        if ($message->type !== 'user') {
            abort(422, 'System messages cannot be deleted for everyone.');
        }

        $metadata = is_array($message->metadata) ? $message->metadata : [];
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

            $message = $message->fresh(['user', 'media', 'replyTo.user', 'chat']);
            \App\Services\Chat\ChatEvents::messageUpdated($message, $chat->type ?? 'dm');
        }

        return response()->json([
            'status' => 'ok',
            'scope' => $scope,
            'data' => new ChatMessageResource($message),
        ]);
    }

    /**
     * Get edit history for a single message.
     */
    public function messageHistory(Request $request, Chat $chat, string $messagePublicId): JsonResponse
    {
        $this->authorize('view', $chat);

        $message = ChatMessage::where('public_id', $messagePublicId)->where('chat_id', $chat->id)->firstOrFail();

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
     * List people available for chat.
     */
    public function people(Request $request): JsonResponse
    {
        $q = trim((string) $request->input('q', ''));
        $onlineOnly = $request->boolean('online', false);
        $chatPublicId = $request->input('chat_id');
        $userId = Auth::id();

        $query = User::query()->where('id', '!=', $userId);

        if ($chatPublicId) {
            $chat = Chat::where('public_id', $chatPublicId)->firstOrFail();
            $this->authorize('view', $chat);
            
            $query->whereIn('id', $chat->participants()->pluck('users.id'));
        }

        if (strlen($q) >= 2) {
            $escapedQ = str_replace(['%', '_'], ['\%', '\_'], $q);
            $query->where(function ($query) use ($escapedQ) {
                $query->where('name', 'like', "%{$escapedQ}%")
                    ->orWhere('email', 'like', "%{$escapedQ}%")
                    ->orWhere('public_id', 'like', "%{$escapedQ}%");
            });
        }

        if ($onlineOnly) {
            $onlineIds = app(PresenceService::class)->getActiveUserIds();
            $query->whereIn('id', $onlineIds ?: [0]);
        }

        $users = $query->limit(20)->get();
        $presence = app(PresenceService::class);

        return response()->json([
            'data' => $users->map(fn ($u) => [
                'id' => $u->public_id,
                'name' => $u->name,
                'email' => $u->email,
                'public_id' => $u->public_id,
                'avatar' => $u->getAvatarData()->getUrl(),
                'avatar_url' => $u->getAvatarData()->getUrl(),
                'is_online' => $presence->presenceStatus($u->id) === 'online',
                'presence_status' => $presence->presenceStatus($u->id),
            ])
        ]);
    }

    /**
     * Ensure a DM exists or indicate invite required.
     */
    public function ensureDm(Request $request): JsonResponse
    {
        $request->validate([
            'public_id' => ['required', 'string', 'exists:users,public_id'],
        ]);

        $userId = Auth::id();
        $otherUser = User::where('public_id', $request->input('public_id'))->firstOrFail();
        
        abort_if($otherUser->id === $userId, 422, 'You cannot DM yourself.');

        $chat = Chat::where('type', 'dm')
            ->with('participants')
            ->whereHas('participants', fn ($q) => $q->where('user_id', $userId))
            ->whereHas('participants', fn ($q) => $q->where('user_id', $otherUser->id))
            ->first();

        if ($chat) {
            return response()->json([
                'status' => 'chat_exists',
                'chat_public_id' => $chat->public_id,
                'data' => new ChatResource($chat),
            ]);
        }

        return response()->json([
            'status' => 'invite_required',
            'message' => 'No chat exists. Send an invite first.',
        ], 202);
    }

    /**
     * Mark chat as read.
     */
    public function markRead(Chat $chat): JsonResponse
    {
        $this->authorize('view', $chat);
        
        ChatEngine::for($chat, Auth::user())->markRead();

        return response()->json(['status' => 'ok']);
    }

    /**
     * Toggle a single-reaction-per-user entry on a message.
     */
    public function toggleMessageReaction(Request $request, Chat $chat, string $messagePublicId): ChatMessageResource
    {
        $message = ChatMessage::where('public_id', $messagePublicId)->where('chat_id', $chat->id)->firstOrFail();
        
        $this->authorize('react', $message);

        $validated = $request->validate([
            'reaction' => 'required|string|in:like,laugh,100,hundred,sad,love,angry,scared,care',
        ]);

        $metadata = is_array($message->metadata) ? $message->metadata : [];
        if (($metadata['is_deleted'] ?? false) === true) {
            abort(422, 'Cannot react to deleted messages.');
        }

        $reaction = (string) $validated['reaction'];
        if ($reaction === '100') {
            $reaction = 'hundred';
        }

        $reactions = is_array($metadata['reactions'] ?? null) ? $metadata['reactions'] : [];
        $actorPublicId = strtolower((string) Auth::user()->public_id);

        // Logic to toggle reaction (one per user)
        $alreadyReacted = false;
        foreach ($reactions as $key => $ids) {
            if (in_array($actorPublicId, $ids)) {
                $reactions[$key] = array_values(array_diff($ids, [$actorPublicId]));
                if ($key === $reaction) {
                    $alreadyReacted = true;
                }
            }
        }

        if (! $alreadyReacted) {
            $reactions[$reaction][] = $actorPublicId;
        }

        $metadata['reactions'] = array_filter($reactions);
        $message->forceFill(['metadata' => $metadata])->save();

        $message = $message->fresh(['user', 'media', 'replyTo.user', 'chat']);
        \App\Services\Chat\ChatEvents::messageUpdated($message, $chat->type ?? 'dm');

        return (new ChatMessageResource($message))->additional(["meta" => ["active" => ! $alreadyReacted]]);
    }

    /**
     * Pin a message.
     */
    public function pinMessage(Request $request, Chat $chat, string $messagePublicId): ChatMessageResource
    {
        $message = ChatMessage::where('public_id', $messagePublicId)->where('chat_id', $chat->id)->firstOrFail();
        
        $this->authorize('pin', $message);

        $metadata = is_array($message->metadata) ? $message->metadata : [];
        if (($metadata['is_deleted'] ?? false) === true) {
            abort(422, 'Deleted messages cannot be pinned.');
        }

        $metadata['is_pinned'] = true;
        $metadata['pinned_at'] = now()->toIso8601String();
        $metadata['pinned_by_user_public_id'] = (string) Auth::user()->public_id;
        $metadata['pinned_by_user_name'] = (string) Auth::user()->name;

        $message->forceFill(['metadata' => $metadata])->save();

        $message = $message->fresh(['user', 'media', 'replyTo.user', 'chat']);
        \App\Services\Chat\ChatEvents::messageUpdated($message, $chat->type ?? 'dm');

        return new ChatMessageResource($message);
    }

    /**
     * Unpin a message.
     */
    public function unpinMessage(Request $request, Chat $chat, string $messagePublicId): ChatMessageResource
    {
        $message = ChatMessage::where('public_id', $messagePublicId)->where('chat_id', $chat->id)->firstOrFail();
        
        $this->authorize('pin', $message);

        $metadata = is_array($message->metadata) ? $message->metadata : [];
        unset($metadata['is_pinned'], $metadata['pinned_at'], $metadata['pinned_by_user_public_id'], $metadata['pinned_by_user_name']);

        $message->forceFill(['metadata' => $metadata])->save();

        $message = $message->fresh(['user', 'media', 'replyTo.user', 'chat']);
        \App\Services\Chat\ChatEvents::messageUpdated($message, $chat->type ?? 'dm');

        return new ChatMessageResource($message);
    }


    /**
     * Send typing indicator.
     */
    public function typing(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('view', $chat);

        broadcast(new \App\Events\Chat\UserTyping(
            chatPublicId: $chat->public_id,
            user: Auth::user(),
            chatType: $chat->type
        ))->toOthers();

        return response()->json(['status' => 'ok']);
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
            ->with(['inviter', 'chat'])
            ->latest()
            ->get();

        return response()->json([
            'data' => $invites->map(fn ($invite) => [
                'id' => $invite->public_id,
                'inviter_name' => $invite->inviter?->name,
                'inviter_public_id' => $invite->inviter?->public_id,
                'avatar_url' => $invite->inviter?->avatar_url,
                'sent_at' => $invite->created_at?->diffForHumans(),
                'type' => $invite->chat_id ? 'group' : 'dm',
                'chat_name' => $invite->chat?->name,
                'chat_public_id' => $invite->chat?->public_id,
            ])
        ]);
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
        $invitee = User::where('public_id', $request->input('invitee_public_id'))->firstOrFail();
        
        abort_if($invitee->id === $userId, 422, 'You cannot invite yourself.');

        $existingChat = Chat::where('type', 'dm')
            ->whereHas('participants', fn ($q) => $q->where('user_id', $userId))
            ->whereHas('participants', fn ($q) => $q->where('user_id', $invitee->id))
            ->exists();

        if ($existingChat) {
            return response()->json(['message' => 'Chat already exists.'], 422);
        }

        $invite = ChatInvite::create([
            'inviter_id' => $userId,
            'invitee_id' => $invitee->id,
            'type' => 'dm',
            'expires_at' => now()->addDays(7),
        ]);

        return response()->json([
            'status' => 'invite_sent',
            'invite_id' => $invite->public_id,
        ], 201);
    }

    /**
     * Accept an invite.
     */
    public function acceptInvite(string $invitePublicId): JsonResponse
    {
        $invite = ChatInvite::where('public_id', $invitePublicId)
            ->where('invitee_id', Auth::id())
            ->pending()
            ->firstOrFail();

        $chat = DB::transaction(function () use ($invite) {
            $chat = null;
            if ($invite->chat_id) {
                // Group invite
                $chat = Chat::findOrFail($invite->chat_id);
                $chat->allParticipants()->syncWithoutDetaching([
                    $invite->invitee_id => ['role' => 'member', 'public_id' => (string) Str::ulid()]
                ]);
            } else {
                // DM invite
                $chat = Chat::create(['type' => 'dm', 'public_id' => (string) Str::ulid()]);
                $chat->participants()->attach([
                    $invite->inviter_id => ['role' => 'member', 'public_id' => (string) Str::ulid()],
                    $invite->invitee_id => ['role' => 'member', 'public_id' => (string) Str::ulid()],
                ]);
            }

            $invite->markAccepted();
            return $chat;
        });

        return response()->json([
            'status' => 'ok',
            'chat_public_id' => $chat->public_id,
            'data' => new ChatResource($chat->load(['participants', 'latestVisibleMessage.user', 'latestVisibleMessage.media'])),
        ]);
    }

    /**
     * Decline an invite.
     */
    public function declineInvite(string $invitePublicId): JsonResponse
    {
        $invite = ChatInvite::where('public_id', $invitePublicId)
            ->where('invitee_id', Auth::id())
            ->pending()
            ->firstOrFail();

        $invite->markRejected();

        return response()->json(['status' => 'ok']);
    }

    // =========================================================================
    // Groups
    // =========================================================================

    /**
     * Create a group chat.
     */
    public function createGroup(Request $request): JsonResponse
    {
        $validated = $request->validate([
            'name' => ['nullable', 'string', 'max:100'],
            'member_ids' => ['sometimes', 'array'],
        ]);

        $chat = DB::transaction(function () use ($validated) {
            $chat = Chat::create([
                'public_id' => (string) Str::ulid(),
                'name' => $validated['name'] ?? 'New Group Chat',
                'type' => 'group',
                'created_by' => Auth::id(),
            ]);

            $chat->participants()->attach(Auth::id(), [
                'role' => 'owner',
                'public_id' => (string) Str::ulid(),
            ]);

            if (! empty($validated['member_ids'])) {
                foreach ($validated['member_ids'] as $memberPublicId) {
                    $user = User::where('public_id', $memberPublicId)->first();
                    if ($user && $user->id !== Auth::id()) {
                        $chat->participants()->attach($user->id, [
                            'role' => 'member',
                            'public_id' => (string) Str::ulid(),
                        ]);
                    }
                }
            }

            return $chat;
        });

        return (new ChatResource($chat->load(['participants', 'latestVisibleMessage.user', 'latestVisibleMessage.media'])))
            ->response()
            ->setStatusCode(201);
    }

    /**
     * Rename a group chat.
     */
    public function rename(Request $request, Chat $chat): ChatResource
    {
        $this->authorize('update', $chat);

        $validated = $request->validate([
            'name' => ['required', 'string', 'max:100'],
        ]);

        $chat->update(['name' => $validated['name']]);

        return new ChatResource($chat->load(['participants', 'latestVisibleMessage.user', 'latestVisibleMessage.media']));
    }

    /**
     * Add a member to a group.
     */
    public function addMember(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('manageMembers', $chat);

        $validated = $request->validate([
            'user_public_id' => ['required', 'string', 'exists:users,public_id'],
        ]);

        $user = User::where('public_id', $validated['user_public_id'])->firstOrFail();
        
        if ($chat->participants()->where('user_id', $user->id)->exists()) {
            abort(422, 'User is already a member.');
        }

        $invite = ChatInvite::create([
            'inviter_id' => Auth::id(),
            'invitee_id' => $user->id,
            'chat_id' => $chat->id,
            'type' => 'group',
            'expires_at' => now()->addDays(7),
            'public_id' => (string) Str::ulid(),
        ]);

        return response()->json([
            'message' => 'Invite sent successfully.',
            'invite_id' => $invite->id,
            'public_id' => $invite->public_id,
        ], 201);
    }

    /**
     * Remove a member from a group.
     */
    public function removeMember(Request $request, Chat $chat, string $memberPublicId): ChatResource
    {
        $this->authorize('manageMembers', $chat);

        $participant = $chat->participants()->where('public_id', $memberPublicId)->firstOrFail();
        
        abort_if($participant->id === Auth::id(), 422, 'Cannot remove yourself.');
        
        $role = $participant->pivot->role ?? 'member';
        abort_if($role === 'owner', 422, 'Owners cannot be removed.');

        $chat->participants()->detach($participant->id);

        $chat->load(['participants', 'latestVisibleMessage.user', 'latestVisibleMessage.media']);
        return new ChatResource($chat);
    }

    // =========================================================================
    // Media
    // =========================================================================

    /**
     * List media for a chat.
     */
    public function media(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('view', $chat);

        $filter = $request->input('filter');
        $perPage = max(1, min(50, $request->integer('per_page', 24)));

        $paginator = app(ChatMediaService::class)->getChatMedia(
            $chat,
            $filter === 'all' ? null : $filter,
            $perPage
        );

        return response()->json([
            'data' => $paginator->items(), // Note: would be better to use a MediaResource
            'has_more' => $paginator->hasMorePages(),
        ]);
    }

    /**
     * Get storage statistics for a chat.
     */
    public function storageStats(Chat $chat): JsonResponse
    {
        $this->authorize('view', $chat);

        $stats = app(ChatMediaService::class)->getChatStorageStats($chat);

        return response()->json(['data' => $stats]);
    }

    /**
     * Delete media from a chat.
     */
    public function deleteMedia(Request $request, Chat $chat, int $mediaId): JsonResponse
    {
        $this->authorize('view', $chat);

        /** @var Media $media */
        $media = Media::findOrFail($mediaId);
        $message = $media->model;

        abort_if(!$message instanceof ChatMessage || $message->chat_id !== $chat->id, 404);

        $this->authorize('delete', $message);

        app(ChatMediaService::class)->deleteMedia($mediaId, Auth::user());

        return response()->json(['status' => 'deleted']);
    }

    // =========================================================================
    // Group Management
    // =========================================================================

    /**
     * Leave a group chat.
     */
    public function leave(Chat $chat): JsonResponse
    {
        $this->authorize('leave', $chat);

        $this->groupChatService->leaveGroup($chat, Auth::user());

        return response()->json(['message' => 'You have left the group.']);
    }

    /**
     * Kick a member from the group.
     */
    public function kick(Chat $chat, string $userPublicId): JsonResponse
    {
        $this->authorize('kick', $chat);

        $user = User::where('public_id', $userPublicId)->firstOrFail();

        $this->groupChatService->kickMember($chat, $user, Auth::user());

        return response()->json(['message' => 'Member kicked successfully.']);
    }

    /**
     * Delete a group chat.
     */
    public function delete(Request $request, Chat $chat): JsonResponse
    {
        $this->authorize('delete', $chat);

        $request->validate(['password' => 'required|string']);

        $this->groupChatService->deleteGroup($chat, Auth::user(), $request->input('password'));

        return response()->json(['message' => 'Group deleted successfully.']);
    }

    /**
     * Rejoin a group chat.
     */
    public function rejoin(Chat $chat): ChatResource
    {
        $this->authorize('rejoin', $chat);

        $this->groupChatService->rejoinGroup($chat, Auth::user());

        return new ChatResource($chat);
    }

    protected function enforceRateLimit(int $userId): void
    {
        $key = 'chat_send_limit_'.$userId;
        $count = (int) Cache::get($key, 0);

        if ($count >= self::SEND_MAX_PER_WINDOW) {
            abort(429, 'Too many messages. Please wait a moment.');
        }

        Cache::put($key, $count + 1, self::SEND_WINDOW_SECONDS);
    }
}
