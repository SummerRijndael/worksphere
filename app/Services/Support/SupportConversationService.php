<?php

namespace App\Services\Support;

use App\Contracts\SupportConversationServiceContract;
use App\Events\Support\SupportConversationChanged;
use App\Events\Support\SupportMessageCreated;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\User;
use App\Services\Support\Pipelines\SupportHandoffPipeline;
use Illuminate\Auth\Access\AuthorizationException;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Database\Eloquent\Collection;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Str;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class SupportConversationService implements SupportConversationServiceContract
{
    public function __construct(
        protected SupportHandoffPipeline $handoffPipeline,
        protected SupportChatMediaService $supportChatMediaService
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

        /** @var SupportConversation $conversation */
        $conversation = DB::transaction(function () use ($payload, $actor, $initialMessage) {
            $conversation = SupportConversation::create([
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
            ]);

            $this->createMessage(
                conversation: $conversation,
                senderType: SupportMessage::SENDER_CUSTOMER,
                body: $initialMessage,
                senderUserId: $actor?->id,
            );

            return $conversation;
        });

        $this->processAiForCustomerMessage($conversation->fresh(), $initialMessage);

        $this->broadcastConversationChanged($conversation->fresh());

        return $conversation->fresh(['requester', 'assignee', 'latestMessage.sender', 'latestMessage.media', 'messages.sender', 'messages.media']);
    }

    public function getConversationForActor(SupportConversation $conversation, ?User $actor = null, ?string $guestToken = null): SupportConversation
    {
        if ($actor) {
            if ($this->canOperateAsAgent($actor)) {
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

        $this->broadcastConversationChanged($conversation->fresh());

        return $message->fresh(['sender', 'media']);
    }

    /**
     * @param  array<string, mixed>  $payload
     */
    public function addAgentMessage(SupportConversation $conversation, User $agent, array $payload): SupportMessage
    {
        if (! $this->canOperateAsAgent($agent)) {
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
        ])->save();

        $this->broadcastConversationChanged($conversation->fresh(), ! $isPrivateNote);

        return $message->fresh(['sender', 'media']);
    }

    public function assignConversation(SupportConversation $conversation, User $agent, User $actor): SupportConversation
    {
        if (! $this->canOperateAsAgent($actor)) {
            throw new AuthorizationException('Only support agents can assign conversations.');
        }

        if (! $this->canOperateAsAgent($agent)) {
            throw new \InvalidArgumentException('Assigned user must be an eligible support agent.');
        }

        $conversation->forceFill([
            'assigned_to' => $agent->id,
            'status' => SupportConversation::STATUS_ASSIGNED,
        ])->save();

        $assignmentMessage = $this->createMessage(
            conversation: $conversation,
            senderType: SupportMessage::SENDER_SYSTEM,
            body: "{$actor->name} assigned this conversation to {$agent->name}.",
            senderUserId: $actor->id,
            metadata: ['type' => 'assignment'],
        );
        $this->broadcastMessageCreated($conversation->fresh(), $assignmentMessage->fresh('sender'));

        $this->broadcastConversationChanged($conversation->fresh());

        return $conversation->fresh(['requester', 'assignee', 'latestMessage']);
    }

    public function resolveConversation(SupportConversation $conversation, User $actor): SupportConversation
    {
        if (! $this->canOperateAsAgent($actor)) {
            throw new AuthorizationException('Only support agents can resolve conversations.');
        }

        $conversation->forceFill([
            'status' => SupportConversation::STATUS_RESOLVED,
            'resolved_at' => now(),
        ])->save();

        $resolutionMessage = $this->createMessage(
            conversation: $conversation,
            senderType: SupportMessage::SENDER_SYSTEM,
            body: "{$actor->name} marked this conversation as resolved.",
            senderUserId: $actor->id,
            metadata: ['type' => 'resolution'],
        );
        $this->broadcastMessageCreated($conversation->fresh(), $resolutionMessage->fresh('sender'));

        $this->broadcastConversationChanged($conversation->fresh());

        return $conversation->fresh(['requester', 'assignee', 'latestMessage']);
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

        return $conversation->fresh(['requester', 'assignee', 'latestMessage.sender', 'latestMessage.media', 'messages.sender', 'messages.media']);
    }

    /**
     * @param  array<string, mixed>  $filters
     */
    public function inbox(User $agent, string $scope = 'mine', array $filters = []): LengthAwarePaginator
    {
        if (! $this->canOperateAsAgent($agent)) {
            throw new AuthorizationException('Only support agents can access support inbox.');
        }

        $status = isset($filters['status']) ? trim((string) $filters['status']) : null;
        $search = isset($filters['q']) ? trim((string) $filters['q']) : null;
        $defaultPerPage = (int) config('support_chat.default_per_page', 20);
        $maxPerPage = (int) config('support_chat.max_per_page', 100);
        $perPage = isset($filters['per_page']) ? (int) $filters['per_page'] : $defaultPerPage;
        $safePerPage = max(1, min($maxPerPage, $perPage));

        $query = SupportConversation::query()
            ->with(['requester:id,public_id,name,email', 'assignee:id,public_id,name,email', 'latestMessage.sender:id,public_id,name,email', 'latestMessage.media'])
            ->orderByDesc('last_message_at')
            ->orderByDesc('updated_at');

        if ($scope === 'mine') {
            $query->where('assigned_to', $agent->id);
        } elseif ($scope === 'unassigned') {
            $query->whereNull('assigned_to')
                ->whereIn('status', [
                    SupportConversation::STATUS_OPEN,
                    SupportConversation::STATUS_BOT_ACTIVE,
                    SupportConversation::STATUS_WAITING_HUMAN,
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

        return $query->paginate($safePerPage);
    }

    public function canOperateAsAgent(User $user): bool
    {
        return $this->isSupportAgent($user);
    }

    /**
     * @return Collection<int, User>
     */
    public function eligibleAgents(): Collection
    {
        return $this->eligibleAgentsQuery()
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
        if (! $conversation->ai_enabled || ! config('support_chat.ai_enabled', true)) {
            return;
        }

        $decision = $this->handoffPipeline->handle($conversation, $incomingBody);

        $botMessage = $this->createMessage(
            conversation: $conversation,
            senderType: SupportMessage::SENDER_BOT,
            body: (string) ($decision['reply'] ?? ''),
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
            ])->save();

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
            $conversation->status = SupportConversation::STATUS_BOT_ACTIVE;
            $conversation->save();
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

    protected function isSupportAgent(User $user): bool
    {
        $roles = (array) config('support_chat.agent_roles', ['administrator', 'it_support']);
        $permissions = (array) config('support_chat.agent_permissions', ['tickets.manage']);

        if (! empty($roles) && $user->hasAnyRole($roles)) {
            return true;
        }

        foreach ($permissions as $permission) {
            try {
                if ($user->hasPermissionTo($permission)) {
                    return true;
                }
            } catch (PermissionDoesNotExist) {
                continue;
            } catch (\Throwable) {
                continue;
            }
        }

        return false;
    }

    protected function eligibleAgentsQuery(): Builder
    {
        $roles = (array) config('support_chat.agent_roles', ['administrator', 'it_support']);
        $permissions = (array) config('support_chat.agent_permissions', ['tickets.manage']);

        return User::query()
            ->where(function (Builder $query) use ($roles, $permissions) {
                $hasClause = false;

                if (! empty($roles)) {
                    $query->whereHas('roles', fn (Builder $roleQuery) => $roleQuery->whereIn('name', $roles));
                    $hasClause = true;
                }

                if (! empty($permissions)) {
                    if ($hasClause) {
                        $query->orWhereHas('permissions', fn (Builder $permissionQuery) => $permissionQuery->whereIn('name', $permissions));
                    } else {
                        $query->whereHas('permissions', fn (Builder $permissionQuery) => $permissionQuery->whereIn('name', $permissions));
                    }
                }
            });
    }

    protected function broadcastConversationChanged(SupportConversation $conversation, bool $broadcastToCustomer = true): void
    {
        $conversation->loadMissing('assignee:id,public_id');

        broadcast(new SupportConversationChanged($conversation, $broadcastToCustomer));
    }

    protected function broadcastMessageCreated(
        SupportConversation $conversation,
        SupportMessage $message,
        bool $broadcastToCustomer = true
    ): void {
        broadcast(new SupportMessageCreated($conversation, $message, $broadcastToCustomer));
    }
}
