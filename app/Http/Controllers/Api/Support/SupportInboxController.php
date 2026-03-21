<?php

namespace App\Http\Controllers\Api\Support;

use App\Contracts\SupportConversationServiceContract;
use App\Contracts\SupportSurveyServiceContract;
use App\Http\Controllers\Controller;
use App\Http\Resources\Support\SupportConversationResource;
use App\Http\Resources\Support\SupportSurveyInviteResource;
use App\Http\Resources\Support\SupportMessageResource;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\SupportSurveyInvite;
use App\Models\User;
use App\Events\Support\SupportTypingUpdated;
use App\Services\Chat\ChatPipeline;
use App\Services\Support\SupportRealtimeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class SupportInboxController extends Controller
{
    public function __construct(
        protected SupportConversationServiceContract $supportService,
        protected ChatPipeline $chatPipeline,
        protected SupportRealtimeService $supportRealtimeService,
        protected SupportSurveyServiceContract $supportSurveyService
    ) {}

    public function index(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SupportConversation::class);

        $scope = (string) $request->input('scope', 'mine');
        if (! in_array($scope, ['mine', 'unassigned', 'all'], true)) {
            $scope = 'mine';
        }

        $paginator = $this->supportService->inbox(
            $request->user(),
            $scope,
            $request->only(['status', 'q', 'per_page'])
        );

        return $this->paginatedConversationResponse($paginator, $request, [
            'realtime' => $this->supportRealtimeService->agentRealtimeMeta($request->user()),
            'ui_timers' => $this->supportUiTimerMeta(),
        ]);
    }

    public function show(Request $request, SupportConversation $conversation): JsonResponse
    {
        $this->authorize('viewAny', SupportConversation::class);

        try {
            $conversation = $this->supportService->getConversationForActor($conversation, $request->user());
        } catch (AuthorizationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        }

        $conversation = $this->hydrateConversation($conversation, true);

        return response()->json([
            'data' => new SupportConversationResource($conversation, includePrivateNotes: true),
            'meta' => [
                'realtime' => $this->supportRealtimeService->agentRealtimeMeta($request->user(), $conversation->public_id),
                'ui_timers' => $this->supportUiTimerMeta(),
            ],
        ]);
    }

    public function messages(Request $request, SupportConversation $conversation): JsonResponse
    {
        $this->authorize('viewAny', SupportConversation::class);

        try {
            $conversation = $this->supportService->getConversationForActor($conversation, $request->user());
        } catch (AuthorizationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        }

        $validated = $request->validate([
            'before' => ['nullable', 'string', 'max:64'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $limit = (int) ($validated['limit'] ?? 30);
        $beforePublicId = trim((string) ($validated['before'] ?? ''));
        $adapterEnabled = (bool) config('chat_pipeline.support_chat_adapter_enabled', true);
        $adapterFailed = false;
        $adapterUsed = false;
        $messages = [];
        $hasMoreBefore = false;

        if ($adapterEnabled) {
            try {
                $messages = $this->chatPipeline->fetchMessages(
                    (string) config('chat_pipeline.support_chat_adapter', 'support_live'),
                    [
                        'conversation' => $conversation,
                        'include_private_notes' => true,
                    ],
                    $limit + 1,
                    $beforePublicId !== '' ? $beforePublicId : null
                );

                $hasMoreBefore = count($messages) > $limit;
                if ($hasMoreBefore) {
                    $messages = array_slice($messages, -$limit);
                }
                $messages = array_values($messages);
                $adapterUsed = true;
            } catch (\Throwable $exception) {
                Log::warning('[SupportInbox] Adapter messages failed, falling back to service query.', [
                    'conversation' => $conversation->public_id,
                    'error' => $exception->getMessage(),
                ]);
                $adapterFailed = true;
            }
        }

        if ($adapterUsed && ! $adapterFailed && empty($messages) && $beforePublicId !== '') {
            return response()->json([
                'data' => [],
                'meta' => [
                    'has_more_before' => false,
                    'oldest_id' => null,
                    'newest_id' => null,
                    'limit' => $limit,
                ],
            ]);
        }

        if (! $adapterUsed || $adapterFailed) {
            $query = SupportMessage::query()
                ->where('conversation_id', $conversation->id);

            if ($beforePublicId !== '') {
                $beforeId = SupportMessage::query()
                    ->where('conversation_id', $conversation->id)
                    ->where('public_id', $beforePublicId)
                    ->value('id');

                if (! $beforeId) {
                    return response()->json([
                        'data' => [],
                        'meta' => [
                            'has_more_before' => false,
                            'oldest_id' => null,
                            'newest_id' => null,
                            'limit' => $limit,
                        ],
                    ]);
                }

                $query->where('id', '<', (int) $beforeId);
            }

            $messagesDesc = $query
                ->with(['sender:id,public_id,name,email', 'media'])
                ->orderByDesc('id')
                ->limit($limit + 1)
                ->get();

            $hasMoreBefore = $messagesDesc->count() > $limit;
            if ($hasMoreBefore) {
                $messagesDesc = $messagesDesc->slice(0, $limit)->values();
            }

            $messages = SupportMessageResource::collection($messagesDesc->reverse()->values())
                ->collection
                ->map(fn (SupportMessageResource $resource): array => $resource->toArray($request))
                ->all();
        }

        $oldestId = $messages[0]['id'] ?? null;
        $newestId = ! empty($messages) ? $messages[count($messages) - 1]['id'] : null;

        return response()->json([
            'data' => $messages,
            'meta' => [
                'has_more_before' => $hasMoreBefore,
                'oldest_id' => $oldestId,
                'newest_id' => $newestId,
                'limit' => $limit,
            ],
        ]);
    }

    public function typing(Request $request, SupportConversation $conversation): JsonResponse
    {
        $this->authorize('respondAsAgent', $conversation);

        try {
            $conversation = $this->supportService->getConversationForActor($conversation, $request->user());
        } catch (AuthorizationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        }

        $validated = $request->validate([
            'is_typing' => ['nullable', 'boolean'],
        ]);

        if ($rateLimited = $this->enforceAgentRateLimit(
            action: 'agent_typing',
            request: $request,
            conversation: $conversation,
            soft: true
        )) {
            return $rateLimited;
        }

        $isTyping = (bool) ($validated['is_typing'] ?? true);
        broadcast(new SupportTypingUpdated(
            conversation: $conversation,
            actorType: 'agent',
            actorName: (string) $request->user()->name,
            isTyping: $isTyping
        ))->toOthers();

        return response()->json([
            'status' => 'ok',
        ]);
    }

    public function storeAgentMessage(Request $request, SupportConversation $conversation): JsonResponse
    {
        $this->authorize('respondAsAgent', $conversation);

        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:5000'],
            'is_private_note' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => ['file', 'max:5120'],
        ]);
        $files = $this->normalizeUploadedFiles($request->file('files', []));
        $body = trim((string) ($validated['body'] ?? ''));
        if ($body === '' && empty($files)) {
            return response()->json(['message' => 'Message body or attachment is required.'], 422);
        }

        if ($rateLimited = $this->enforceAgentRateLimit(
            action: 'agent_send',
            request: $request,
            conversation: $conversation
        )) {
            return $rateLimited;
        }

        $payload = array_merge($validated, [
            'body' => $body,
            'files' => $files,
        ]);

        $messagePayload = null;

        if (config('chat_pipeline.support_chat_adapter_enabled', true)) {
            try {
                $messagePayload = $this->chatPipeline->sendMessage(
                    (string) config('chat_pipeline.support_chat_adapter', 'support_live'),
                    [
                        'conversation' => $conversation,
                        'actor' => $request->user(),
                        'as_agent' => true,
                    ],
                    $payload
                );
            } catch (AuthorizationException $exception) {
                return response()->json(['message' => $exception->getMessage()], 403);
            } catch (\InvalidArgumentException $exception) {
                return response()->json(['message' => $exception->getMessage()], 422);
            } catch (\Throwable $exception) {
                Log::warning('[SupportInbox] Adapter storeAgentMessage failed, falling back to service.', [
                    'conversation' => $conversation->public_id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if ($messagePayload === null) {
            try {
                $message = $this->supportService->addAgentMessage($conversation, $request->user(), $payload);
                $messagePayload = (new SupportMessageResource($message->load(['sender', 'media'])))->toArray($request);
            } catch (\InvalidArgumentException $exception) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }
        }

        $conversation = $conversation->fresh()->load([
            'requester:id,public_id,name,email',
            'assignee:id,public_id,name,email',
            'endedBy:id,public_id,name,email',
            'skill:id,public_id,name,slug,department',
            'latestMessage.sender:id,public_id,name,email',
            'latestMessage.media',
            'messages.sender:id,public_id,name,email',
            'messages.media',
        ]);

        return response()->json([
            'message' => 'Reply sent successfully.',
            'data' => $messagePayload,
            'conversation' => new SupportConversationResource($conversation, includePrivateNotes: true),
            'meta' => [
                'realtime' => $this->supportRealtimeService->agentRealtimeMeta($request->user(), $conversation->public_id),
            ],
        ], 201);
    }

    public function assign(Request $request, SupportConversation $conversation): JsonResponse
    {
        $this->authorize('assign', $conversation);

        $validated = $request->validate([
            'agent_public_id' => ['required', 'string', 'exists:users,public_id'],
        ]);

        $agent = User::where('public_id', $validated['agent_public_id'])->firstOrFail();

        try {
            $conversation = $this->supportService->assignConversation($conversation, $agent, $request->user());
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $conversation->load([
            'requester:id,public_id,name,email',
            'assignee:id,public_id,name,email',
            'endedBy:id,public_id,name,email',
            'skill:id,public_id,name,slug,department',
            'latestMessage.sender:id,public_id,name,email',
            'latestMessage.media',
            'messages.sender:id,public_id,name,email',
            'messages.media',
        ]);

        return response()->json([
            'message' => 'Conversation assigned successfully.',
            'data' => new SupportConversationResource($conversation, includePrivateNotes: true),
            'meta' => [
                'realtime' => $this->supportRealtimeService->agentRealtimeMeta($request->user(), $conversation->public_id),
            ],
        ]);
    }

    public function resolve(Request $request, SupportConversation $conversation): JsonResponse
    {
        $this->authorize('resolve', $conversation);

        $conversation = $this->supportService->resolveConversation($conversation, $request->user());
        $surveyBundle = [
            SupportSurveyInvite::TYPE_CSAT => null,
            SupportSurveyInvite::TYPE_NPS => null,
        ];
        try {
            $surveyBundle = $this->supportSurveyService->issuePostResolutionSurveyBundle($conversation, $request->user());
        } catch (\Throwable $exception) {
            Log::warning('[SupportInbox] Failed to issue post-resolution survey bundle.', [
                'conversation' => $conversation->public_id,
                'error' => $exception->getMessage(),
            ]);
        }

        $conversation->load([
            'requester:id,public_id,name,email',
            'assignee:id,public_id,name,email',
            'endedBy:id,public_id,name,email',
            'skill:id,public_id,name,slug,department',
            'latestMessage.sender:id,public_id,name,email',
            'latestMessage.media',
            'messages.sender:id,public_id,name,email',
            'messages.media',
        ]);

        return response()->json([
            'message' => 'Conversation resolved successfully.',
            'data' => new SupportConversationResource($conversation, includePrivateNotes: true),
            'meta' => [
                'realtime' => $this->supportRealtimeService->agentRealtimeMeta($request->user(), $conversation->public_id),
                'survey_invite_bundle' => $this->serializeSurveyInviteBundle($surveyBundle, $request),
            ],
        ]);
    }

    public function endConversation(Request $request, SupportConversation $conversation): JsonResponse
    {
        $this->authorize('resolve', $conversation);

        try {
            $conversation = $this->supportService->closeConversation(
                $conversation,
                $request->user(),
                null,
                ['ended_by_name' => (string) ($request->input('ended_by_name') ?? '')]
            );
        } catch (AuthorizationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $surveyBundle = [
            SupportSurveyInvite::TYPE_CSAT => null,
            SupportSurveyInvite::TYPE_NPS => null,
        ];
        try {
            $surveyBundle = $this->supportSurveyService->issuePostResolutionSurveyBundle($conversation, $request->user());
        } catch (\Throwable $exception) {
            Log::warning('[SupportInbox] Failed to issue post-close survey bundle.', [
                'conversation' => $conversation->public_id,
                'error' => $exception->getMessage(),
            ]);
        }

        $conversation->load([
            'requester:id,public_id,name,email',
            'assignee:id,public_id,name,email',
            'endedBy:id,public_id,name,email',
            'skill:id,public_id,name,slug,department',
            'latestMessage.sender:id,public_id,name,email',
            'latestMessage.media',
            'messages.sender:id,public_id,name,email',
            'messages.media',
        ]);

        return response()->json([
            'message' => 'Conversation ended successfully.',
            'data' => new SupportConversationResource($conversation, includePrivateNotes: true),
            'meta' => [
                'realtime' => $this->supportRealtimeService->agentRealtimeMeta($request->user(), $conversation->public_id),
                'survey_invite_bundle' => $this->serializeSurveyInviteBundle($surveyBundle, $request),
            ],
        ]);
    }

    public function agents(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SupportConversation::class);

        $agents = $this->supportService->eligibleAgents()->map(fn (User $agent) => [
            'id' => $agent->public_id,
            'name' => $agent->name,
            'email' => $agent->email,
            'status' => $agent->status,
        ]);

        return response()->json([
            'data' => $agents,
        ]);
    }

    public function realtimeToken(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SupportConversation::class);

        return response()->json([
            'data' => $this->supportRealtimeService->agentRealtimeMeta($request->user()),
        ]);
    }

    /**
     * @param  array<string, mixed>  $meta
     */
    protected function paginatedConversationResponse(LengthAwarePaginator $paginator, Request $request, array $meta = []): JsonResponse
    {
        $data = $paginator->getCollection()
            ->map(fn (SupportConversation $conversation) => (new SupportConversationResource($conversation, includePrivateNotes: true))->toArray($request))
            ->values();

        return response()->json([
            'data' => $data,
            'meta' => array_merge([
                'current_page' => $paginator->currentPage(),
                'last_page' => $paginator->lastPage(),
                'per_page' => $paginator->perPage(),
                'total' => $paginator->total(),
            ], $meta),
        ]);
    }

    protected function hydrateConversation(SupportConversation $conversation, bool $includePrivateNotes): SupportConversation
    {
        return $conversation->load([
            'requester:id,public_id,name,email',
            'assignee:id,public_id,name,email',
            'endedBy:id,public_id,name,email',
            'skill:id,public_id,name,slug,department',
            'latestMessage.sender:id,public_id,name,email',
            'latestMessage.media',
            'messages' => function ($query) use ($includePrivateNotes): void {
                if (! $includePrivateNotes) {
                    $query->where('is_private_note', false);
                }

                $query->with(['sender:id,public_id,name,email', 'media'])
                    ->orderBy('created_at');
            },
        ]);
    }

    protected function enforceAgentRateLimit(
        string $action,
        Request $request,
        SupportConversation $conversation,
        bool $soft = false
    ): ?JsonResponse {
        $config = (array) config("support_chat.rate_limits.{$action}", []);
        $maxAttempts = max(1, (int) ($config['max_attempts'] ?? 12));
        $decaySeconds = max(1, (int) ($config['decay_seconds'] ?? 30));
        $actorPublicId = (string) ($request->user()?->public_id ?? 'unknown');

        $key = implode(':', [
            'support',
            'inbox',
            $action,
            (string) $conversation->public_id,
            'agent:'.$actorPublicId,
        ]);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $retryAfter = max(1, (int) RateLimiter::availableIn($key));

            if ($soft) {
                return response()->json([
                    'status' => 'ok',
                    'throttled' => true,
                    'meta' => [
                        'retry_after' => $retryAfter,
                    ],
                ]);
            }

            return response()->json([
                'message' => 'You are sending messages too quickly. Please slow down.',
                'meta' => [
                    'retry_after' => $retryAfter,
                    'rate_limited' => true,
                ],
            ], 429);
        }

        RateLimiter::hit($key, $decaySeconds);

        return null;
    }

    /**
     * @param  mixed  $files
     * @return array<UploadedFile>
     */
    protected function normalizeUploadedFiles(mixed $files): array
    {
        if ($files instanceof UploadedFile) {
            return [$files];
        }

        if (! is_array($files)) {
            return [];
        }

        return array_values(array_filter($files, fn ($file): bool => $file instanceof UploadedFile));
    }

    /**
     * @param  array<string, SupportSurveyInvite|null>  $bundle
     * @return array<string, mixed>
     */
    protected function serializeSurveyInviteBundle(array $bundle, Request $request): array
    {
        $serialized = [
            SupportSurveyInvite::TYPE_CSAT => null,
            SupportSurveyInvite::TYPE_NPS => null,
        ];

        foreach ([SupportSurveyInvite::TYPE_CSAT, SupportSurveyInvite::TYPE_NPS] as $type) {
            $invite = $bundle[$type] ?? null;
            if (! $invite instanceof SupportSurveyInvite) {
                continue;
            }

            $serialized[$type] = (new SupportSurveyInviteResource($invite, includeDefinition: true))->toArray($request);
        }

        return $serialized;
    }

    /**
     * @return array<string, mixed>
     */
    protected function supportUiTimerMeta(): array
    {
        $tickMs = max(250, (int) config('support_chat.ui_timers.tick_ms', 1000));
        $warnMinutes = max(1, (int) config('support_chat.ui_timers.last_response_warn_minutes', 5));
        $alertMinutes = max($warnMinutes, (int) config('support_chat.ui_timers.last_response_alert_minutes', 15));

        return [
            'tick_ms' => $tickMs,
            'last_response_warn_minutes' => $warnMinutes,
            'last_response_alert_minutes' => $alertMinutes,
            'last_response_include_bot' => (bool) config('support_chat.ui_timers.last_response_include_bot', true),
        ];
    }
}
