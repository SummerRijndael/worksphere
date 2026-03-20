<?php

namespace App\Services\Support;

use App\Contracts\SupportConversationServiceContract;
use App\Contracts\SupportSurveyServiceContract;
use App\Models\SupportConversation;
use App\Models\SupportSurveyInvite;
use App\Models\SupportSurveyResponse;
use App\Models\User;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Support\Carbon;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;

class SupportSurveyService implements SupportSurveyServiceContract
{
    public function __construct(
        protected SupportConversationServiceContract $supportConversationService
    ) {}

    /**
     * @param  array<string, mixed>  $options
     */
    public function issueSurveyInvite(
        SupportConversation $conversation,
        string $surveyType,
        ?User $issuedBy = null,
        array $options = []
    ): ?SupportSurveyInvite {
        if (! (bool) config('support_chat.surveys.enabled', true)) {
            return null;
        }

        $normalizedType = $this->normalizeSurveyType($surveyType);
        if (! in_array($normalizedType, [SupportSurveyInvite::TYPE_CSAT, SupportSurveyInvite::TYPE_NPS], true)) {
            throw new \InvalidArgumentException('Unsupported survey type.');
        }

        if (! $this->isSurveyTypeEnabled($normalizedType)) {
            return null;
        }

        if (! $this->canIssueInviteForConversation($conversation)) {
            return null;
        }

        $force = (bool) ($options['force'] ?? false);
        if (! $force && $normalizedType === SupportSurveyInvite::TYPE_NPS && ! $this->passesNpsCooldown($conversation)) {
            return null;
        }

        $ttlHours = $this->ttlHours($normalizedType);
        $issuedAt = now();
        $expiresAt = $ttlHours > 0 ? now()->addHours($ttlHours) : null;
        $plainToken = Str::random(64);
        $tokenHash = hash('sha256', $plainToken);
        $metadata = is_array($options['metadata'] ?? null) ? $options['metadata'] : [];

        /** @var SupportSurveyInvite $invite */
        $invite = DB::transaction(function () use ($conversation, $issuedBy, $normalizedType, $issuedAt, $expiresAt, $tokenHash, $metadata) {
            SupportSurveyInvite::query()
                ->where('conversation_id', $conversation->id)
                ->where('survey_type', $normalizedType)
                ->where('status', SupportSurveyInvite::STATUS_PENDING)
                ->update([
                    'status' => SupportSurveyInvite::STATUS_REVOKED,
                    'updated_at' => now(),
                ]);

            return SupportSurveyInvite::create([
                'conversation_id' => $conversation->id,
                'requester_user_id' => $conversation->requester_user_id,
                'issued_by_user_id' => $issuedBy?->id,
                'survey_type' => $normalizedType,
                'status' => SupportSurveyInvite::STATUS_PENDING,
                'token_hash' => $tokenHash,
                'issued_at' => $issuedAt,
                'expires_at' => $expiresAt,
                'metadata' => ! empty($metadata) ? $metadata : null,
            ]);
        });

        $invite->setAttribute('plain_token', $plainToken);
        $invite->loadMissing([
            'conversation:id,public_id,requester_user_id,assigned_to,status,channel',
            'requester:id,public_id,name,email',
            'issuer:id,public_id,name,email',
        ]);

        return $invite;
    }

    public function issuePostResolutionCsatInvite(
        SupportConversation $conversation,
        ?User $issuedBy = null
    ): ?SupportSurveyInvite {
        if (! in_array($conversation->status, [SupportConversation::STATUS_RESOLVED, SupportConversation::STATUS_CLOSED], true)) {
            return null;
        }

        return $this->issueSurveyInvite($conversation, SupportSurveyInvite::TYPE_CSAT, $issuedBy, [
            'metadata' => ['source' => 'resolution'],
        ]);
    }

    public function issuePostResolutionSurveyBundle(
        SupportConversation $conversation,
        ?User $issuedBy = null
    ): array {
        if (! in_array($conversation->status, [SupportConversation::STATUS_RESOLVED, SupportConversation::STATUS_CLOSED], true)) {
            return [
                SupportSurveyInvite::TYPE_CSAT => null,
                SupportSurveyInvite::TYPE_NPS => null,
            ];
        }

        return [
            SupportSurveyInvite::TYPE_CSAT => $this->issueSurveyInvite($conversation, SupportSurveyInvite::TYPE_CSAT, $issuedBy, [
                'metadata' => ['source' => 'resolution_bundle'],
            ]),
            SupportSurveyInvite::TYPE_NPS => $this->issueSurveyInvite($conversation, SupportSurveyInvite::TYPE_NPS, $issuedBy, [
                'metadata' => ['source' => 'resolution_bundle'],
            ]),
        ];
    }

    public function findActiveInviteByToken(string $token): ?SupportSurveyInvite
    {
        $normalized = trim($token);
        if ($normalized === '') {
            return null;
        }

        $invite = SupportSurveyInvite::query()
            ->where('token_hash', hash('sha256', $normalized))
            ->with([
                'conversation:id,public_id,requester_user_id,assigned_to,status,channel',
                'requester:id,public_id,name,email',
                'issuer:id,public_id,name,email',
            ])
            ->first();

        if (! $invite) {
            return null;
        }

        if ($invite->status !== SupportSurveyInvite::STATUS_PENDING) {
            return null;
        }

        if ($invite->expires_at && $invite->expires_at->isPast()) {
            $invite->forceFill([
                'status' => SupportSurveyInvite::STATUS_EXPIRED,
            ])->save();

            return null;
        }

        return $invite;
    }

    public function findPendingInviteForConversation(SupportConversation $conversation): ?SupportSurveyInvite
    {
        $pending = $this->findPendingInvitesByTypeForConversation($conversation);

        return $pending[SupportSurveyInvite::TYPE_CSAT]
            ?? $pending[SupportSurveyInvite::TYPE_NPS]
            ?? null;
    }

    public function findLatestInviteForConversation(SupportConversation $conversation): ?SupportSurveyInvite
    {
        $latest = $this->findLatestInvitesByTypeForConversation($conversation);

        return $latest[SupportSurveyInvite::TYPE_CSAT]
            ?? $latest[SupportSurveyInvite::TYPE_NPS]
            ?? null;
    }

    public function findPendingInvitesByTypeForConversation(SupportConversation $conversation): array
    {
        $invites = SupportSurveyInvite::query()
            ->where('conversation_id', $conversation->id)
            ->where('status', SupportSurveyInvite::STATUS_PENDING)
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->with([
                'conversation:id,public_id,requester_user_id,assigned_to,status,channel,survey_opt_out',
                'requester:id,public_id,name,email',
                'issuer:id,public_id,name,email',
                'response:id,invite_id,public_id,survey_type,score,label,comment,created_at',
            ])
            ->get();

        $pendingByType = [];
        foreach ($invites as $invite) {
            if ($invite->expires_at && $invite->expires_at->isPast()) {
                $invite->forceFill([
                    'status' => SupportSurveyInvite::STATUS_EXPIRED,
                ])->save();

                continue;
            }

            if (! isset($pendingByType[$invite->survey_type])) {
                $pendingByType[$invite->survey_type] = $invite;
            }
        }

        return $pendingByType;
    }

    public function findLatestInvitesByTypeForConversation(SupportConversation $conversation): array
    {
        $invites = SupportSurveyInvite::query()
            ->where('conversation_id', $conversation->id)
            ->orderByDesc('issued_at')
            ->orderByDesc('id')
            ->with([
                'conversation:id,public_id,requester_user_id,assigned_to,status,channel,survey_opt_out',
                'requester:id,public_id,name,email',
                'issuer:id,public_id,name,email',
                'response:id,invite_id,public_id,survey_type,score,label,comment,created_at',
            ])
            ->get();

        $latestByType = [];
        foreach ($invites as $invite) {
            if (! isset($latestByType[$invite->survey_type])) {
                $latestByType[$invite->survey_type] = $invite;
            }
        }

        return $latestByType;
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     */
    public function submitInviteResponseByToken(
        string $token,
        array $payload,
        array $context = []
    ): SupportSurveyResponse {
        $normalizedToken = trim($token);
        if ($normalizedToken === '') {
            throw new \InvalidArgumentException('Survey token is required.');
        }

        /** @var SupportSurveyInvite|null $invite */
        $invite = SupportSurveyInvite::query()
            ->where('token_hash', hash('sha256', $normalizedToken))
            ->first();

        if (! $invite) {
            throw new \InvalidArgumentException('Survey invite is invalid.');
        }

        return $this->submitInviteResponse($invite, $payload, $context);
    }

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     */
    public function submitInviteResponse(
        SupportSurveyInvite $invite,
        array $payload,
        array $context = []
    ): SupportSurveyResponse {
        $commentMaxLength = max(100, (int) config('support_chat.surveys.comment_max_length', 1000));

        /** @var SupportSurveyResponse $response */
        $response = DB::transaction(function () use ($invite, $payload, $context, $commentMaxLength) {
            /** @var SupportSurveyInvite|null $lockedInvite */
            $lockedInvite = SupportSurveyInvite::query()
                ->whereKey($invite->id)
                ->lockForUpdate()
                ->with('conversation:id,public_id,requester_user_id,assigned_to,channel')
                ->first();

            if (! $lockedInvite) {
                throw new \InvalidArgumentException('Survey invite is invalid.');
            }

            if ($lockedInvite->status !== SupportSurveyInvite::STATUS_PENDING) {
                throw new \DomainException('This survey invite is no longer active.');
            }

            if ($lockedInvite->expires_at && $lockedInvite->expires_at->isPast()) {
                $lockedInvite->forceFill([
                    'status' => SupportSurveyInvite::STATUS_EXPIRED,
                ])->save();

                throw new \DomainException('This survey invite has expired.');
            }

            $score = (int) ($payload['score'] ?? -1);
            [$minScore, $maxScore] = $this->scoreBounds($lockedInvite->survey_type);
            if ($score < $minScore || $score > $maxScore) {
                throw new \InvalidArgumentException("Score must be between {$minScore} and {$maxScore}.");
            }

            $comment = trim(strip_tags((string) ($payload['comment'] ?? '')));
            if ($comment === '') {
                $comment = null;
            } elseif (Str::length($comment) > $commentMaxLength) {
                $comment = Str::limit($comment, $commentMaxLength, '');
            }

            $metadata = is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [];
            $conversation = $lockedInvite->conversation;

            $response = SupportSurveyResponse::create([
                'invite_id' => $lockedInvite->id,
                'conversation_id' => $lockedInvite->conversation_id,
                'requester_user_id' => $lockedInvite->requester_user_id ?: ($conversation?->requester_user_id ?: null),
                'rated_agent_user_id' => $conversation?->assigned_to,
                'survey_type' => $lockedInvite->survey_type,
                'score' => $score,
                'label' => $this->labelForScore($lockedInvite->survey_type, $score),
                'comment' => $comment,
                'channel' => $conversation?->channel,
                'submitted_from_ip' => Str::limit((string) ($context['ip'] ?? ''), 64, ''),
                'submitted_user_agent' => Str::limit((string) ($context['user_agent'] ?? ''), 512, ''),
                'metadata' => ! empty($metadata) ? $metadata : null,
            ]);

            $lockedInvite->forceFill([
                'status' => SupportSurveyInvite::STATUS_RESPONDED,
                'responded_at' => now(),
            ])->save();

            return $response;
        });

        return $response->fresh([
            'invite:id,public_id,survey_type,status,conversation_id',
            'requester:id,public_id,name,email',
            'ratedAgent:id,public_id,name,email',
        ]);
    }

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function metrics(User $actor, array $filters = []): array
    {
        if (! $this->supportConversationService->canOperateAsAgent($actor)) {
            throw new AuthorizationException('Only support agents can view survey metrics.');
        }

        $surveyType = $this->normalizeSurveyType((string) ($filters['survey_type'] ?? ''));
        $agentId = isset($filters['agent_id']) ? (int) $filters['agent_id'] : null;
        $from = $this->parseDateBoundary($filters['from'] ?? null, true);
        $to = $this->parseDateBoundary($filters['to'] ?? null, false);

        $baseQuery = SupportSurveyResponse::query();

        if (in_array($surveyType, [SupportSurveyInvite::TYPE_CSAT, SupportSurveyInvite::TYPE_NPS], true)) {
            $baseQuery->where('survey_type', $surveyType);
        }

        if ($agentId) {
            $baseQuery->where('rated_agent_user_id', $agentId);
        }

        if ($from) {
            $baseQuery->where('created_at', '>=', $from);
        }

        if ($to) {
            $baseQuery->where('created_at', '<=', $to);
        }

        $totalResponses = (clone $baseQuery)->count();

        $csatQuery = (clone $baseQuery)->where('survey_type', SupportSurveyInvite::TYPE_CSAT);
        $csatTotal = (clone $csatQuery)->count();
        $csatAverage = $csatTotal > 0 ? round((float) ((clone $csatQuery)->avg('score') ?? 0), 2) : null;
        $csatPositive = (clone $csatQuery)->where('score', '>=', 4)->count();
        $csatPositiveRate = $csatTotal > 0
            ? round(($csatPositive / $csatTotal) * 100, 2)
            : null;

        $npsQuery = (clone $baseQuery)->where('survey_type', SupportSurveyInvite::TYPE_NPS);
        $npsTotal = (clone $npsQuery)->count();
        $npsPromoters = (clone $npsQuery)->where('score', '>=', 9)->count();
        $npsPassives = (clone $npsQuery)->whereBetween('score', [7, 8])->count();
        $npsDetractors = (clone $npsQuery)->where('score', '<=', 6)->count();
        $npsScore = $npsTotal > 0
            ? round((($npsPromoters / $npsTotal) * 100) - (($npsDetractors / $npsTotal) * 100), 2)
            : null;

        $agentMetrics = $this->buildAgentMetrics((clone $baseQuery)->whereNotNull('rated_agent_user_id')->get([
            'rated_agent_user_id',
            'survey_type',
            'score',
        ]));

        return [
            'totals' => [
                'responses' => $totalResponses,
                'csat' => [
                    'responses' => $csatTotal,
                    'average_score' => $csatAverage,
                    'positive_count' => $csatPositive,
                    'positive_rate' => $csatPositiveRate,
                ],
                'nps' => [
                    'responses' => $npsTotal,
                    'promoters' => $npsPromoters,
                    'passives' => $npsPassives,
                    'detractors' => $npsDetractors,
                    'score' => $npsScore,
                ],
            ],
            'by_agent' => $agentMetrics,
            'filters' => [
                'survey_type' => $surveyType !== '' ? $surveyType : null,
                'agent_id' => $agentId,
                'from' => $from?->toISOString(),
                'to' => $to?->toISOString(),
            ],
        ];
    }

    protected function normalizeSurveyType(string $surveyType): string
    {
        return strtolower(trim($surveyType));
    }

    protected function isSurveyTypeEnabled(string $surveyType): bool
    {
        return (bool) config("support_chat.surveys.{$surveyType}.enabled", true);
    }

    protected function canIssueInviteForConversation(SupportConversation $conversation): bool
    {
        if ((bool) $conversation->survey_opt_out) {
            return false;
        }

        if ($conversation->requester_user_id) {
            return true;
        }

        return filled($conversation->guest_email)
            || filled($conversation->guest_name)
            || filled($conversation->guest_token);
    }

    protected function passesNpsCooldown(SupportConversation $conversation): bool
    {
        if (! $conversation->requester_user_id) {
            return true;
        }

        $cooldownDays = max(1, (int) config('support_chat.surveys.nps.cooldown_days', 90));
        $cutoff = now()->subDays($cooldownDays);

        return ! SupportSurveyResponse::query()
            ->where('requester_user_id', $conversation->requester_user_id)
            ->where('survey_type', SupportSurveyInvite::TYPE_NPS)
            ->where('created_at', '>=', $cutoff)
            ->exists();
    }

    protected function ttlHours(string $surveyType): int
    {
        return max(1, (int) config("support_chat.surveys.{$surveyType}.ttl_hours", 168));
    }

    /**
     * @return array{0:int,1:int}
     */
    protected function scoreBounds(string $surveyType): array
    {
        if ($surveyType === SupportSurveyInvite::TYPE_NPS) {
            return [0, 10];
        }

        return [1, 5];
    }

    protected function labelForScore(string $surveyType, int $score): string
    {
        if ($surveyType === SupportSurveyInvite::TYPE_NPS) {
            if ($score >= 9) {
                return 'promoter';
            }
            if ($score >= 7) {
                return 'passive';
            }

            return 'detractor';
        }

        if ($score >= 4) {
            return 'satisfied';
        }
        if ($score === 3) {
            return 'neutral';
        }

        return 'dissatisfied';
    }

    protected function parseDateBoundary(mixed $value, bool $startOfDay): ?Carbon
    {
        if (! is_string($value) || trim($value) === '') {
            return null;
        }

        try {
            $parsed = Carbon::parse($value);
        } catch (\Throwable) {
            return null;
        }

        return $startOfDay ? $parsed->startOfDay() : $parsed->endOfDay();
    }

    /**
     * @param  Collection<int, SupportSurveyResponse>  $rows
     * @return array<int, array<string, mixed>>
     */
    protected function buildAgentMetrics(Collection $rows): array
    {
        if ($rows->isEmpty()) {
            return [];
        }

        $byAgentId = $rows->groupBy('rated_agent_user_id');
        $agentIds = $byAgentId->keys()->map(fn ($id) => (int) $id)->values();

        $agents = User::query()
            ->whereIn('id', $agentIds)
            ->get(['id', 'public_id', 'name', 'email'])
            ->keyBy('id');

        return $byAgentId->map(function (Collection $agentRows, string|int $agentId) use ($agents): array {
            $resolvedAgentId = (int) $agentId;
            $agent = $agents->get($resolvedAgentId);

            $csatRows = $agentRows->where('survey_type', SupportSurveyInvite::TYPE_CSAT)->values();
            $csatCount = $csatRows->count();
            $csatPositive = $csatRows->where('score', '>=', 4)->count();
            $csatAverage = $csatCount > 0 ? round((float) $csatRows->avg('score'), 2) : null;

            $npsRows = $agentRows->where('survey_type', SupportSurveyInvite::TYPE_NPS)->values();
            $npsCount = $npsRows->count();
            $npsPromoters = $npsRows->where('score', '>=', 9)->count();
            $npsDetractors = $npsRows->where('score', '<=', 6)->count();
            $npsPassives = $npsRows->whereBetween('score', [7, 8])->count();
            $npsScore = $npsCount > 0
                ? round((($npsPromoters / $npsCount) * 100) - (($npsDetractors / $npsCount) * 100), 2)
                : null;

            return [
                'agent' => [
                    'id' => $agent?->public_id,
                    'name' => $agent?->name ?? 'Unassigned',
                    'email' => $agent?->email,
                ],
                'responses' => $agentRows->count(),
                'csat' => [
                    'responses' => $csatCount,
                    'average_score' => $csatAverage,
                    'positive_count' => $csatPositive,
                    'positive_rate' => $csatCount > 0 ? round(($csatPositive / $csatCount) * 100, 2) : null,
                ],
                'nps' => [
                    'responses' => $npsCount,
                    'promoters' => $npsPromoters,
                    'passives' => $npsPassives,
                    'detractors' => $npsDetractors,
                    'score' => $npsScore,
                ],
            ];
        })->values()->all();
    }
}
