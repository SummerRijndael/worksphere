<?php

namespace App\Services\Support;

use App\Contracts\SupportAccessAdapterContract;
use App\Contracts\SupportConversationServiceContract;
use App\Enums\AuditAction;
use App\Enums\AuditCategory;
use App\Events\Support\SupportConversationChanged;
use App\Events\Support\SupportMessageCreated;
use App\Jobs\BroadcastSupportConversationChanged;
use App\Jobs\BroadcastSupportMessageCreated;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\SupportRoutingQueueEntry;
use App\Models\SupportSkillMembership;
use App\Models\SupportSurveyInvite;
use App\Models\SupportSurveyResponse;
use App\Jobs\Support\SupportAssignmentTimeoutJob;
use App\Models\User;
use App\Services\Support\Access\SupportAccessAdapterResolver;
use App\Services\AuditService;
use App\Services\Support\Pipelines\SupportHandoffPipeline;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use App\Services\Chat\PresenceService;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Schema;
use Illuminate\Support\Str;

class SupportConversationService implements SupportConversationServiceContract
{
    /**
     * @var array<string, true>|null
     */
    protected ?array $supportConversationColumnMap = null;

    // TODO(PARKED:support-chat-hardening): Move support message rendering to backend.
    // Keep raw `body` for edit/audit, and add canonical safe `body_rendered` in API/resource
    // payloads so clients don't rely only on frontend sanitization during the migration.

    public function __construct(
        protected SupportHandoffPipeline $handoffPipeline,
        protected SupportChatMediaService $supportChatMediaService,
        protected AuditService $auditService,
        protected SupportAccessAdapterResolver $supportAccessAdapterResolver,
        protected \App\Contracts\SupportRoutingServiceContract $supportRoutingService,
        protected PresenceService $presenceService
    ) {}

    /**
     * @param  array<string, mixed>  $payload
     */
    public function openConversation(array $payload, ?User $actor = null): SupportConversation
    {
        $initialMessage = trim((string) ($payload['initial_message'] ?? $payload['message'] ?? ''));
        if ($initialMessage === '') {
            throw new \InvalidArgumentException('Initial message is required.');
        }
        $conversationType = $this->normalizeConversationType($payload['conversation_type'] ?? null);

        /** @var SupportConversation $conversation */
        $conversation = DB::transaction(function () use ($payload, $actor, $initialMessage, $conversationType) {
            $baseAttributes = [
                'requester_user_id' => $actor?->id,
                'guest_name' => $actor ? null : ($payload['guest_name'] ?? null),
                'guest_email' => $actor ? null : ($payload['guest_email'] ?? null),
                'guest_token' => $actor ? null : Str::random(64),
                'status' => SupportConversation::STATUS_OPEN,
                'priority' => (string) ($payload['priority'] ?? 'normal'),
                'channel' => (string) ($payload['channel'] ?? 'widget'),
                'subject' => $payload['subject'] ?? null,
                'source_url' => $payload['source_url'] ?? null,
                'ai_enabled' => (bool) ($payload['ai_enabled'] ?? config('support_chat.ai_enabled', true)),
                'metadata' => is_array($payload['metadata'] ?? null) ? $payload['metadata'] : null,
            ];

            $stateAttributes = $this->filterExistingSupportConversationColumns([
                'chat_state' => SupportConversation::CHAT_STATE_NEW,
                'assignment_state' => SupportConversation::ASSIGNMENT_STATE_UNASSIGNED,
                'resolution_marker' => SupportConversation::RESOLUTION_MARKER_UNRESOLVED,
                'conversation_type' => $conversationType,
            ]);

            $conversation = SupportConversation::create(array_merge($baseAttributes, $stateAttributes));

            $this->createMessage(
                conversation: $conversation,
                senderType: SupportMessage::SENDER_CUSTOMER,
                body: $initialMessage,
                senderUserId: $actor?->id,
            );

            $displayName = trim((string) ($actor?->name ?? $payload['guest_name'] ?? ''));
            $greetingTarget = $displayName !== '' ? $displayName : 'there';
            $chatReference = $conversation->chat_reference;

            $this->createMessage(
                conversation: $conversation,
                senderType: SupportMessage::SENDER_SYSTEM,
                body: sprintf(
                    'Welcome, %s. Your chat ID is %s. Keep it handy if you need to return to this conversation while it is still active.',
                    $greetingTarget,
                    $chatReference
                ),
                metadata: [
                    'type' => 'chat_reference',
                    'chat_reference' => $chatReference,
                ],
            );

            return $conversation;
        });

        $this->processAiForCustomerMessage($conversation->fresh(), $initialMessage);
        $this->supportRoutingService->enqueueConversation(
            $conversation->fresh(),
            reason: 'conversation_opened'
        );

        $this->broadcastConversationChanged($conversation->fresh());

        return $conversation->fresh(['requester', 'assignee', 'endedBy', 'skill', 'latestMessage.sender', 'latestMessage.media', 'messages.sender', 'messages.media']);
    }

    public function getConversationForActor(SupportConversation $conversation, ?User $actor = null, ?string $guestToken = null): SupportConversation
    {
        if ($actor) {
            if ($this->supportAccessAdapter()->canAccessConversation($actor, $conversation)) {
                return $conversation;
            }

            if ((int) $conversation->requester_user_id === (int) $actor->id) {
                return $conversation;
            }

            throw new AuthorizationException('You are not allowed to access this support conversation.');
        }

        if (! $guestToken || ! hash_equals((string) $conversation->guest_token, (string) $guestToken)) {
            throw new AuthorizationException('Guest token is invalid for this support conversation.');
        }

        return $conversation;
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function addCustomerMessage(SupportConversation $conversation, array $payload, ?User $actor = null, ?string $guestToken = null): SupportMessage
    {
        $conversation = $this->getConversationForActor($conversation, $actor, $guestToken);

        if ($conversation->isClosedLike()) {
            throw new \InvalidArgumentException('Cannot send messages to a resolved/closed conversation.');
        }

        $body = trim((string) ($payload['body'] ?? $payload['message'] ?? ''));
        $files = $this->normalizeUploadedFiles($payload['files'] ?? []);
        if ($body === '' && empty($files)) {
            throw new \InvalidArgumentException('Message body or attachment is required.');
        }

        if (! empty($files)) {
            $this->supportChatMediaService->validateFiles($files);
        }

        if ($actor && ! $conversation->requester_user_id) {
            $conversation->requester_user_id = $actor->id;
            $conversation->save();
        }

        $message = DB::transaction(function () use ($conversation, $actor, $payload, $body, $files): SupportMessage {
            $message = $this->createMessage(
                conversation: $conversation,
                senderType: SupportMessage::SENDER_CUSTOMER,
                body: $body !== '' ? $body : null,
                senderUserId: $actor?->id,
                metadata: is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
            );

            if (! empty($files)) {
                $attachments = $this->supportChatMediaService->attachFilesToMessage($message, $files);
                $message->forceFill([
                    'attachments' => $attachments,
                ])->save();
            }

            return $message;
        });
        $this->broadcastMessageCreated($conversation->fresh(), $message->fresh(['sender', 'media']));

        if ($body !== '') {
            $this->processAiForCustomerMessage($conversation->fresh(), $body);
        }
        $this->supportRoutingService->enqueueConversation(
            $conversation->fresh(),
            reason: 'customer_message'
        );

        $this->broadcastConversationChanged($conversation->fresh());

        return $message->fresh(['sender', 'media']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function addAgentMessage(SupportConversation $conversation, User $agent, array $payload): SupportMessage
    {
        if (! $this->supportAccessAdapter()->canReply($agent, $conversation)) {
            throw new AuthorizationException('Only support agents can send agent messages.');
        }

        $isPrivateNote = (bool) ($payload['is_private_note'] ?? false);

        if (! $isPrivateNote && $conversation->isClosedLike()) {
            throw new \InvalidArgumentException('Cannot send agent messages to a resolved/closed conversation.');
        }

        if (! $isPrivateNote && ! $conversation->assigned_to) {
            $this->assertAgentHasCapacity($agent, $conversation);
        }

        $body = trim((string) ($payload['body'] ?? $payload['message'] ?? ''));
        $files = $this->normalizeUploadedFiles($payload['files'] ?? []);
        if ($body === '' && empty($files)) {
            throw new \InvalidArgumentException('Message body or attachment is required.');
        }

        if (! empty($files)) {
            $this->supportChatMediaService->validateFiles($files);
        }

        $message = DB::transaction(function () use ($conversation, $agent, $payload, $body, $isPrivateNote, $files): SupportMessage {
            $message = $this->createMessage(
                conversation: $conversation,
                senderType: SupportMessage::SENDER_AGENT,
                body: $body !== '' ? $body : null,
                senderUserId: $agent->id,
                isPrivateNote: $isPrivateNote,
                metadata: is_array($payload['metadata'] ?? null) ? $payload['metadata'] : [],
            );

            if (! empty($files)) {
                $attachments = $this->supportChatMediaService->attachFilesToMessage($message, $files);
                $message->forceFill([
                    'attachments' => $attachments,
                ])->save();
            }

            return $message;
        });
        $this->broadcastMessageCreated($conversation->fresh(), $message->fresh(['sender', 'media']), ! $isPrivateNote);

        if ($isPrivateNote) {
            return $message->fresh(['sender', 'media']);
        }

        $conversation->forceFill([
            'first_response_at' => $conversation->first_response_at ?? now(),
            'assigned_to' => $conversation->assigned_to ?: $agent->id,
            'status' => SupportConversation::STATUS_ASSIGNED,
            'ai_handoff_required' => false,
            'metadata' => $this->withoutWaitingForAgentMetadata($conversation),
        ])->forceFill(
            $this->filterExistingSupportConversationColumns([
                'assigned_at' => $conversation->assigned_at ?? now(),
            ])
        )->forceFill(
            $this->filterExistingSupportConversationColumns([
                'chat_state' => SupportConversation::CHAT_STATE_NEW,
                'assignment_state' => SupportConversation::ASSIGNMENT_STATE_ASSIGNED,
            ])
        )->save();

        $this->supportRoutingService->markConversationAssigned(
            $conversation->fresh(),
            $agent->id,
            reason: 'agent_message'
        );
        $this->broadcastConversationChanged($conversation->fresh(), true);

        return $message->fresh(['sender', 'media']);
    }

    public function acceptAssignment(SupportConversation $conversation, User $agent): SupportConversation
    {
        if ((int) $conversation->assigned_to !== (int) $agent->id) {
            throw new \InvalidArgumentException('You are not assigned to this conversation.');
        }

        if ($conversation->status !== SupportConversation::STATUS_PENDING_ACCEPTANCE) {
            // Already accepted or in another state
            return $conversation->fresh(['requester', 'assignee', 'skill']);
        }

        $conversation->forceFill([
            'status' => SupportConversation::STATUS_ASSIGNED,
            'metadata' => $this->withoutWaitingForAgentMetadata($conversation),
        ])->forceFill(
            $this->filterExistingSupportConversationColumns([
                'assignment_state' => SupportConversation::ASSIGNMENT_STATE_ASSIGNED,
                'assigned_at' => $conversation->assigned_at ?? now(),
            ])
        )->save();

        $acceptMessage = $this->createMessage(
            conversation: $conversation,
            senderType: SupportMessage::SENDER_SYSTEM,
            body: "{$agent->name} joined the chat.",
            senderUserId: $agent->id,
            metadata: ['type' => 'agent_joined'],
        );
        $this->broadcastMessageCreated($conversation->fresh(), $acceptMessage->fresh('sender'));
        $this->broadcastConversationChanged($conversation->fresh());

        return $conversation->fresh(['requester', 'assignee', 'skill']);
    }

    public function rejectAssignment(SupportConversation $conversation, User $agent, ?string $reason = null): SupportConversation
    {
        if ((int) $conversation->assigned_to !== (int) $agent->id) {
            throw new \InvalidArgumentException('You are not assigned to this conversation.');
        }

        if ($conversation->status !== SupportConversation::STATUS_PENDING_ACCEPTANCE) {
            throw new \LogicException('Conversation is not in a pending acceptance state.');
        }

        $conversation->forceFill([
            'status' => SupportConversation::STATUS_WAITING_HUMAN,
            'assigned_to' => null,
            'metadata' => $this->withWaitingForAgentMetadata($conversation),
        ])->forceFill(
            $this->filterExistingSupportConversationColumns([
                'assigned_at' => null,
                'assignment_state' => SupportConversation::ASSIGNMENT_STATE_UNASSIGNED,
            ])
        )->save();

        $this->supportRoutingService->enqueueConversation(
            $conversation->fresh(),
            reason: 'assignment_rejected',
            force: true
        );

        $rejectMessage = $this->createMessage(
            conversation: $conversation,
            senderType: SupportMessage::SENDER_SYSTEM,
            body: "{$agent->name} declined the assignment" . ($reason ? ": {$reason}" : "."),
            senderUserId: $agent->id,
            metadata: [
                'type' => 'assignment_rejected',
                'reason' => $reason
            ],
        );
        $this->broadcastMessageCreated($conversation->fresh(), $rejectMessage->fresh('sender'));
        $this->broadcastConversationChanged($conversation->fresh());

        // Mark agent as unavailable because they rejected/missed an offer
        $this->presenceService->setSupportStatus($agent, 'unavailable', false);

        return $conversation->fresh(['requester', 'assignee', 'skill']);
    }

    public function transfer(SupportConversation $conversation, User $actor, array $payload): SupportConversation
    {
        if (! $this->supportAccessAdapter()->canAssign($actor, $conversation)) {
            throw new AuthorizationException('Only support agents can transfer conversations.');
        }

        $targetSkillId = $payload['support_skill_id'] ?? null;
        $targetAgentId = $payload['assigned_to'] ?? null;

        if (! $targetSkillId && ! $targetAgentId) {
            throw new \InvalidArgumentException('Target skill or agent is required for transfer.');
        }

        $oldAgentName = $conversation->assignee?->name ?? 'None';
        $transferType = $targetAgentId ? 'agent' : 'skill';

        if ($targetAgentId) {
            $targetAgent = User::findOrFail($targetAgentId);
            if (! $this->supportAccessAdapter()->canBeAssignedToConversation($targetAgent, $conversation)) {
                throw new \InvalidArgumentException('Target agent is not eligible for this conversation.');
            }

            $this->assertAgentHasCapacity($targetAgent, $conversation);

            $conversation->forceFill([
                'assigned_to' => $targetAgent->id,
                'status' => SupportConversation::STATUS_PENDING_ACCEPTANCE,
                'metadata' => $this->withoutWaitingForAgentMetadata($conversation),
            ])->forceFill(
                $this->filterExistingSupportConversationColumns([
                    'assigned_at' => now(),
                    'assignment_state' => SupportConversation::ASSIGNMENT_STATE_PENDING,
                ])
            )->save();

            $body = "{$actor->name} transferred this conversation to {$targetAgent->name}.";
        } else {
            $skill = DB::table('support_skills')->where('id', $targetSkillId)->first();
            if (! $skill) {
                throw new \InvalidArgumentException('Target skill not found.');
            }

            $conversation->forceFill([
                'assigned_to' => null,
                'support_skill_id' => $skill->id,
                'status' => SupportConversation::STATUS_WAITING_HUMAN,
                'metadata' => $this->withWaitingForAgentMetadata($conversation),
            ])->forceFill(
                $this->filterExistingSupportConversationColumns([
                    'assigned_at' => null,
                    'assignment_state' => SupportConversation::ASSIGNMENT_STATE_UNASSIGNED,
                ])
            )->save();

            $this->supportRoutingService->enqueueConversation(
                $conversation->fresh(),
                reason: 'manual_transfer',
                force: true
            );

            $body = "{$actor->name} transferred this conversation back to the queue ({$skill->name}).";
        }

        $transferMessage = $this->createMessage(
            conversation: $conversation,
            senderType: SupportMessage::SENDER_SYSTEM,
            body: $body,
            senderUserId: $actor->id,
            metadata: [
                'type' => 'transfer',
                'transfer_type' => $transferType,
                'from_agent' => $oldAgentName,
                'to_skill_id' => $targetSkillId,
                'to_agent_id' => $targetAgentId
            ],
        );
        $this->broadcastMessageCreated($conversation->fresh(), $transferMessage->fresh('sender'));
        $this->broadcastConversationChanged($conversation->fresh());

        return $conversation->fresh(['requester', 'assignee', 'skill']);
    }

    public function assignConversation(SupportConversation $conversation, User $agent, User $actor): SupportConversation
    {
        if (! $this->supportAccessAdapter()->canAssign($actor, $conversation)) {
            throw new AuthorizationException('Only support agents can assign conversations.');
        }

        if (! $this->supportAccessAdapter()->canBeAssignedToConversation($agent, $conversation)) {
            throw new \InvalidArgumentException('Assigned user must be an eligible support agent.');
        }
        if ($conversation->isClosedLike()) {
            throw new \InvalidArgumentException('Cannot assign an ended/resolved conversation.');
        }

        $this->assertAgentHasCapacity($agent, $conversation);

        $isSelfAssignment = (int) $actor->id === (int) $agent->id;
        $status = $isSelfAssignment ? SupportConversation::STATUS_ASSIGNED : SupportConversation::STATUS_PENDING_ACCEPTANCE;
        $assignmentState = $isSelfAssignment ? SupportConversation::ASSIGNMENT_STATE_ASSIGNED : SupportConversation::ASSIGNMENT_STATE_PENDING;

        $conversation->forceFill([
            'assigned_to' => $agent->id,
            'status' => $status,
            'ai_handoff_required' => false,
            'ai_handoff_reason' => null,
            'metadata' => $this->withoutWaitingForAgentMetadata($conversation),
        ])->forceFill(
            $this->filterExistingSupportConversationColumns([
                'assigned_at' => now(),
            ])
        )->forceFill(
            $this->filterExistingSupportConversationColumns([
                'chat_state' => SupportConversation::CHAT_STATE_NEW,
                'assignment_state' => $assignmentState,
            ])
        )->save();

        if (! $isSelfAssignment) {
            $timeoutSeconds = max(30, (int) config('support_chat.routing.assignment_timeout_seconds', 60));
            SupportAssignmentTimeoutJob::dispatch($conversation->id, $agent->id)
                ->delay(now()->addSeconds($timeoutSeconds));
        }

        $this->supportRoutingService->markConversationAssigned(
            $conversation->fresh(),
            $agent->id,
            reason: 'manual_assignment'
        );

        $assignmentMessage = $this->createMessage(
            conversation: $conversation,
            senderType: SupportMessage::SENDER_SYSTEM,
            body: "{$actor->name} assigned this conversation to {$agent->name}.",
            senderUserId: $actor->id,
            metadata: ['type' => 'assignment'],
        );
        $this->broadcastMessageCreated($conversation->fresh(), $assignmentMessage->fresh('sender'));

        $this->broadcastConversationChanged($conversation->fresh());

        return $conversation->fresh(['requester', 'assignee', 'endedBy', 'skill', 'latestMessage']);
    }

    public function resolveConversation(SupportConversation $conversation, User $actor, array $options = []): SupportConversation
    {
        if (! $this->supportAccessAdapter()->canResolve($actor, $conversation)) {
            throw new AuthorizationException('Only support agents can resolve conversations.');
        }

        $resolutionMarker = $this->normalizeResolutionMarker(
            $options['resolution_marker'] ?? SupportConversation::RESOLUTION_MARKER_RESOLVED
        );

        $conversation->forceFill([
            'status' => SupportConversation::STATUS_WRAP_UP,
        ])->forceFill(
            $this->filterExistingSupportConversationColumns([
                'chat_state' => SupportConversation::CHAT_STATE_ENDED,
                'assignment_state' => $conversation->assigned_to
                    ? SupportConversation::ASSIGNMENT_STATE_ASSIGNED
                    : SupportConversation::ASSIGNMENT_STATE_UNASSIGNED,
                'resolution_marker' => $resolutionMarker,
                'resolved_at' => $resolutionMarker === SupportConversation::RESOLUTION_MARKER_RESOLVED ? now() : null,
                'ended_at' => $conversation->ended_at ?? now(),
                'ended_by_type' => 'agent',
                'ended_by_user_id' => $actor->id,
                'ended_by_name' => $actor->name,
                'end_reason' => SupportConversation::END_REASON_AGENT_ENDED,
            ])
        )->save();

        $this->supportRoutingService->cancelConversationQueue(
            $conversation->fresh(),
            reason: 'resolved'
        );

        $this->supportRoutingService->triggerImmediateRouting();

        $resolutionMessage = $this->createMessage(
            conversation: $conversation,
            senderType: SupportMessage::SENDER_SYSTEM,
            body: "{$actor->name} ended the interaction and opened close-out.",
            senderUserId: $actor->id,
            metadata: [
                'type' => 'close_started',
                'resolution_marker' => $resolutionMarker,
            ],
        );
        $this->broadcastMessageCreated($conversation->fresh(), $resolutionMessage->fresh('sender'));

        $this->broadcastConversationChanged($conversation->fresh());

        return $conversation->fresh(['requester', 'assignee', 'endedBy', 'skill', 'latestMessage']);
    }

    public function completeWrapUp(SupportConversation $conversation, User $actor, array $options = []): SupportConversation
    {
        if (! $this->supportAccessAdapter()->canResolve($actor, $conversation)) {
            throw new AuthorizationException('Only support agents can finalize close-out.');
        }

        if ($conversation->status !== SupportConversation::STATUS_WRAP_UP) {
            throw new \LogicException('Conversation is not in close-out state.');
        }

        $resolutionMarker = $this->normalizeResolutionMarker(
            $options['resolution_marker'] ?? $conversation->resolution_marker
        );

        $conversation->forceFill([
            'status' => SupportConversation::STATUS_CLOSED,
            'closed_at' => now(),
        ])->forceFill(
            $this->filterExistingSupportConversationColumns([
                'resolution_marker' => $resolutionMarker,
                'resolved_at' => $resolutionMarker === SupportConversation::RESOLUTION_MARKER_RESOLVED ? now() : null,
            ])
        )->save();

        $closeMessage = $this->createMessage(
            conversation: $conversation,
            senderType: SupportMessage::SENDER_SYSTEM,
            body: "{$actor->name} completed close-out ({$resolutionMarker}).",
            senderUserId: $actor->id,
            metadata: [
                'type' => 'close_completed',
                'resolution_marker' => $resolutionMarker,
            ],
        );
        $this->broadcastMessageCreated($conversation->fresh(), $closeMessage->fresh('sender'));
        $this->broadcastConversationChanged($conversation->fresh());

        return $conversation->fresh(['requester', 'assignee', 'endedBy', 'skill', 'latestMessage']);
    }

    /**
     * @param  array<string, mixed>  $options
     */
    public function closeConversation(
        SupportConversation $conversation,
        ?User $actor = null,
        ?string $guestToken = null,
        array $options = []
    ): SupportConversation {
        $conversation = $this->getConversationForActor($conversation, $actor, $guestToken);

        if (
            $conversation->status === SupportConversation::STATUS_CLOSED
            || $conversation->chat_state === SupportConversation::CHAT_STATE_ENDED
        ) {
            return $conversation->fresh(['requester', 'assignee', 'endedBy', 'skill', 'latestMessage.sender', 'latestMessage.media', 'messages.sender', 'messages.media']);
        }

        $endedByType = 'guest';
        $endedByUserId = null;
        $endedByName = trim((string) ($options['ended_by_name'] ?? $conversation->guest_name ?? 'Guest'));
        if ($endedByName === '') {
            $endedByName = 'Guest';
        }

        if ($actor) {
            if ($this->canOperateAsAgent($actor)) {
                $endedByType = 'agent';
                $endedByUserId = $actor->id;
                $endedByName = (string) $actor->name;
            } elseif ((int) $conversation->requester_user_id === (int) $actor->id) {
                $endedByType = 'customer';
                $endedByUserId = $actor->id;
                $endedByName = (string) $actor->name;
            }
        }
        $endReason = $this->normalizeEndReason(
            $options['end_reason'] ?? null,
            $endedByType
        );

        $existingColumns = $this->existingSupportConversationColumns();

        $oldValues = [
            'status' => $conversation->status,
            'closed_at' => $conversation->closed_at?->toISOString(),
        ];
        if (isset($existingColumns['chat_state'])) {
            $oldValues['chat_state'] = $conversation->chat_state;
        }
        if (isset($existingColumns['assignment_state'])) {
            $oldValues['assignment_state'] = $conversation->assignment_state;
        }
        if (isset($existingColumns['resolution_marker'])) {
            $oldValues['resolution_marker'] = $conversation->resolution_marker;
        }
        if (isset($existingColumns['end_reason'])) {
            $oldValues['end_reason'] = $conversation->end_reason;
        }

        if (isset($existingColumns['ended_at'])) {
            $oldValues['ended_at'] = $conversation->ended_at?->toISOString();
        }
        if (isset($existingColumns['ended_by_type'])) {
            $oldValues['ended_by_type'] = $conversation->ended_by_type;
        }
        if (isset($existingColumns['ended_by_user_id'])) {
            $oldValues['ended_by_user_id'] = $conversation->ended_by_user_id;
        }
        if (isset($existingColumns['ended_by_name'])) {
            $oldValues['ended_by_name'] = $conversation->ended_by_name;
        }

        $conversationUpdates = $this->filterExistingSupportConversationColumns([
            'status' => ($endedByType === 'agent' || $conversation->assigned_to)
                ? SupportConversation::STATUS_WRAP_UP
                : SupportConversation::STATUS_CLOSED,
            'closed_at' => ($endedByType === 'agent' || $conversation->assigned_to)
                ? null
                : now(),
            'ended_at' => now(),
            'ended_by_type' => $endedByType,
            'ended_by_user_id' => $endedByUserId,
            'ended_by_name' => $endedByName,
            'chat_state' => SupportConversation::CHAT_STATE_ENDED,
            'assignment_state' => $conversation->assigned_to
                ? SupportConversation::ASSIGNMENT_STATE_ASSIGNED
                : SupportConversation::ASSIGNMENT_STATE_UNASSIGNED,
            'resolution_marker' => $conversation->resolution_marker
                ?: SupportConversation::RESOLUTION_MARKER_UNRESOLVED,
            'resolved_at' => $conversation->resolution_marker === SupportConversation::RESOLUTION_MARKER_RESOLVED
                ? ($conversation->resolved_at ?? now())
                : null,
            'end_reason' => $endReason,
            'ai_handoff_required' => false,
        ]);
        $conversation->forceFill($conversationUpdates)->save();
        $this->supportRoutingService->cancelConversationQueue(
            $conversation->fresh(),
            reason: 'conversation_closed'
        );

        $this->supportRoutingService->triggerImmediateRouting();

        $label = $endedByType === 'agent'
            ? "{$endedByName} (Agent)"
            : ($endedByType === 'customer' ? "{$endedByName} (Customer)" : "{$endedByName} (Guest)");

        $endingMessage = $this->createMessage(
            conversation: $conversation,
            senderType: SupportMessage::SENDER_SYSTEM,
            body: "{$label} ended this support conversation.",
            senderUserId: $endedByUserId,
            metadata: [
                'type' => ($endedByType === 'agent' || $conversation->assigned_to) ? 'close_started' : 'conversation_closed',
                'ended_by_type' => $endedByType,
                'ended_by_name' => $endedByName,
                'end_reason' => $endReason,
            ],
        );
        $this->broadcastMessageCreated($conversation->fresh(), $endingMessage->fresh('sender'));
        $this->broadcastConversationChanged($conversation->fresh());

        $this->auditService->log(
            action: AuditAction::Updated,
            category: AuditCategory::Communication,
            auditable: $conversation,
            user: $actor,
            oldValues: $oldValues,
            newValues: $this->buildCloseConversationAuditNewValues($conversation, $existingColumns),
            context: [
                'event' => 'support_chat_ended',
                'actor_type' => $endedByType,
                'conversation_public_id' => $conversation->public_id,
            ]
        );

        return $conversation->fresh(['requester', 'assignee', 'endedBy', 'skill', 'latestMessage.sender', 'latestMessage.media', 'messages.sender', 'messages.media']);
    }

    public function updateSurveyPreference(
        SupportConversation $conversation,
        bool $optOut,
        ?User $actor = null,
        ?string $guestToken = null
    ): SupportConversation {
        $conversation = $this->getConversationForActor($conversation, $actor, $guestToken);

        $updates = $this->filterExistingSupportConversationColumns([
            'survey_opt_out' => $optOut,
            'survey_opt_out_at' => $optOut ? now() : null,
        ]);
        if (! empty($updates)) {
            $conversation->forceFill($updates)->save();
        }

        if ($optOut) {
            SupportSurveyInvite::query()
                ->where('conversation_id', $conversation->id)
                ->where('status', SupportSurveyInvite::STATUS_PENDING)
                ->update([
                    'status' => SupportSurveyInvite::STATUS_REVOKED,
                    'updated_at' => now(),
                ]);
        }

        $this->broadcastConversationChanged($conversation->fresh());

        return $conversation->fresh(['requester', 'assignee', 'endedBy', 'skill', 'latestMessage.sender', 'latestMessage.media', 'messages.sender', 'messages.media']);
    }

    /**
     * @param  array<string, mixed>  $attributes
     * @return array<string, mixed>
     */
    protected function filterExistingSupportConversationColumns(array $attributes): array
    {
        $columns = $this->existingSupportConversationColumns();

        return array_filter(
            $attributes,
            static fn (string $key): bool => isset($columns[$key]),
            ARRAY_FILTER_USE_KEY
        );
    }

    /**
     * @return array<string, true>
     */
    protected function existingSupportConversationColumns(): array
    {
        if ($this->supportConversationColumnMap !== null) {
            return $this->supportConversationColumnMap;
        }

        try {
            $columns = Schema::getColumnListing('support_conversations');
            $this->supportConversationColumnMap = array_fill_keys($columns, true);
        } catch (\Throwable) {
            $this->supportConversationColumnMap = [];
        }

        return $this->supportConversationColumnMap;
    }

    /**
     * @param  array<string, true>  $existingColumns
     * @return array<string, mixed>
     */
    protected function buildCloseConversationAuditNewValues(SupportConversation $conversation, array $existingColumns): array
    {
        $values = [
            'status' => $conversation->status,
            'closed_at' => $conversation->closed_at?->toISOString(),
        ];
        if (isset($existingColumns['chat_state'])) {
            $values['chat_state'] = $conversation->chat_state;
        }
        if (isset($existingColumns['assignment_state'])) {
            $values['assignment_state'] = $conversation->assignment_state;
        }
        if (isset($existingColumns['resolution_marker'])) {
            $values['resolution_marker'] = $conversation->resolution_marker;
        }
        if (isset($existingColumns['end_reason'])) {
            $values['end_reason'] = $conversation->end_reason;
        }

        if (isset($existingColumns['ended_at'])) {
            $values['ended_at'] = $conversation->ended_at?->toISOString();
        }
        if (isset($existingColumns['ended_by_type'])) {
            $values['ended_by_type'] = $conversation->ended_by_type;
        }
        if (isset($existingColumns['ended_by_user_id'])) {
            $values['ended_by_user_id'] = $conversation->ended_by_user_id;
        }
        if (isset($existingColumns['ended_by_name'])) {
            $values['ended_by_name'] = $conversation->ended_by_name;
        }

        return $values;
    }

    public function claimConversationToUser(SupportConversation $conversation, User $user): SupportConversation
    {
        if ($conversation->requester_user_id && (int) $conversation->requester_user_id !== (int) $user->id) {
            throw new AuthorizationException('This support conversation is already claimed by another user.');
        }

        if (! $conversation->requester_user_id) {
            $conversation->forceFill([
                'requester_user_id' => $user->id,
                'guest_name' => $conversation->guest_name ?: $user->name,
                'guest_email' => $conversation->guest_email ?: $user->email,
                'guest_token' => null,
            ])->save();

            $this->broadcastConversationChanged($conversation->fresh());
        }

        return $conversation->fresh(['requester', 'assignee', 'endedBy', 'skill', 'latestMessage.sender', 'latestMessage.media', 'messages.sender', 'messages.media']);
    }

    public function findActiveConversationByReference(string $chatReference, ?User $actor = null, ?string $guestEmail = null): ?SupportConversation
    {
        $normalizedReference = SupportConversation::chatReferenceFromPublicId($chatReference);
        if ($normalizedReference === '') {
            return null;
        }

        $query = SupportConversation::query();

        if ($actor) {
            $query->where('requester_user_id', $actor->id);
        } else {
            $normalizedEmail = mb_strtolower(trim((string) $guestEmail));
            if ($normalizedEmail === '') {
                return null;
            }

            $query->whereNull('requester_user_id')
                ->whereRaw('LOWER(guest_email) = ?', [$normalizedEmail]);
        }

        $query->whereNotIn('status', [
            SupportConversation::STATUS_RESOLVED,
            SupportConversation::STATUS_CLOSED,
        ]);

        $existingColumns = $this->existingSupportConversationColumns();
        if (isset($existingColumns['chat_state'])) {
            $query->where(function (Builder $builder): void {
                $builder->whereNull('chat_state')
                    ->orWhere('chat_state', '!=', SupportConversation::CHAT_STATE_ENDED);
            });
        }
        if (isset($existingColumns['ended_at'])) {
            $query->whereNull('ended_at');
        }

        $matches = $query
            ->latest('created_at')
            ->get()
            ->filter(fn (SupportConversation $conversation): bool => $conversation->chat_reference === $normalizedReference)
            ->values();

        if ($matches->count() > 1) {
            throw new \RuntimeException('Multiple active conversations matched that chat ID.');
        }

        return $matches->first();
    }

    public function customerHistory(User $user, int $limit = 20): Collection
    {
        $safeLimit = max(1, min(100, $limit));

        return SupportConversation::query()
            ->with([
                'requester:id,public_id,name,email',
                'assignee:id,public_id,name,email',
                'endedBy:id,public_id,name,email',
                'skill:id,public_id,name,slug,department',
                'routingQueueEntry',
                'latestPublicMessage.sender:id,public_id,name,email',
                'latestPublicMessage.media',
                'latestMessage.sender:id,public_id,name,email',
                'latestMessage.media',
            ])
            ->where('requester_user_id', $user->id)
            ->orderByDesc('last_message_at')
            ->orderByDesc('updated_at')
            ->orderByDesc('created_at')
            ->limit($safeLimit)
            ->get();
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function inbox(User $agent, string $scope = 'mine', array $filters = []): LengthAwarePaginator
    {
        if (! $this->supportAccessAdapter()->canViewAny($agent)) {
            throw new AuthorizationException('Only support agents can access support inbox.');
        }

        $status = isset($filters['status']) ? trim((string) $filters['status']) : null;
        $search = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $defaultPerPage = (int) config('support_chat.default_per_page', 20);
        $maxPerPage = (int) config('support_chat.max_per_page', 100);
        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : $defaultPerPage;
        $safePerPage = max(1, min($maxPerPage, $perPage));

        $query = SupportConversation::query()
            ->with([
                'requester:id,public_id,name,email',
                'assignee:id,public_id,name,email',
                'endedBy:id,public_id,name,email',
                'skill:id,public_id,name,slug,department',
                'latestMessage.sender:id,public_id,name,email',
                'latestMessage.media',
            ])
            ->orderByDesc('last_message_at')
            ->orderByDesc('updated_at');

        $this->supportAccessAdapter()->applyInboxAccessScope($agent, $query);

        if ($scope === 'mine') {
            $query->where('assigned_to', $agent->id);
        } elseif ($scope === 'unassigned') {
            $columns = $this->existingSupportConversationColumns();
            if (isset($columns['assignment_state']) && isset($columns['chat_state'])) {
                $query->where('assignment_state', SupportConversation::ASSIGNMENT_STATE_UNASSIGNED)
                    ->where('chat_state', SupportConversation::CHAT_STATE_NEW);
            } else {
                $query->whereNull('assigned_to')
                    ->whereIn('status', [
                        SupportConversation::STATUS_OPEN,
                        SupportConversation::STATUS_BOT_ACTIVE,
                        SupportConversation::STATUS_WAITING_HUMAN,
                    ]);
            }
        } elseif ($scope === 'waiting') {
            $query->where(function (Builder $waitingScope): void {
                $waitingScope->where(function (Builder $unassignedWaiting): void {
                    $unassignedWaiting->whereNull('assigned_to')
                        ->whereIn('status', [
                            SupportConversation::STATUS_OPEN,
                            SupportConversation::STATUS_WAITING_HUMAN,
                        ]);
                })->orWhere('status', SupportConversation::STATUS_PENDING_ACCEPTANCE);
            });
        } elseif ($scope === 'ai') {
            $query->whereNull('assigned_to')
                ->where(function (Builder $aiScope): void {
                    $aiScope->where('status', SupportConversation::STATUS_BOT_ACTIVE)
                        ->orWhere(function (Builder $legacyAiScope): void {
                            $legacyAiScope->where('status', SupportConversation::STATUS_OPEN)
                                ->where('ai_enabled', true)
                                ->where(function (Builder $handoffFlag): void {
                                    $handoffFlag->whereNull('ai_handoff_required')
                                        ->orWhere('ai_handoff_required', false);
                                });
                        });
                });
        } elseif ($scope === 'assigned_all') {
            $query->whereNotNull('assigned_to')
                ->whereNotIn('status', [
                    SupportConversation::STATUS_RESOLVED,
                    SupportConversation::STATUS_CLOSED
                ]);
        }

        if ($status) {
            $query->where('status', $status);
        }

        if ($search) {
            $query->where(function (Builder $builder) use ($search) {
                $builder->where('subject', 'like', "%{$search}%")
                    ->orWhere('guest_name', 'like', "%{$search}%")
                    ->orWhere('guest_email', 'like', "%{$search}%")
                    ->orWhereHas('requester', fn (Builder $q) => $q->where('name', 'like', "%{$search}%")->orWhere('email', 'like', "%{$search}%"))
                    ->orWhereHas('latestMessage', fn (Builder $q) => $q->where('body', 'like', "%{$search}%"));
            });
        }

        $paginator = $query->paginate($safePerPage);
        $this->attachInboxQueueTelemetry($paginator->getCollection());

        return $paginator;
    }

    /**
     * @param  Collection<int, SupportConversation>  $conversations
     */
    protected function attachInboxQueueTelemetry(Collection $conversations): void
    {
        if ($conversations->isEmpty()) {
            return;
        }

        $conversationIds = $conversations
            ->pluck('id')
            ->filter(fn ($id) => is_numeric($id))
            ->map(fn ($id) => (int) $id)
            ->values();

        if ($conversationIds->isEmpty()) {
            return;
        }

        $entriesByConversation = SupportRoutingQueueEntry::query()
            ->whereIn('conversation_id', $conversationIds->all())
            ->get(['conversation_id', 'state', 'created_at', 'next_attempt_at'])
            ->keyBy('conversation_id');

        $positionByConversation = SupportRoutingQueueEntry::query()
            ->whereIn('state', [
                SupportRoutingQueueEntry::STATE_PENDING,
                SupportRoutingQueueEntry::STATE_ROUTING,
            ])
            ->orderBy('priority')
            ->orderBy('created_at')
            ->orderBy('id')
            ->get(['conversation_id'])
            ->pluck('conversation_id')
            ->map(fn ($id) => (int) $id)
            ->values()
            ->flip()
            ->map(fn (int $index) => $index + 1);

        foreach ($conversations as $conversation) {
            $conversationId = (int) $conversation->id;
            $entry = $entriesByConversation->get($conversationId);

            $conversation->setAttribute('queue_state', $entry?->state);
            $conversation->setAttribute('queue_entered_at', $entry?->created_at);
            $conversation->setAttribute('queue_next_attempt_at', $entry?->next_attempt_at);
            $conversation->setAttribute('queue_position', $positionByConversation->get($conversationId));
        }
    }

    public function canOperateAsAgent(User $user): bool
    {
        return $this->supportAccessAdapter()->canOperateAsAgent($user);
    }

    /**
     * @return Collection<int, User>
     */
    public function eligibleAgents(): Collection
    {
        $query = User::query();
        $this->supportAccessAdapter()->applyEligibleAgentsScope($query);

        return $query
            ->where('status', 'active')
            ->select(['id', 'public_id', 'name', 'email', 'status', 'support_status', 'support_status_at', 'support_available'])
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array{available: bool, available_agents: int, message: string}
     */
    public function availability(): array
    {
        $eligibleAgents = $this->eligibleAgents();
        $agentIds = $eligibleAgents->pluck('id');
        $todayStart = now()->startOfDay();
        $now = now();
        $defaultAgentCapacity = $this->defaultAgentCapacity();
        $columns = $this->existingSupportConversationColumns();
        $hasAssignedAt = isset($columns['assigned_at']);
        $hasEndedAt = isset($columns['ended_at']);

        // Fetch active chats count per agent
        $activeChatCountsQuery = SupportConversation::query()
            ->whereNotNull('assigned_to')
            ->whereIn('assigned_to', $agentIds)
            ->whereNotIn('status', [
                SupportConversation::STATUS_RESOLVED,
                SupportConversation::STATUS_CLOSED,
            ]);

        if (Schema::hasColumn('support_conversations', 'chat_state')) {
            $activeChatCountsQuery->where('chat_state', SupportConversation::CHAT_STATE_NEW);
        }

        $activeChatCounts = $activeChatCountsQuery
            ->selectRaw('assigned_to, count(*) as count')
            ->groupBy('assigned_to')
            ->pluck('count', 'assigned_to');

        $activeConversations = SupportConversation::query()
            ->whereNotNull('assigned_to')
            ->whereIn('assigned_to', $agentIds)
            ->whereNotIn('status', [
                SupportConversation::STATUS_RESOLVED,
                SupportConversation::STATUS_CLOSED,
            ]);

        if (Schema::hasColumn('support_conversations', 'chat_state')) {
            $activeConversations->where('chat_state', SupportConversation::CHAT_STATE_NEW);
        }

        $activeConversations = $activeConversations
            ->get(array_values(array_filter([
                'assigned_to',
                $hasAssignedAt ? 'assigned_at' : null,
                'created_at',
                'first_response_at',
            ])))
            ->groupBy('assigned_to');

        $completedConversations = SupportConversation::query()
            ->whereNotNull('assigned_to')
            ->whereIn('assigned_to', $agentIds)
            ->where(function (Builder $builder) use ($todayStart): void {
                if ($this->existingSupportConversationColumns()['ended_at'] ?? false) {
                    $builder->where(function (Builder $endedQuery) use ($todayStart): void {
                        $endedQuery->whereNotNull('ended_at')
                            ->where('ended_at', '>=', $todayStart);
                    })->orWhere(function (Builder $closedQuery) use ($todayStart): void {
                        $closedQuery->whereNull('ended_at')
                            ->whereNotNull('closed_at')
                            ->where('closed_at', '>=', $todayStart);
                    });
                    return;
                }

                $builder->whereNotNull('closed_at')
                    ->where('closed_at', '>=', $todayStart);
            })
            ->get(array_values(array_filter([
                'assigned_to',
                $hasAssignedAt ? 'assigned_at' : null,
                'created_at',
                'first_response_at',
                $hasEndedAt ? 'ended_at' : null,
                'closed_at',
            ])))
            ->groupBy('assigned_to');

        $transferMessages = SupportMessage::query()
            ->where('sender_type', SupportMessage::SENDER_SYSTEM)
            ->whereNotNull('sender_user_id')
            ->whereIn('sender_user_id', $agentIds)
            ->where('created_at', '>=', $todayStart)
            ->get(['sender_user_id', 'metadata'])
            ->groupBy('sender_user_id');

        $surveyResponses = SupportSurveyResponse::query()
            ->whereNotNull('rated_agent_user_id')
            ->whereIn('rated_agent_user_id', $agentIds)
            ->where('created_at', '>=', $todayStart)
            ->get(['rated_agent_user_id', 'survey_type', 'score'])
            ->groupBy('rated_agent_user_id');

        $skillMemberships = SupportSkillMembership::query()
            ->whereIn('user_id', $agentIds)
            ->where('is_active', true)
            ->get(['user_id', 'capacity', 'is_primary'])
            ->groupBy('user_id');

        $agentsList = $eligibleAgents->map(function ($agent) use ($activeChatCounts, $activeConversations, $completedConversations, $transferMessages, $surveyResponses, $skillMemberships, $defaultAgentCapacity, $now) {
            $supportAvailable = $this->presenceService->isSupportAvailable((int) $agent->id);
            $isOnline = $this->presenceService->isUserActive((int) $agent->id);
            $activeForAgent = $activeConversations->get($agent->id, collect());
            $completedForAgent = $completedConversations->get($agent->id, collect());
            $membershipRows = $skillMemberships->get($agent->id, collect());
            $primaryCapacity = $membershipRows
                ->first(fn (SupportSkillMembership $membership) => (bool) $membership->is_primary)?->capacity;
            $fallbackCapacity = $membershipRows
                ->pluck('capacity')
                ->filter(fn ($capacity) => $capacity !== null)
                ->map(fn ($capacity) => $this->clampAgentCapacity((int) $capacity))
                ->max();
            $agentCapacity = $this->clampAgentCapacity((int) ($primaryCapacity ?? $fallbackCapacity ?? $defaultAgentCapacity));
            $handleSeconds = $completedForAgent
                ->map(function (SupportConversation $conversation): ?int {
                    $assignedAt = $conversation->assigned_at ?? $conversation->first_response_at ?? $conversation->created_at;
                    $endedAt = $conversation->ended_at ?? $conversation->closed_at;

                    if (! $assignedAt || ! $endedAt) {
                        return null;
                    }

                    return $assignedAt->diffInSeconds($endedAt);
                })
                ->filter(fn (?int $seconds): bool => is_int($seconds))
                ->values();
            $activeStartedAt = $activeForAgent
                ->map(fn (SupportConversation $conversation) => $conversation->assigned_at ?? $conversation->first_response_at ?? $conversation->created_at)
                ->filter()
                ->values();
            $workingSinceAt = $activeStartedAt
                ->sortBy(fn ($startedAt) => $startedAt->getTimestamp())
                ->first();
            $longestActiveChatSeconds = $activeStartedAt
                ->map(fn ($startedAt): int => $startedAt->diffInSeconds($now))
                ->max();
            $transfersToday = $transferMessages->get($agent->id, collect())
                ->filter(fn (SupportMessage $message) => ($message->metadata['type'] ?? null) === 'transfer')
                ->count();
            $missedToday = $transferMessages->get($agent->id, collect())
                ->filter(fn (SupportMessage $message) => 
                    ($message->metadata['type'] ?? null) === 'assignment_rejected' && 
                    ($message->metadata['reason'] ?? null) === 'Assignment timed out.'
                )->count();
            $rejectedToday = $transferMessages->get($agent->id, collect())
                ->filter(fn (SupportMessage $message) => 
                    ($message->metadata['type'] ?? null) === 'assignment_rejected' && 
                    ($message->metadata['reason'] ?? null) !== 'Assignment timed out.'
                )->count();
            $surveyRows = $surveyResponses->get($agent->id, collect());
            $csatRows = $surveyRows
                ->where('survey_type', SupportSurveyInvite::TYPE_CSAT)
                ->values();
            $surveyCsatAverageToday = $csatRows->isNotEmpty()
                ? round((float) $csatRows->avg('score'), 2)
                : null;

            return [
                'public_id' => $agent->public_id,
                'name' => $agent->name,
                'avatar_thumb_url' => $agent->avatar_thumb_url,
                'status' => $agent->status,
                'is_online' => $isOnline,
                'support_status' => $agent->support_status ?? 'unavailable',
                'support_status_at' => $agent->support_status_at?->toISOString(),
                'support_available' => $supportAvailable,
                'active_chats' => $activeChatCounts->get($agent->id, 0),
                'agent_capacity' => $agentCapacity,
                'working_since_at' => $workingSinceAt?->toISOString(),
                'longest_active_chat_seconds' => is_numeric($longestActiveChatSeconds)
                    ? (int) $longestActiveChatSeconds
                    : null,
                'completed_today' => $completedForAgent->count(),
                'average_handle_time_seconds' => $handleSeconds->isNotEmpty()
                    ? (int) round($handleSeconds->avg())
                    : null,
                'transfers_today' => $transfersToday,
                'missed_today' => $missedToday,
                'rejected_today' => $rejectedToday,
                'survey_responses_today' => $surveyRows->count(),
                'survey_csat_average_today' => $surveyCsatAverageToday,
            ];
        })->filter(fn (array $agent) => $agent['is_online'])->sortBy([
            ['support_available', 'desc'],
            ['active_chats', 'desc'],
            ['name', 'asc'],
        ])->values();
        $availableCount = $agentsList->where('support_available', true)->count();

        return [
            'available' => $availableCount > 0,
            'available_agents' => $availableCount,
            'agents' => $agentsList->toArray(),
            'message' => $availableCount > 0
                ? 'Support agents are currently available.'
                : 'All support specialists are currently assisting other customers. You can leave a message and we will follow up as soon as possible.',
        ];
    }

    public function workbenchCapacity(User $agent): array
    {
        $maxPanels = $this->workbenchMaxPanels();
        $hardCap = $this->hardChatCap();
        $agentCapacity = $this->resolveAgentCapacity($agent);
        $activeChats = $this->activeAssignedChatsCount($agent->id);
        $availableSlots = max(0, $agentCapacity - $activeChats);
        $effectivePanelLimit = max(1, min($maxPanels, $agentCapacity));

        return [
            'max_panels' => $maxPanels,
            'hard_cap' => $hardCap,
            'agent_capacity' => $agentCapacity,
            'active_chats' => $activeChats,
            'available_slots' => $availableSlots,
            'effective_panel_limit' => $effectivePanelLimit,
        ];
    }

    protected function supportAccessAdapter(): SupportAccessAdapterContract
    {
        return $this->supportAccessAdapterResolver->resolve();
    }

    /**
     * @param  array<string, mixed>  $metadata
     */
    protected function createMessage(
        SupportConversation $conversation,
        string $senderType,
        ?string $body,
        ?int $senderUserId = null,
        bool $isPrivateNote = false,
        array $metadata = []
    ): SupportMessage {
        $message = SupportMessage::create([
            'conversation_id' => $conversation->id,
            'sender_type' => $senderType,
            'sender_user_id' => $senderUserId,
            'body' => $body,
            'is_private_note' => $isPrivateNote,
            'metadata' => $metadata ?: null,
        ]);

        if (! $isPrivateNote) {
            $conversation->forceFill([
                'last_message_at' => $message->created_at,
            ])->save();
        }

        return $message;
    }

    protected function processAiForCustomerMessage(SupportConversation $conversation, string $incomingBody): void
    {
        $conversation = SupportConversation::query()->find($conversation->id) ?? $conversation;

        if ($this->shouldSkipAiAutoReply($conversation)) {
            return;
        }

        $decision = $this->handoffPipeline->handle($conversation, $incomingBody);
        $botReply = trim((string) ($decision['reply'] ?? ''));
        if ($botReply === '') {
            return;
        }

        // Re-check state after AI pipeline to avoid race conditions:
        // a conversation can become assigned/resolved while AI is generating a reply.
        $conversation = $conversation->fresh() ?? $conversation;
        if ($this->shouldSkipAiAutoReply($conversation)) {
            return;
        }

        $botMessage = $this->createMessage(
            conversation: $conversation,
            senderType: SupportMessage::SENDER_BOT,
            body: $botReply,
            metadata: [
                'confidence' => $decision['confidence'] ?? null,
                'escalate' => $decision['escalate'] ?? false,
                'reason' => $decision['reason'] ?? null,
            ],
        );
        $this->broadcastMessageCreated($conversation->fresh(), $botMessage->fresh('sender'));

        $escalate = (bool) ($decision['escalate'] ?? false);
        if ($escalate) {
            $availability = $this->availability();
            $meta = $this->withWaitingForAgentMetadata($conversation);
            $meta['availability'] = $availability;

            $conversation->forceFill([
                'status' => SupportConversation::STATUS_WAITING_HUMAN,
                'ai_handoff_required' => true,
                'ai_handoff_reason' => $decision['reason'] ?? null,
                'metadata' => $meta,
            ])->forceFill(
                $this->filterExistingSupportConversationColumns([
                    'chat_state' => SupportConversation::CHAT_STATE_NEW,
                    'assignment_state' => SupportConversation::ASSIGNMENT_STATE_UNASSIGNED,
                ])
            )->save();

            if (! $availability['available']) {
                $availabilityMessage = $this->createMessage(
                    conversation: $conversation,
                    senderType: SupportMessage::SENDER_SYSTEM,
                    body: $this->waitingForAgentCustomerMessage($conversation),
                    metadata: ['type' => 'availability'],
                );
                $this->broadcastMessageCreated($conversation->fresh(), $availabilityMessage->fresh('sender'));
            }

            return;
        }

        $aiAssistMetadata = $this->withAiAssistingMetadata($conversation);

        if ($conversation->status === SupportConversation::STATUS_OPEN) {
            $conversation->forceFill([
                'status' => SupportConversation::STATUS_BOT_ACTIVE,
                'metadata' => $aiAssistMetadata,
            ])->forceFill(
                $this->filterExistingSupportConversationColumns([
                    'chat_state' => SupportConversation::CHAT_STATE_NEW,
                    'assignment_state' => SupportConversation::ASSIGNMENT_STATE_UNASSIGNED,
                ])
            )->save();
            return;
        }

        if ($conversation->status === SupportConversation::STATUS_BOT_ACTIVE) {
            $currentMetadata = is_array($conversation->metadata) ? $conversation->metadata : [];
            if ($currentMetadata !== $aiAssistMetadata) {
                $conversation->forceFill([
                    'metadata' => $aiAssistMetadata,
                ])->save();
            }
        }
    }

    protected function normalizeConversationType(mixed $type): string
    {
        $normalized = strtolower(trim((string) ($type ?? '')));
        if (in_array($normalized, ['complaint'], true)) {
            return SupportConversation::TYPE_COMPLAINT;
        }

        if (in_array($normalized, ['inquery', 'inquiry'], true)) {
            return SupportConversation::TYPE_INQUIRY;
        }

        return SupportConversation::TYPE_INQUIRY;
    }

    protected function normalizeEndReason(mixed $reason, string $endedByType): string
    {
        $normalized = strtolower(trim((string) ($reason ?? '')));
        if ($normalized !== '' && in_array($normalized, [
            SupportConversation::END_REASON_USER_ENDED,
            SupportConversation::END_REASON_AGENT_ENDED,
            SupportConversation::END_REASON_GHOST_TIMEOUT,
            SupportConversation::END_REASON_ABANDONED,
            SupportConversation::END_REASON_SYSTEM_ENDED,
        ], true)) {
            return $normalized;
        }

        return match ($endedByType) {
            'agent' => SupportConversation::END_REASON_AGENT_ENDED,
            'customer', 'guest' => SupportConversation::END_REASON_USER_ENDED,
            default => SupportConversation::END_REASON_SYSTEM_ENDED,
        };
    }

    protected function normalizeResolutionMarker(mixed $marker): string
    {
        $normalized = strtolower(trim((string) ($marker ?? '')));

        return $normalized === SupportConversation::RESOLUTION_MARKER_RESOLVED
            ? SupportConversation::RESOLUTION_MARKER_RESOLVED
            : SupportConversation::RESOLUTION_MARKER_UNRESOLVED;
    }

    protected function shouldSkipAiAutoReply(SupportConversation $conversation): bool
    {
        if (! $conversation->ai_enabled || ! config('support_chat.ai_enabled', true)) {
            return true;
        }

        return (bool) (
            $conversation->assigned_to
            || $conversation->chat_state === SupportConversation::CHAT_STATE_ENDED
            || $conversation->assignment_state === SupportConversation::ASSIGNMENT_STATE_ASSIGNED
            || in_array($conversation->status, [
                SupportConversation::STATUS_ASSIGNED,
                SupportConversation::STATUS_WAITING_HUMAN,
                SupportConversation::STATUS_RESOLVED,
                SupportConversation::STATUS_CLOSED,
            ], true)
        );
    }

    /**
     * @return array<string, mixed>
     */
    protected function withWaitingForAgentMetadata(SupportConversation $conversation): array
    {
        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
        if (empty($metadata['waiting_for_agent_since'])) {
            $metadata['waiting_for_agent_since'] = now()->toIso8601String();
        }
        unset($metadata['ai_assisting_since']);

        return $metadata;
    }

    /**
     * @return array<string, mixed>
     */
    protected function withAiAssistingMetadata(SupportConversation $conversation): array
    {
        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
        if (empty($metadata['ai_assisting_since'])) {
            $metadata['ai_assisting_since'] = now()->toIso8601String();
        }
        unset($metadata['waiting_for_agent_since']);

        return $metadata;
    }

    /**
     * @return array<string, mixed>|null
     */
    protected function withoutWaitingForAgentMetadata(SupportConversation $conversation): ?array
    {
        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
        unset($metadata['waiting_for_agent_since']);
        unset($metadata['ai_assisting_since']);

        return $metadata !== [] ? $metadata : null;
    }

    protected function waitingForAgentCustomerMessage(SupportConversation $conversation): string
    {
        $metadata = is_array($conversation->metadata) ? $conversation->metadata : [];
        $waitingSinceRaw = $metadata['waiting_for_agent_since'] ?? null;

        $waitingSince = null;
        if (is_string($waitingSinceRaw) && trim($waitingSinceRaw) !== '') {
            try {
                $waitingSince = \Illuminate\Support\Carbon::parse($waitingSinceRaw);
            } catch (\Throwable) {
                $waitingSince = null;
            }
        }

        $queuePosition = $conversation->getAttribute('queue_position');
        if (! is_numeric($queuePosition)) {
            $queuePosition = $this->resolveQueuePositionForConversation($conversation);
        }
        $queuePosition = is_numeric($queuePosition) ? max(1, (int) $queuePosition) : null;

        if ($waitingSince && $waitingSince->diffInMinutes(now()) >= 30) {
            if ($queuePosition !== null) {
                return "Thank you for waiting. You are currently number {$queuePosition} in queue. It appears no support specialist is available right now, but you may leave a message and our team will follow up as soon as possible.";
            }

            return 'Thank you for waiting. It appears no support specialist is available right now, but you may leave a message and our team will follow up as soon as possible.';
        }

        if ($queuePosition !== null) {
            return "All support specialists are currently assisting other customers. You are number {$queuePosition} in queue, and the next available specialist will assist you shortly.";
        }

        return 'All support specialists are currently assisting other customers. You are in queue, and the next available specialist will assist you shortly.';
    }

    protected function resolveQueuePositionForConversation(SupportConversation $conversation): ?int
    {
        $entry = SupportRoutingQueueEntry::query()
            ->where('conversation_id', $conversation->id)
            ->whereIn('state', [
                SupportRoutingQueueEntry::STATE_PENDING,
                SupportRoutingQueueEntry::STATE_ROUTING,
            ])
            ->first(['id', 'priority', 'created_at']);

        if ($entry) {
            return SupportRoutingQueueEntry::query()
                ->whereIn('state', [
                    SupportRoutingQueueEntry::STATE_PENDING,
                    SupportRoutingQueueEntry::STATE_ROUTING,
                ])
                ->where(function (Builder $query) use ($entry): void {
                    $query->where('priority', '<', $entry->priority)
                        ->orWhere(function (Builder $samePriority) use ($entry): void {
                            $samePriority->where('priority', $entry->priority)
                                ->where(function (Builder $sameCreated) use ($entry): void {
                                    $sameCreated->where('created_at', '<', $entry->created_at)
                                        ->orWhere(function (Builder $sameInstant) use ($entry): void {
                                            $sameInstant->where('created_at', $entry->created_at)
                                                ->where('id', '<=', $entry->id);
                                        });
                                });
                        });
                })
                ->count();
        }

        if (
            $conversation->status !== SupportConversation::STATUS_WAITING_HUMAN
            || $conversation->assigned_to
        ) {
            return null;
        }

        $fallbackQuery = SupportConversation::query()
            ->whereNull('assigned_to')
            ->where('status', SupportConversation::STATUS_WAITING_HUMAN)
            ->where(function (Builder $query) use ($conversation): void {
                $query->where('created_at', '<', $conversation->created_at)
                    ->orWhere(function (Builder $sameCreatedAt) use ($conversation): void {
                        $sameCreatedAt->where('created_at', $conversation->created_at)
                            ->where('id', '<=', $conversation->id);
                    });
            });

        $columns = $this->existingSupportConversationColumns();
        if (isset($columns['chat_state'])) {
            $fallbackQuery->where(function (Builder $query): void {
                $query->whereNull('chat_state')
                    ->orWhere('chat_state', '!=', SupportConversation::CHAT_STATE_ENDED);
            });
        }
        if (isset($columns['ended_at'])) {
            $fallbackQuery->whereNull('ended_at');
        }

        return $fallbackQuery->count();
    }

    protected function hardChatCap(): int
    {
        return max(1, min(5, (int) config('support_chat.workbench.max_panels', 5)));
    }

    protected function workbenchMaxPanels(): int
    {
        return max(1, min($this->hardChatCap(), (int) config('support_chat.workbench.max_panels', 5)));
    }

    protected function clampAgentCapacity(int $capacity): int
    {
        return max(1, min($this->hardChatCap(), $capacity));
    }

    protected function defaultAgentCapacity(): int
    {
        return $this->clampAgentCapacity((int) config('support_chat.routing.default_agent_capacity', 3));
    }

    protected function resolveAgentCapacity(User $agent, ?SupportConversation $conversation = null): int
    {
        $defaultCapacity = $this->defaultAgentCapacity();
        $skillId = $conversation?->support_skill_id ? (int) $conversation->support_skill_id : null;

        if ($skillId) {
            $skillCapacity = SupportSkillMembership::query()
                ->where('user_id', $agent->id)
                ->where('support_skill_id', $skillId)
                ->where('is_active', true)
                ->value('capacity');

            return $skillCapacity !== null
                ? $this->clampAgentCapacity((int) $skillCapacity)
                : $defaultCapacity;
        }

        $memberships = SupportSkillMembership::query()
            ->where('user_id', $agent->id)
            ->where('is_active', true)
            ->get(['capacity', 'is_primary']);

        $primaryCapacity = $memberships
            ->first(fn (SupportSkillMembership $membership) => (bool) $membership->is_primary && $membership->capacity !== null)?->capacity;
        $fallbackCapacity = $memberships
            ->pluck('capacity')
            ->filter(fn ($capacity) => $capacity !== null)
            ->map(fn ($capacity) => $this->clampAgentCapacity((int) $capacity))
            ->max();

        return $this->clampAgentCapacity((int) ($primaryCapacity ?? $fallbackCapacity ?? $defaultCapacity));
    }

    protected function activeAssignedChatsCount(int $agentId, ?int $exceptConversationId = null): int
    {
        $query = SupportConversation::query()
            ->where('assigned_to', $agentId)
            ->whereNotIn('status', [
                SupportConversation::STATUS_RESOLVED,
                SupportConversation::STATUS_CLOSED,
            ]);

        if (Schema::hasColumn('support_conversations', 'chat_state')) {
            $query->where('chat_state', SupportConversation::CHAT_STATE_NEW);
        }

        if ($exceptConversationId) {
            $query->where('id', '!=', $exceptConversationId);
        }

        return (int) $query->count();
    }

    protected function assertAgentHasCapacity(User $agent, ?SupportConversation $conversation = null): void
    {
        $effectiveCapacity = $this->resolveAgentCapacity($agent, $conversation);
        $excludeConversationId = ($conversation && (int) $conversation->assigned_to === (int) $agent->id)
            ? (int) $conversation->id
            : null;
        $activeChats = $this->activeAssignedChatsCount((int) $agent->id, $excludeConversationId);

        if ($activeChats >= $effectiveCapacity) {
            throw new \InvalidArgumentException("{$agent->name} is already at the maximum of {$effectiveCapacity} active chats.");
        }
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

    protected function broadcastConversationChanged(SupportConversation $conversation, bool $broadcastToCustomer = true): void
    {
        $conversation->loadMissing('assignee:id,public_id');
        if (! (bool) config('support_chat.jobs.enabled', true)) {
            broadcast(new SupportConversationChanged($conversation, $broadcastToCustomer));

            return;
        }

        $this->dispatchSupportBroadcastJob(
            syncJob: function () use ($conversation, $broadcastToCustomer): void {
                BroadcastSupportConversationChanged::dispatchSync(
                    $conversation->id,
                    $broadcastToCustomer
                );
            },
            queuedJob: function () use ($conversation, $broadcastToCustomer): void {
                BroadcastSupportConversationChanged::dispatch(
                    $conversation->id,
                    $broadcastToCustomer
                );
            },
            logContext: [
                'type' => 'conversation_changed',
                'conversation_id' => $conversation->public_id,
            ],
        );
    }

    protected function broadcastMessageCreated(
        SupportConversation $conversation,
        SupportMessage $message,
        bool $broadcastToCustomer = true
    ): void {
        if (! (bool) config('support_chat.jobs.enabled', true)) {
            broadcast(new SupportMessageCreated($conversation, $message, $broadcastToCustomer));

            return;
        }

        $this->dispatchSupportBroadcastJob(
            syncJob: function () use ($conversation, $message, $broadcastToCustomer): void {
                BroadcastSupportMessageCreated::dispatchSync(
                    $conversation->id,
                    $message->id,
                    $broadcastToCustomer
                );
            },
            queuedJob: function () use ($conversation, $message, $broadcastToCustomer): void {
                BroadcastSupportMessageCreated::dispatch(
                    $conversation->id,
                    $message->id,
                    $broadcastToCustomer
                );
            },
            logContext: [
                'type' => 'message_created',
                'conversation_id' => $conversation->public_id,
                'message_id' => $message->public_id,
            ],
        );
    }

    /**
     * @param  callable():void  $syncJob
     * @param  callable():void  $queuedJob
     * @param  array<string, mixed>  $logContext
     */
    protected function dispatchSupportBroadcastJob(callable $syncJob, callable $queuedJob, array $logContext = []): void
    {
        $mode = (string) config('support_chat.jobs.broadcast_mode', 'sync_first');
        $isQueueFirst = strtolower($mode) === 'queue_first';

        if ($isQueueFirst) {
            try {
                $queuedJob();

                return;
            } catch (\Throwable $exception) {
                Log::warning('[SupportChat] Queue-first broadcast dispatch failed, falling back to sync.', array_merge($logContext, [
                    'mode' => 'queue_first',
                    'error' => $exception->getMessage(),
                ]));
            }

            $syncJob();

            return;
        }

        try {
            $syncJob();
        } catch (\Throwable $exception) {
            Log::warning('[SupportChat] Sync-first broadcast dispatch failed, falling back to queue.', array_merge($logContext, [
                'mode' => 'sync_first',
                'error' => $exception->getMessage(),
            ]));
            $queuedJob();
        }
    }
}
