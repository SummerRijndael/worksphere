<?php

namespace App\Contracts;

use App\Models\SupportConversation;
use App\Models\SupportSurveyInvite;
use App\Models\SupportSurveyResponse;
use App\Models\User;

interface SupportSurveyServiceContract
{
    /**
     * @param  array<string, mixed>  $options
     */
    public function issueSurveyInvite(
        SupportConversation $conversation,
        string $surveyType,
        ?User $issuedBy = null,
        array $options = []
    ): ?SupportSurveyInvite;

    public function issuePostResolutionCsatInvite(
        SupportConversation $conversation,
        ?User $issuedBy = null
    ): ?SupportSurveyInvite;

    /**
     * @return array{csat: ?SupportSurveyInvite, nps: ?SupportSurveyInvite}
     */
    public function issuePostResolutionSurveyBundle(
        SupportConversation $conversation,
        ?User $issuedBy = null
    ): array;

    public function findActiveInviteByToken(string $token): ?SupportSurveyInvite;

    public function findPendingInviteForConversation(SupportConversation $conversation): ?SupportSurveyInvite;

    public function findLatestInviteForConversation(SupportConversation $conversation): ?SupportSurveyInvite;

    /**
     * @return array<string, SupportSurveyInvite>
     */
    public function findPendingInvitesByTypeForConversation(SupportConversation $conversation): array;

    /**
     * @return array<string, SupportSurveyInvite>
     */
    public function findLatestInvitesByTypeForConversation(SupportConversation $conversation): array;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     */
    public function submitInviteResponseByToken(
        string $token,
        array $payload,
        array $context = []
    ): SupportSurveyResponse;

    /**
     * @param  array<string, mixed>  $payload
     * @param  array<string, mixed>  $context
     */
    public function submitInviteResponse(
        SupportSurveyInvite $invite,
        array $payload,
        array $context = []
    ): SupportSurveyResponse;

    /**
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function metrics(User $actor, array $filters = []): array;
}
