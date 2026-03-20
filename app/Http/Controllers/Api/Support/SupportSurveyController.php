<?php

namespace App\Http\Controllers\Api\Support;

use App\Contracts\SupportConversationServiceContract;
use App\Contracts\SupportSurveyServiceContract;
use App\Http\Controllers\Controller;
use App\Http\Resources\Support\SupportConversationResource;
use App\Http\Resources\Support\SupportSurveyInviteResource;
use App\Http\Resources\Support\SupportSurveyResponseResource;
use App\Models\SupportConversation;
use App\Models\SupportSurveyInvite;
use App\Models\User;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;

class SupportSurveyController extends Controller
{
    public function __construct(
        protected SupportSurveyServiceContract $supportSurveyService,
        protected SupportConversationServiceContract $supportConversationService
    ) {}

    public function showByToken(Request $request, string $token): JsonResponse
    {
        $invite = $this->supportSurveyService->findActiveInviteByToken($token);
        if (! $invite) {
            return response()->json([
                'message' => 'Survey invite is invalid or expired.',
            ], 404);
        }

        return response()->json([
            'data' => new SupportSurveyInviteResource($invite, includeDefinition: true),
        ]);
    }

    public function showForConversation(Request $request, SupportConversation $conversation): JsonResponse
    {
        $actor = $this->resolveActor($request);
        $guestToken = (string) ($request->input('guest_token') ?? $request->header('X-Support-Guest-Token', ''));

        try {
            $conversation = $this->supportConversationService->getConversationForActor($conversation, $actor, $guestToken);
        } catch (\Illuminate\Auth\Access\AuthorizationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        }

        return response()->json([
            'data' => $this->buildSurveyStatePayload($conversation, $request),
        ]);
    }

    public function submitByToken(Request $request, string $token): JsonResponse
    {
        $validated = $request->validate([
            'score' => ['required', 'integer', 'min:0', 'max:10'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'metadata' => ['nullable', 'array'],
        ]);

        try {
            $response = $this->supportSurveyService->submitInviteResponseByToken($token, $validated, [
                'ip' => $request->ip(),
                'user_agent' => (string) $request->userAgent(),
            ]);
        } catch (\DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Survey submitted successfully.',
            'data' => new SupportSurveyResponseResource($response->loadMissing([
                'invite:id,public_id,survey_type,conversation_id',
                'requester:id,public_id,name,email',
                'ratedAgent:id,public_id,name,email',
            ])),
        ], 201);
    }

    public function submitForConversation(Request $request, SupportConversation $conversation): JsonResponse
    {
        $actor = $this->resolveActor($request);
        $guestToken = (string) ($request->input('guest_token') ?? $request->header('X-Support-Guest-Token', ''));

        try {
            $conversation = $this->supportConversationService->getConversationForActor($conversation, $actor, $guestToken);
        } catch (\Illuminate\Auth\Access\AuthorizationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        }

        if ((bool) $conversation->survey_opt_out) {
            return response()->json([
                'message' => 'Survey collection is disabled for this conversation.',
            ], 409);
        }

        $hasLegacyScore = $request->has('score');
        if ($hasLegacyScore) {
            $validated = $request->validate([
                'score' => ['required', 'integer', 'min:0', 'max:10'],
                'comment' => ['nullable', 'string', 'max:1000'],
                'metadata' => ['nullable', 'array'],
            ]);

            $invite = $this->supportSurveyService->findPendingInviteForConversation($conversation);
            if (! $invite) {
                return response()->json([
                    'message' => 'No active survey is available for this conversation.',
                ], 409);
            }

            try {
                $response = $this->supportSurveyService->submitInviteResponse($invite, $validated, [
                    'ip' => $request->ip(),
                    'user_agent' => (string) $request->userAgent(),
                ]);
            } catch (\DomainException $exception) {
                return response()->json(['message' => $exception->getMessage()], 409);
            } catch (\InvalidArgumentException $exception) {
                return response()->json(['message' => $exception->getMessage()], 422);
            }

            return response()->json([
                'message' => 'Survey submitted successfully.',
                'data' => new SupportSurveyResponseResource($response->loadMissing([
                    'invite:id,public_id,survey_type,conversation_id',
                    'requester:id,public_id,name,email',
                    'ratedAgent:id,public_id,name,email',
                ])),
                'meta' => [
                    'survey' => $this->buildSurveyStatePayload($conversation->fresh(), $request),
                ],
            ], 201);
        }

        $validated = $request->validate([
            'csat_score' => ['nullable', 'integer', 'min:1', 'max:5'],
            'nps_score' => ['nullable', 'integer', 'min:0', 'max:10'],
            'comment' => ['nullable', 'string', 'max:1000'],
            'csat_comment' => ['nullable', 'string', 'max:1000'],
            'nps_comment' => ['nullable', 'string', 'max:1000'],
            'metadata' => ['nullable', 'array'],
        ]);

        $hasCsat = array_key_exists('csat_score', $validated) && $validated['csat_score'] !== null;
        $hasNps = array_key_exists('nps_score', $validated) && $validated['nps_score'] !== null;
        if (! $hasCsat && ! $hasNps) {
            return response()->json([
                'message' => 'At least one bundled survey score is required.',
            ], 422);
        }

        $pendingByType = $this->supportSurveyService->findPendingInvitesByTypeForConversation($conversation);
        if (empty($pendingByType)) {
            return response()->json([
                'message' => 'No active survey is available for this conversation.',
            ], 409);
        }

        $responses = [];
        try {
            if ($hasCsat) {
                $invite = $pendingByType[SupportSurveyInvite::TYPE_CSAT] ?? null;
                if (! $invite) {
                    return response()->json([
                        'message' => 'CSAT survey is no longer available.',
                    ], 409);
                }

                $responses[] = $this->supportSurveyService->submitInviteResponse($invite, [
                    'score' => (int) $validated['csat_score'],
                    'comment' => $validated['csat_comment'] ?? $validated['comment'] ?? null,
                    'metadata' => is_array($validated['metadata'] ?? null) ? $validated['metadata'] : [],
                ], [
                    'ip' => $request->ip(),
                    'user_agent' => (string) $request->userAgent(),
                ]);
            }

            if ($hasNps) {
                $invite = $pendingByType[SupportSurveyInvite::TYPE_NPS] ?? null;
                if (! $invite) {
                    return response()->json([
                        'message' => 'NPS survey is no longer available.',
                    ], 409);
                }

                $responses[] = $this->supportSurveyService->submitInviteResponse($invite, [
                    'score' => (int) $validated['nps_score'],
                    'comment' => $validated['nps_comment'] ?? $validated['comment'] ?? null,
                    'metadata' => is_array($validated['metadata'] ?? null) ? $validated['metadata'] : [],
                ], [
                    'ip' => $request->ip(),
                    'user_agent' => (string) $request->userAgent(),
                ]);
            }
        } catch (\DomainException $exception) {
            return response()->json(['message' => $exception->getMessage()], 409);
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        return response()->json([
            'message' => 'Survey submitted successfully.',
            'data' => [
                'responses' => array_values(array_map(
                    fn ($response) => (new SupportSurveyResponseResource($response->loadMissing([
                        'invite:id,public_id,survey_type,conversation_id',
                        'requester:id,public_id,name,email',
                        'ratedAgent:id,public_id,name,email',
                    ])))->toArray($request),
                    $responses
                )),
                'survey' => $this->buildSurveyStatePayload($conversation->fresh(), $request),
            ],
        ], 201);
    }

    public function createInvite(Request $request, SupportConversation $conversation): JsonResponse
    {
        $this->authorize('resolve', $conversation);

        $validated = $request->validate([
            'survey_type' => ['required', 'string', 'in:csat,nps'],
            'force' => ['nullable', 'boolean'],
        ]);

        try {
            $conversation = $this->supportConversationService->getConversationForActor($conversation, $request->user());
            $invite = $this->supportSurveyService->issueSurveyInvite(
                $conversation,
                (string) $validated['survey_type'],
                $request->user(),
                ['force' => (bool) ($validated['force'] ?? false), 'metadata' => ['source' => 'manual']]
            );
        } catch (\InvalidArgumentException $exception) {
            return response()->json(['message' => $exception->getMessage()], 422);
        }

        if (! $invite) {
            return response()->json([
                'message' => 'Survey invite was not issued for this conversation.',
                'data' => null,
            ]);
        }

        $response = response()->json([
            'message' => 'Survey invite created successfully.',
            'data' => new SupportSurveyInviteResource($invite),
        ], 201);

        if (app()->environment('local', 'testing') || config('app.debug')) {
            $response->setData(array_merge($response->getData(true), [
                'meta' => [
                    'test_token' => $invite->getAttribute('plain_token'),
                ],
            ]));
        }

        return $response;
    }

    public function metrics(Request $request): JsonResponse
    {
        $this->authorize('viewAny', SupportConversation::class);

        $validated = $request->validate([
            'survey_type' => ['nullable', 'string', 'in:csat,nps'],
            'agent_public_id' => ['nullable', 'string', 'exists:users,public_id'],
            'from' => ['nullable', 'date'],
            'to' => ['nullable', 'date'],
        ]);

        $agentId = null;
        if (! empty($validated['agent_public_id'])) {
            $agentId = User::query()
                ->where('public_id', (string) $validated['agent_public_id'])
                ->value('id');
        }

        try {
            $data = $this->supportSurveyService->metrics($request->user(), [
                'survey_type' => $validated['survey_type'] ?? null,
                'agent_id' => $agentId,
                'from' => $validated['from'] ?? null,
                'to' => $validated['to'] ?? null,
            ]);
        } catch (\Illuminate\Auth\Access\AuthorizationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        }

        return response()->json([
            'data' => $data,
        ]);
    }

    public function updatePreference(Request $request, SupportConversation $conversation): JsonResponse
    {
        $actor = $this->resolveActor($request);
        $guestToken = (string) ($request->input('guest_token') ?? $request->header('X-Support-Guest-Token', ''));

        $validated = $request->validate([
            'opt_out' => ['required', 'boolean'],
        ]);

        try {
            $conversation = $this->supportConversationService->updateSurveyPreference(
                $conversation,
                (bool) $validated['opt_out'],
                $actor,
                $guestToken
            );
        } catch (\Illuminate\Auth\Access\AuthorizationException $exception) {
            return response()->json(['message' => $exception->getMessage()], 403);
        }

        $includePrivateNotes = $actor ? $this->supportConversationService->canOperateAsAgent($actor) : false;
        $conversation->loadMissing([
            'requester:id,public_id,name,email',
            'assignee:id,public_id,name,email',
            'endedBy:id,public_id,name,email',
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

        return response()->json([
            'message' => (bool) $validated['opt_out']
                ? 'Survey requests disabled for this conversation.'
                : 'Survey requests enabled for this conversation.',
            'data' => [
                'conversation' => new SupportConversationResource($conversation, includePrivateNotes: $includePrivateNotes),
                'survey' => $this->buildSurveyStatePayload($conversation, $request),
            ],
        ]);
    }

    protected function resolveActor(Request $request): ?User
    {
        return $request->user() ?: $request->user('sanctum');
    }

    /**
     * @return array<string, mixed>
     */
    protected function buildSurveyStatePayload(SupportConversation $conversation, Request $request): array
    {
        $pendingByType = $this->supportSurveyService->findPendingInvitesByTypeForConversation($conversation);
        $latestByType = $this->supportSurveyService->findLatestInvitesByTypeForConversation($conversation);
        $types = [SupportSurveyInvite::TYPE_CSAT, SupportSurveyInvite::TYPE_NPS];

        $bundle = [];
        foreach ($types as $type) {
            $pending = $pendingByType[$type] ?? null;
            if ($pending) {
                $bundle[$type] = [
                    'state' => SupportSurveyInvite::STATUS_PENDING,
                    'invite' => (new SupportSurveyInviteResource($pending, includeDefinition: true))->toArray($request),
                    'response' => null,
                ];

                continue;
            }

            $latest = $latestByType[$type] ?? null;
            if ($latest?->status === SupportSurveyInvite::STATUS_RESPONDED && $latest->response) {
                $bundle[$type] = [
                    'state' => SupportSurveyInvite::STATUS_RESPONDED,
                    'invite' => (new SupportSurveyInviteResource($latest, includeDefinition: true))->toArray($request),
                    'response' => (new SupportSurveyResponseResource(
                        $latest->response->loadMissing([
                            'invite:id,public_id,survey_type,conversation_id',
                            'requester:id,public_id,name,email',
                            'ratedAgent:id,public_id,name,email',
                        ])
                    ))->toArray($request),
                ];

                continue;
            }

            $bundle[$type] = [
                'state' => $latest?->status ?? 'none',
                'invite' => $latest ? (new SupportSurveyInviteResource($latest, includeDefinition: true))->toArray($request) : null,
                'response' => null,
            ];
        }

        $bundleStates = array_values(array_map(
            fn (array $entry): string => (string) ($entry['state'] ?? 'none'),
            $bundle
        ));

        $state = 'none';
        if (in_array(SupportSurveyInvite::STATUS_PENDING, $bundleStates, true)) {
            $state = SupportSurveyInvite::STATUS_PENDING;
        } elseif (in_array(SupportSurveyInvite::STATUS_RESPONDED, $bundleStates, true)) {
            $state = SupportSurveyInvite::STATUS_RESPONDED;
        }

        $legacySelected = null;
        if (($bundle[SupportSurveyInvite::TYPE_CSAT]['state'] ?? null) === SupportSurveyInvite::STATUS_PENDING) {
            $legacySelected = $bundle[SupportSurveyInvite::TYPE_CSAT];
        } elseif (($bundle[SupportSurveyInvite::TYPE_NPS]['state'] ?? null) === SupportSurveyInvite::STATUS_PENDING) {
            $legacySelected = $bundle[SupportSurveyInvite::TYPE_NPS];
        } elseif (($bundle[SupportSurveyInvite::TYPE_CSAT]['state'] ?? null) === SupportSurveyInvite::STATUS_RESPONDED) {
            $legacySelected = $bundle[SupportSurveyInvite::TYPE_CSAT];
        } elseif (($bundle[SupportSurveyInvite::TYPE_NPS]['state'] ?? null) === SupportSurveyInvite::STATUS_RESPONDED) {
            $legacySelected = $bundle[SupportSurveyInvite::TYPE_NPS];
        }

        return [
            'state' => $state,
            'invite' => $legacySelected['invite'] ?? null,
            'response' => $legacySelected['response'] ?? null,
            'bundle' => $bundle,
            'survey_opt_out' => (bool) $conversation->survey_opt_out,
        ];
    }
}
