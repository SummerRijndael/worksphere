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
use App\Models\SupportSurveyInvite;
use App\Models\User;
use App\Services\Support\Access\SupportAccessAdapterResolver;
use App\Services\AuditService;
use App\Services\Support\Pipelines\SupportHandoffPipeline;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
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
        protected SupportRoutingService $supportRoutingService
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

        if ($conversation->isClosedLike()) {
            throw new \InvalidArgumentException('Cannot send agent messages to a resolved/closed conversation.');
        }

        $body = trim((string) ($payload['body'] ?? $payload['message'] ?? ''));
        $files = $this->normalizeUploadedFiles($payload['files'] ?? []);
        $isPrivateNote = (bool) ($payload['is_private_note'] ?? false);
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

        $conversation->forceFill([
            'first_response_at' => $conversation->first_response_at ?? now(),
            'assigned_to' => $conversation->assigned_to ?: $agent->id,
            'status' => $conversation->assigned_to || ! $isPrivateNote
                ? SupportConversation::STATUS_ASSIGNED
                : $conversation->status,
            'ai_handoff_required' => false,
        ])->forceFill(
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
        $this->broadcastConversationChanged($conversation->fresh(), ! $isPrivateNote);

        return $message->fresh(['sender', 'media']);
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

        $conversation->forceFill([
            'assigned_to' => $agent->id,
            'status' => SupportConversation::STATUS_ASSIGNED,
            'ai_handoff_required' => false,
            'ai_handoff_reason' => null,
        ])->forceFill(
            $this->filterExistingSupportConversationColumns([
                'chat_state' => SupportConversation::CHAT_STATE_NEW,
                'assignment_state' => SupportConversation::ASSIGNMENT_STATE_ASSIGNED,
            ])
        )->save();

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

    public function resolveConversation(SupportConversation $conversation, User $actor): SupportConversation
    {
        if (! $this->supportAccessAdapter()->canResolve($actor, $conversation)) {
            throw new AuthorizationException('Only support agents can resolve conversations.');
        }

        $conversation->forceFill([
            'status' => SupportConversation::STATUS_RESOLVED,
            'resolved_at' => now(),
        ])->forceFill(
            $this->filterExistingSupportConversationColumns([
                'resolution_marker' => SupportConversation::RESOLUTION_MARKER_RESOLVED,
            ])
        )->save();

        $this->supportRoutingService->cancelConversationQueue(
            $conversation->fresh(),
            reason: 'resolved'
        );

        $resolutionMessage = $this->createMessage(
            conversation: $conversation,
            senderType: SupportMessage::SENDER_SYSTEM,
            body: "{$actor->name} marked this conversation as resolved.",
            senderUserId: $actor->id,
            metadata: ['type' => 'resolution'],
        );
        $this->broadcastMessageCreated($conversation->fresh(), $resolutionMessage->fresh('sender'));

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
            'status' => SupportConversation::STATUS_CLOSED,
            'closed_at' => now(),
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
            'end_reason' => $endReason,
            'ai_handoff_required' => false,
        ]);
        $conversation->forceFill($conversationUpdates)->save();
        $this->supportRoutingService->cancelConversationQueue(
            $conversation->fresh(),
            reason: 'conversation_closed'
        );

        $label = $endedByType === 'agent'
            ? "{$endedByName} (Agent)"
            : ($endedByType === 'customer' ? "{$endedByName} (Customer)" : "{$endedByName} (Guest)");

        $endingMessage = $this->createMessage(
            conversation: $conversation,
            senderType: SupportMessage::SENDER_SYSTEM,
            body: "{$label} ended this support conversation.",
            senderUserId: $endedByUserId,
            metadata: [
                'type' => 'conversation_closed',
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

        return $query->paginate($safePerPage);
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
            ->select(['id', 'public_id', 'name', 'email', 'status'])
            ->orderBy('name')
            ->get();
    }

    /**
     * @return array{available: bool, available_agents: int, message: string}
     */
    public function availability(): array
    {
        $availableAgents = $this->eligibleAgents()->count();

        return [
            'available' => $availableAgents > 0,
            'available_agents' => $availableAgents,
            'message' => $availableAgents > 0
                ? 'Support agents are currently available.'
                : 'No support agent is available right now, but you can leave a message.',
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

        $conversation->forceFill([
            'last_message_at' => $message->created_at,
        ])->save();

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
            $meta = is_array($conversation->metadata) ? $conversation->metadata : [];
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
                    body: (string) $availability['message'],
                    metadata: ['type' => 'availability'],
                );
                $this->broadcastMessageCreated($conversation->fresh(), $availabilityMessage->fresh('sender'));
            }

            return;
        }

        if ($conversation->status === SupportConversation::STATUS_OPEN) {
            $conversation->forceFill([
                'status' => SupportConversation::STATUS_BOT_ACTIVE,
            ])->forceFill(
                $this->filterExistingSupportConversationColumns([
                    'chat_state' => SupportConversation::CHAT_STATE_NEW,
                    'assignment_state' => SupportConversation::ASSIGNMENT_STATE_UNASSIGNED,
                ])
            )->save();
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
