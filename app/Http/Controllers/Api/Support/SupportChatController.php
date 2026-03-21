<?php

namespace App\Http\Controllers\Api\Support;

use App\Contracts\SupportConversationServiceContract;
use App\Contracts\SupportSurveyServiceContract;
use App\Events\Support\SupportTypingUpdated;
use App\Http\Controllers\Controller;
use App\Http\Resources\Support\SupportConversationResource;
use App\Http\Resources\Support\SupportSurveyInviteResource;
use App\Http\Resources\Support\SupportMessageResource;
use App\Models\SupportConversation;
use App\Models\SupportSurveyInvite;
use App\Models\User;
use App\Services\RecaptchaService;
use App\Services\Chat\ChatPipeline;
use App\Services\Support\SupportGuestSessionService;
use App\Services\Support\SupportRealtimeService;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\RateLimiter;

class SupportChatController extends Controller
{
    public function __construct(
        protected SupportConversationServiceContract $supportService,
        protected SupportSurveyServiceContract $supportSurveyService,
        protected RecaptchaService $recaptcha,
        protected ChatPipeline $chatPipeline,
        protected SupportRealtimeService $supportRealtimeService,
        protected SupportGuestSessionService $guestSessionService
    ) {}

    public function availability(): JsonResponse
    {
        return response()->json([
            'data' => $this->supportService->availability(),
        ]);
    }

    public function store(Request $request): JsonResponse
    {
        $actor = $this->resolveActor($request);

        $rules = [
            'subject' => ['nullable', 'string', 'max:255'],
            'initial_message' => ['required', 'string', 'max:5000'],
            'priority' => ['nullable', 'string', 'in:low,normal,high,urgent'],
            'conversation_type' => ['nullable', 'string', 'in:inquiry,inquery,complaint'],
            'channel' => ['nullable', 'string', 'max:64'],
            'source_url' => ['nullable', 'string', 'max:2048'],
            'ai_enabled' => ['nullable', 'boolean'],
            'metadata' => ['nullable', 'array'],
            'guest_name' => ['nullable', 'string', 'max:120'],
            'guest_email' => ['nullable', 'email', 'max:255'],
            'website_url' => ['nullable', 'string', 'max:0'],
            'recaptcha_token' => ['nullable', 'string'],
            'recaptcha_v2_token' => ['nullable', 'string'],
        ];

        if (! $actor) {
            $rules['guest_name'][] = 'required';
            $rules['guest_email'][] = 'required';
            if ((bool) config('recaptcha.enabled', false)) {
                $rules['recaptcha_token'][] = 'required_without:recaptcha_v2_token';
            }
        }

        $validated = $request->validate($rules);

        if (! $actor) {
            if ($securityFailure = $this->verifyGuestRecaptcha($validated, $request, 'support_chat_open')) {
                return $securityFailure;
            }
        }

        try {
            $conversation = $this->supportService->openConversation($validated, $actor);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        $includePrivateNotes = $actor ? $this->supportService->canOperateAsAgent($actor) : false;
        $conversation = $this->hydrateConversation($conversation, $includePrivateNotes);

        $response = response()->json([
            'message' => 'Support conversation started successfully.',
            'data' => new SupportConversationResource(
                $conversation,
                includePrivateNotes: $includePrivateNotes,
                exposeGuestToken: ! $actor && ! empty($conversation->guest_token)
            ),
            'meta' => [
                'availability' => $this->supportService->availability(),
                'realtime' => $this->supportRealtimeService->conversationRealtimeMeta(
                    $conversation,
                    $actor,
                    ! $actor ? (string) $conversation->guest_token : null
                ),
            ],
        ], 201);

        if (! $actor) {
            $response->withCookie($this->guestSessionService->issueForConversation($conversation, $request));
        }

        return $response;
    }

    public function show(Request $request, SupportConversation $conversation): JsonResponse
    {
        $actor = $this->resolveActor($request);
        $guestToken = (string) ($request->input('guest_token') ?? $request->header('X-Support-Guest-Token', ''));
        $isGuestSessionAuthorized = ! $actor && $this->guestSessionService->hasConversationAccess($request, $conversation);
        if ($isGuestSessionAuthorized) {
            $guestToken = (string) $conversation->guest_token;
        }

        try {
            $conversation = $this->supportService->getConversationForActor($conversation, $actor, $guestToken);
        } catch (AuthorizationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        }

        $includePrivateNotes = $actor ? $this->supportService->canOperateAsAgent($actor) : false;
        $conversation = $this->hydrateConversation($conversation, $includePrivateNotes);

        $response = response()->json([
            'data' => new SupportConversationResource($conversation, includePrivateNotes: $includePrivateNotes),
            'meta' => [
                'availability' => $this->supportService->availability(),
                'realtime' => $this->supportRealtimeService->conversationRealtimeMeta(
                    $conversation,
                    $actor,
                    ! $actor ? $guestToken : null
                ),
            ],
        ]);

        if ($isGuestSessionAuthorized) {
            $cookie = $this->guestSessionService->refreshCookieFromRequest($request);
            if ($cookie) {
                $response->withCookie($cookie);
            }
        }

        return $response;
    }

    public function endConversation(Request $request, SupportConversation $conversation): JsonResponse
    {
        $actor = $this->resolveActor($request);
        $guestToken = (string) ($request->input('guest_token') ?? $request->header('X-Support-Guest-Token', ''));
        $isGuestSessionAuthorized = ! $actor && $this->guestSessionService->hasConversationAccess($request, $conversation);
        if ($isGuestSessionAuthorized) {
            $guestToken = (string) $conversation->guest_token;
        }

        try {
            $conversation = $this->supportService->closeConversation(
                $conversation,
                $actor,
                $guestToken,
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
            $surveyBundle = $this->supportSurveyService->issuePostResolutionSurveyBundle(
                $conversation,
                $actor && $this->supportService->canOperateAsAgent($actor) ? $actor : null
            );
        } catch (\Throwable $exception) {
            Log::warning('[SupportChat] Failed to issue post-close survey bundle.', [
                'conversation' => $conversation->public_id,
                'error' => $exception->getMessage(),
            ]);
        }

        $includePrivateNotes = $actor ? $this->supportService->canOperateAsAgent($actor) : false;
        $conversation = $this->hydrateConversation($conversation, $includePrivateNotes);

        $response = response()->json([
            'message' => 'Conversation ended successfully.',
            'data' => new SupportConversationResource(
                $conversation,
                includePrivateNotes: $includePrivateNotes,
                exposeGuestToken: ! $actor && ! empty($conversation->guest_token)
            ),
            'meta' => [
                'availability' => $this->supportService->availability(),
                'realtime' => $this->supportRealtimeService->conversationRealtimeMeta(
                    $conversation,
                    $actor,
                    ! $actor ? $guestToken : null
                ),
                'survey_invite_bundle' => $this->serializeSurveyInviteBundle($surveyBundle, $request),
            ],
        ]);

        if ($isGuestSessionAuthorized) {
            $cookie = $this->guestSessionService->refreshCookieFromRequest($request);
            if ($cookie) {
                $response->withCookie($cookie);
            }
        }

        return $response;
    }

    public function storeCustomerMessage(Request $request, SupportConversation $conversation): JsonResponse
    {
        $actor = $this->resolveActor($request);
        $guestToken = (string) ($request->input('guest_token') ?? $request->header('X-Support-Guest-Token', ''));
        $isGuestSessionAuthorized = ! $actor && $this->guestSessionService->hasConversationAccess($request, $conversation);
        if ($isGuestSessionAuthorized) {
            $guestToken = (string) $conversation->guest_token;
        }
        $validated = $request->validate([
            'body' => ['nullable', 'string', 'max:5000'],
            'metadata' => ['nullable', 'array'],
            'files' => ['nullable', 'array', 'max:10'],
            'files.*' => ['file', 'max:5120'],
        ]);
        $files = $this->normalizeUploadedFiles($request->file('files', []));
        $body = trim((string) ($validated['body'] ?? ''));
        if ($body === '' && empty($files)) {
            return response()->json(['message' => 'Message body or attachment is required.'], 422);
        }

        if ($rateLimited = $this->enforceRateLimit(
            action: 'customer_send',
            request: $request,
            conversation: $conversation,
            actor: $actor,
            guestToken: $guestToken
        )) {
            if ($isGuestSessionAuthorized) {
                $cookie = $this->guestSessionService->refreshCookieFromRequest($request);
                if ($cookie) {
                    $rateLimited->withCookie($cookie);
                }
            }
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
                        'actor' => $actor,
                        'guest_token' => $guestToken,
                        'as_agent' => false,
                    ],
                    $payload
                );
            } catch (AuthorizationException $exception) {
                return response()->json(['message' => $exception->getMessage()], 403);
            } catch (\InvalidArgumentException $exception) {
                return response()->json(['message' => $exception->getMessage()], 422);
            } catch (\Throwable $exception) {
                Log::warning('[SupportChat] Adapter storeCustomerMessage failed, falling back to service.', [
                    'conversation' => $conversation->public_id,
                    'error' => $exception->getMessage(),
                ]);
            }
        }

        if ($messagePayload === null) {
            try {
                $message = $this->supportService->addCustomerMessage($conversation, $payload, $actor, $guestToken);
                $messagePayload = (new SupportMessageResource($message->load(['sender', 'media'])))->toArray($request);
            } catch (AuthorizationException $exception) {
                return response()->json(['message' => $exception->getMessage()], 403);
            } catch (\InvalidArgumentException $exception) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }
        }

        $conversation = $conversation->fresh();
        $includePrivateNotes = $actor ? $this->supportService->canOperateAsAgent($actor) : false;
        $conversation = $this->hydrateConversation($conversation, $includePrivateNotes);

        $response = response()->json([
            'message' => 'Message sent successfully.',
            'data' => $messagePayload,
            'conversation' => new SupportConversationResource($conversation, includePrivateNotes: $includePrivateNotes),
            'meta' => [
                'availability' => $this->supportService->availability(),
                'realtime' => $this->supportRealtimeService->conversationRealtimeMeta(
                    $conversation,
                    $actor,
                    ! $actor ? $guestToken : null
                ),
            ],
        ], 201);

        if ($isGuestSessionAuthorized) {
            $cookie = $this->guestSessionService->refreshCookieFromRequest($request);
            if ($cookie) {
                $response->withCookie($cookie);
            }
        }

        return $response;
    }

    public function messages(Request $request, SupportConversation $conversation): JsonResponse
    {
        $actor = $this->resolveActor($request);
        $guestToken = (string) ($request->input('guest_token') ?? $request->header('X-Support-Guest-Token', ''));
        $isGuestSessionAuthorized = ! $actor && $this->guestSessionService->hasConversationAccess($request, $conversation);
        if ($isGuestSessionAuthorized) {
            $guestToken = (string) $conversation->guest_token;
        }

        try {
            $conversation = $this->supportService->getConversationForActor($conversation, $actor, $guestToken);
        } catch (AuthorizationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        }

        $validated = $request->validate([
            'before' => ['nullable', 'string', 'max:64'],
            'limit' => ['nullable', 'integer', 'min:1', 'max:100'],
        ]);

        $limit = (int) ($validated['limit'] ?? 30);
        $includePrivateNotes = $actor ? $this->supportService->canOperateAsAgent($actor) : false;

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
                        'include_private_notes' => $includePrivateNotes,
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
                Log::warning('[SupportChat] Adapter messages failed, falling back to service query.', [
                    'conversation' => $conversation->public_id,
                    'error' => $exception->getMessage(),
                ]);
                $adapterFailed = true;
            }
        }

        if ($adapterUsed && ! $adapterFailed && empty($messages) && $beforePublicId !== '') {
            // Adapter returned no rows for invalid `before` cursor; keep behavior identical.
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
            $query = \App\Models\SupportMessage::query()
                ->where('conversation_id', $conversation->id);

            if (! $includePrivateNotes) {
                $query->where('is_private_note', false);
            }

            if ($beforePublicId !== '') {
                $beforeId = \App\Models\SupportMessage::query()
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

        $response = response()->json([
            'data' => $messages,
            'meta' => [
                'has_more_before' => $hasMoreBefore,
                'oldest_id' => $oldestId,
                'newest_id' => $newestId,
                'limit' => $limit,
            ],
        ]);

        if ($isGuestSessionAuthorized) {
            $cookie = $this->guestSessionService->refreshCookieFromRequest($request);
            if ($cookie) {
                $response->withCookie($cookie);
            }
        }

        return $response;
    }

    public function typing(Request $request, SupportConversation $conversation): JsonResponse
    {
        $actor = $this->resolveActor($request);
        $guestToken = (string) ($request->input('guest_token') ?? $request->header('X-Support-Guest-Token', ''));
        $isGuestSessionAuthorized = ! $actor && $this->guestSessionService->hasConversationAccess($request, $conversation);
        if ($isGuestSessionAuthorized) {
            $guestToken = (string) $conversation->guest_token;
        }

        try {
            $conversation = $this->supportService->getConversationForActor($conversation, $actor, $guestToken);
        } catch (AuthorizationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        }

        $validated = $request->validate([
            'is_typing' => ['nullable', 'boolean'],
        ]);

        if ($rateLimited = $this->enforceRateLimit(
            action: 'customer_typing',
            request: $request,
            conversation: $conversation,
            actor: $actor,
            guestToken: $guestToken,
            soft: true
        )) {
            if ($isGuestSessionAuthorized) {
                $cookie = $this->guestSessionService->refreshCookieFromRequest($request);
                if ($cookie) {
                    $rateLimited->withCookie($cookie);
                }
            }
            return $rateLimited;
        }

        $isTyping = (bool) ($validated['is_typing'] ?? true);

        $actorType = 'customer';
        $actorName = (string) ($conversation->guest_name ?: 'Customer');

        if ($actor) {
            if ($this->supportService->canOperateAsAgent($actor)) {
                $actorType = 'agent';
            }

            $actorName = (string) $actor->name;
        }

        broadcast(new SupportTypingUpdated(
            conversation: $conversation,
            actorType: $actorType,
            actorName: $actorName,
            isTyping: $isTyping
        ))->toOthers();

        $response = response()->json([
            'status' => 'ok',
        ]);

        if ($isGuestSessionAuthorized) {
            $cookie = $this->guestSessionService->refreshCookieFromRequest($request);
            if ($cookie) {
                $response->withCookie($cookie);
            }
        }

        return $response;
    }

    public function resume(Request $request): JsonResponse
    {
        $actor = $this->resolveActor($request);
        if ($actor) {
            return response()->json(['data' => null]);
        }

        $session = $this->guestSessionService->resolveSessionFromRequest($request);
        if (! $session || ! $session->conversation) {
            return response()->json(['data' => null]);
        }

        $conversation = $this->hydrateConversation($session->conversation, false);

        $response = response()->json([
            'data' => new SupportConversationResource($conversation, includePrivateNotes: false, exposeGuestToken: true),
            'meta' => [
                'availability' => $this->supportService->availability(),
                'realtime' => $this->supportRealtimeService->conversationRealtimeMeta(
                    $conversation,
                    null,
                    (string) $conversation->guest_token
                ),
            ],
        ]);

        $cookie = $this->guestSessionService->refreshCookieFromRequest($request);
        if ($cookie) {
            $response->withCookie($cookie);
        }

        return $response;
    }

    public function clearResume(Request $request): JsonResponse
    {
        $this->guestSessionService->revokeSessionFromRequest($request);

        return response()->json([
            'message' => 'Guest support session cleared.',
        ])->withCookie($this->guestSessionService->clearCookie());
    }

    public function claimGuestConversation(Request $request): JsonResponse
    {
        $actor = $this->resolveActor($request);
        if (! $actor) {
            return response()->json([
                'message' => 'Authentication is required.',
            ], 401);
        }

        $session = $this->guestSessionService->resolveSessionFromRequest($request);
        if (! $session || ! $session->conversation) {
            return response()->json([
                'data' => null,
            ]);
        }

        try {
            $conversation = $this->supportService->claimConversationToUser($session->conversation, $actor);
        } catch (AuthorizationException $exception) {
            return response()->json([
                'message' => $exception->getMessage(),
            ], 409);
        }

        $this->guestSessionService->revokeConversationSessions($conversation);

        $includePrivateNotes = $this->supportService->canOperateAsAgent($actor);
        $conversation = $this->hydrateConversation($conversation, $includePrivateNotes);

        return response()->json([
            'message' => 'Guest support conversation claimed successfully.',
            'data' => new SupportConversationResource($conversation, includePrivateNotes: $includePrivateNotes),
            'meta' => [
                'availability' => $this->supportService->availability(),
                'realtime' => $this->supportRealtimeService->conversationRealtimeMeta($conversation, $actor),
            ],
        ])->withCookie($this->guestSessionService->clearCookie());
    }

    protected function resolveActor(Request $request): ?User
    {
        return $request->user() ?: $request->user('sanctum');
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

    protected function enforceRateLimit(
        string $action,
        Request $request,
        SupportConversation $conversation,
        ?User $actor = null,
        ?string $guestToken = null,
        bool $soft = false
    ): ?JsonResponse {
        $config = (array) config("support_chat.rate_limits.{$action}", []);
        $maxAttempts = max(1, (int) ($config['max_attempts'] ?? 6));
        $decaySeconds = max(1, (int) ($config['decay_seconds'] ?? 30));

        $subjectKey = $actor
            ? 'user:'.(string) $actor->public_id
            : ($guestToken !== ''
                ? 'guest:'.substr(hash('sha256', $guestToken), 0, 24)
                : 'ip:'.(string) $request->ip());

        $key = implode(':', [
            'support',
            'chat',
            $action,
            (string) $conversation->public_id,
            $subjectKey,
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
     * @param  array<string, mixed>  $validated
     */
    protected function verifyGuestRecaptcha(array $validated, Request $request, string $action): ?JsonResponse
    {
        if (! (bool) config('recaptcha.enabled', false)) {
            return null;
        }

        if (! empty($validated['recaptcha_v2_token'])) {
            $v2Verification = $this->recaptcha->verifyV2((string) $validated['recaptcha_v2_token'], $request->ip());
            if (! $v2Verification['success']) {
                return response()->json([
                    'message' => $v2Verification['error'] ?? 'Security challenge failed.',
                ], 422);
            }

            return null;
        }

        $verification = $this->recaptcha->verify(
            (string) ($validated['recaptcha_token'] ?? ''),
            $action,
            $request->ip()
        );

        if ($verification['success']) {
            return null;
        }

        if (isset($verification['score']) && $verification['score'] !== null && $verification['score'] < config('recaptcha.score_threshold', 0.5)) {
            return response()->json([
                'message' => 'Security check required.',
                'requires_challenge' => true,
            ], 422);
        }

        return response()->json([
            'message' => $verification['error'] ?? 'Security check failed.',
        ], 422);
    }
}
