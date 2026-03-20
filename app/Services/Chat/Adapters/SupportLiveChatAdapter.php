<?php

namespace App\Services\Chat\Adapters;

use App\Contracts\ChatChannelAdapterContract;
use App\Contracts\SupportConversationServiceContract;
use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\User;
use Illuminate\Http\UploadedFile;
use InvalidArgumentException;

class SupportLiveChatAdapter implements ChatChannelAdapterContract
{
    public function __construct(
        protected SupportConversationServiceContract $supportService
    ) {}

    public function key(): string
    {
        return 'support_live';
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    public function fetchMessages(array $context, int $limit = 50, ?string $before = null): array
    {
        $conversation = $this->requireConversation($context);
        $includePrivateNotes = (bool) ($context['include_private_notes'] ?? false);
        $safeLimit = max(1, min(200, $limit));

        $query = SupportMessage::query()
            ->where('conversation_id', $conversation->id);

        if (! $includePrivateNotes) {
            $query->where('is_private_note', false);
        }

        $beforePublicId = trim((string) ($before ?? ''));
        if ($beforePublicId !== '') {
            $beforeId = SupportMessage::query()
                ->where('conversation_id', $conversation->id)
                ->where('public_id', $beforePublicId)
                ->value('id');

            if (! $beforeId) {
                return [];
            }

            $query->where('id', '<', (int) $beforeId);
        }

        $messages = $query
            ->with(['sender:id,public_id,name,email', 'media'])
            ->orderByDesc('id')
            ->limit($safeLimit)
            ->get()
            ->reverse()
            ->values();

        return $messages
            ->map(fn (SupportMessage $message): array => $this->normalizeMessage($message))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sendMessage(array $context, array $payload): array
    {
        $conversation = $this->requireConversation($context);
        $body = trim((string) ($payload['body'] ?? $payload['message'] ?? $payload['content'] ?? ''));
        $metadata = isset($payload['metadata']) && is_array($payload['metadata']) ? $payload['metadata'] : [];
        $files = $this->normalizeUploadedFiles($payload['files'] ?? []);
        $asAgent = (bool) ($context['as_agent'] ?? false);

        if ($asAgent) {
            $actor = $this->requireActor($context);
            $message = $this->supportService->addAgentMessage($conversation, $actor, [
                'body' => $body,
                'is_private_note' => (bool) ($payload['is_private_note'] ?? false),
                'metadata' => $metadata,
                'files' => $files,
            ]);
        } else {
            $actor = $context['actor'] ?? null;
            if (! $actor instanceof User && $actor !== null) {
                throw new InvalidArgumentException('Support customer adapter actor must be null or User.');
            }

            $message = $this->supportService->addCustomerMessage(
                $conversation,
                [
                    'body' => $body,
                    'metadata' => $metadata,
                    'files' => $files,
                ],
                $actor,
                isset($context['guest_token']) ? (string) $context['guest_token'] : null
            );
        }

        return $this->normalizeMessage($message->loadMissing(['sender:id,public_id,name,email', 'media']));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function pinMessage(array $context, string|int $messageId): array
    {
        throw new InvalidArgumentException('Pinning is not supported by the support live chat adapter.');
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function unpinMessage(array $context, string|int $messageId): array
    {
        throw new InvalidArgumentException('Unpinning is not supported by the support live chat adapter.');
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function editMessage(array $context, string|int $messageId, array $payload): array
    {
        throw new InvalidArgumentException('Editing is not supported by the support live chat adapter.');
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function deleteMessage(array $context, string|int $messageId): array
    {
        throw new InvalidArgumentException('Deleting is not supported by the support live chat adapter.');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function requireConversation(array $context): SupportConversation
    {
        if (($context['conversation'] ?? null) instanceof SupportConversation) {
            return $context['conversation'];
        }

        throw new InvalidArgumentException('Support adapter requires SupportConversation in context[conversation].');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function requireActor(array $context): User
    {
        if (($context['actor'] ?? null) instanceof User) {
            return $context['actor'];
        }

        throw new InvalidArgumentException('Support adapter requires User in context[actor].');
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeMessage(SupportMessage $message): array
    {
        $sender = $message->sender;
        $attachments = method_exists($message, 'toAttachmentPayload')
            ? $message->toAttachmentPayload()
            : (is_array($message->attachments) ? $message->attachments : []);
        $avatar = $sender?->getAvatarData();
        $thumb = $sender?->getAvatarData('thumb');

        return [
            'id' => $message->public_id,
            'sender_type' => $message->sender_type,
            'is_private_note' => (bool) $message->is_private_note,
            'body' => $message->body,
            'attachments' => $attachments,
            'metadata' => is_array($message->metadata) ? $message->metadata : (object) [],
            'sender' => $sender ? [
                'id' => $sender->public_id,
                'name' => $sender->name,
                'email' => $sender->email,
                'avatar_url' => $avatar?->getUrl(),
                'avatar_thumb_url' => $thumb?->getUrl(),
                'avatar_color' => $avatar?->color,
            ] : null,
            'created_at' => $message->created_at?->toISOString(),
            'updated_at' => $message->updated_at?->toISOString(),
        ];
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
}
