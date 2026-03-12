<?php

namespace App\Services\Chat\Adapters;

use App\Contracts\ChatChannelAdapterContract;
use App\Jobs\ProcessMeetingMessage;
use App\Models\Meeting;
use App\Models\MeetingMessage;
use App\Models\MeetingParticipant;
use App\Services\MeetingChatMediaService;
use Illuminate\Database\Eloquent\Builder;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\DB;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Str;
use InvalidArgumentException;

class MeetingChatAdapter implements ChatChannelAdapterContract
{
    public function key(): string
    {
        return 'meeting';
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<int, array<string, mixed>>
     */
    public function fetchMessages(array $context, int $limit = 200, ?string $before = null): array
    {
        $meeting = $this->requireMeeting($context);
        $threadRootId = isset($context['thread_root_id']) ? (int) $context['thread_root_id'] : null;

        $query = MeetingMessage::query()
            ->where('meeting_id', $meeting->id)
            ->with([
                'participant:id,user_id,public_id,metadata',
                'participant.user:id,name',
                'media',
                'replyTo:id,participant_public_id,body,created_at',
                'threadRoot:id,participant_public_id,body,created_at',
                'pinnedByParticipant:id,user_id,public_id,metadata',
                'pinnedByParticipant.user:id,name',
            ])
            ->withCount('replies');

        if ($threadRootId) {
            $query->where(function (Builder $q) use ($threadRootId) {
                $q->where('id', $threadRootId)
                    ->orWhere('thread_root_message_id', $threadRootId);
            });
        }

        if ($before) {
            $beforeNumericId = ctype_digit($before) ? (int) $before : null;
            if ($beforeNumericId) {
                $query->where('id', '<', $beforeNumericId);
            }
        }

        $safeLimit = max(1, min(500, $limit));

        // Main timeline should open at latest messages (then infinite-scroll older via `before`).
        // Thread view remains chronological from oldest so conversation reads naturally.
        if (! $before && ! $threadRootId) {
            return $query
                ->orderBy('created_at', 'desc')
                ->limit($safeLimit)
                ->get()
                ->sortBy(fn (MeetingMessage $message) => [
                    $message->created_at?->getTimestamp() ?? 0,
                    (int) $message->id,
                ])
                ->values()
                ->map(fn (MeetingMessage $message) => $this->normalizeMessage($message))
                ->all();
        }

        return $query
            ->orderBy('created_at', 'asc')
            ->limit($safeLimit)
            ->get()
            ->map(fn (MeetingMessage $message) => $this->normalizeMessage($message))
            ->all();
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function sendMessage(array $context, array $payload): array
    {
        $meeting = $this->requireMeeting($context);
        $participant = $this->requireParticipant($context);

        $body = strip_tags((string) ($payload['body'] ?? $payload['content'] ?? ''));
        /** @var array<UploadedFile> $files */
        $files = isset($payload['files']) && is_array($payload['files']) ? $payload['files'] : [];

        if ($body === '' && empty($files)) {
            throw new InvalidArgumentException('Message cannot be empty.');
        }

        $tempId = isset($payload['temp_id']) ? (string) $payload['temp_id'] : null;
        $metadata = isset($payload['metadata']) && is_array($payload['metadata']) ? $payload['metadata'] : null;
        $replyToId = $this->resolveReplyId($meeting, $payload['reply_to'] ?? null);

        if (! empty($files)) {
            app(MeetingChatMediaService::class)->validateFiles($files);
        }

        $threadRootId = null;
        if ($replyToId) {
            $replyTarget = MeetingMessage::where('meeting_id', $meeting->id)
                ->find($replyToId, ['id', 'thread_root_message_id']);
            $threadRootId = $replyTarget?->thread_root_message_id ?: $replyTarget?->id;
        }

        $message = DB::transaction(function () use (
            $meeting,
            $participant,
            $body,
            $tempId,
            $metadata,
            $replyToId,
            $threadRootId,
            $files
        ) {
            $created = MeetingMessage::create([
                'meeting_id' => $meeting->id,
                'participant_public_id' => $participant->public_id,
                'body' => $body,
                'temp_id' => $tempId,
                'metadata' => $metadata,
                'reply_to_message_id' => $replyToId,
                'thread_root_message_id' => $threadRootId,
            ]);

            if (! empty($files)) {
                app(MeetingChatMediaService::class)->attachFilesToMessage($created, $files);
            }

            return $created;
        });

        try {
            // Ensure meeting chat is realtime even if queue workers are down.
            ProcessMeetingMessage::dispatchSync($message->id);
        } catch (\Throwable $e) {
            Log::channel('videocall')->warning('[MEETING-CHAT] Sync broadcast failed, falling back to queued dispatch', [
                'meeting_id' => $meeting->id,
                'message_id' => $message->id,
                'error' => $e->getMessage(),
            ]);
            ProcessMeetingMessage::dispatch($message->id);
        }

        return $this->normalizeMessage($message->fresh([
            'participant:id,user_id,public_id,metadata',
            'participant.user:id,name',
            'media',
            'replyTo:id,participant_public_id,body,created_at',
            'threadRoot:id,participant_public_id,body,created_at',
            'pinnedByParticipant:id,user_id,public_id,metadata',
            'pinnedByParticipant.user:id,name',
        ])->loadCount('replies'));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function pinMessage(array $context, string|int $messageId): array
    {
        $meeting = $this->requireMeeting($context);
        $participant = $this->requireParticipant($context);
        $message = $this->resolveMessage($meeting, $messageId);

        if (! $message) {
            throw new InvalidArgumentException('Message not found in this meeting.');
        }

        $message->forceFill([
            'is_pinned' => true,
            'pinned_at' => now(),
            'pinned_by_participant_public_id' => $participant->public_id,
        ])->save();

        return $this->normalizeMessage($message->fresh([
            'participant:id,user_id,public_id,metadata',
            'participant.user:id,name',
            'media',
            'replyTo:id,participant_public_id,body,created_at',
            'threadRoot:id,participant_public_id,body,created_at',
            'pinnedByParticipant:id,user_id,public_id,metadata',
            'pinnedByParticipant.user:id,name',
        ])->loadCount('replies'));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function unpinMessage(array $context, string|int $messageId): array
    {
        $meeting = $this->requireMeeting($context);
        $message = $this->resolveMessage($meeting, $messageId);

        if (! $message) {
            throw new InvalidArgumentException('Message not found in this meeting.');
        }

        $message->forceFill([
            'is_pinned' => false,
            'pinned_at' => null,
            'pinned_by_participant_public_id' => null,
        ])->save();

        return $this->normalizeMessage($this->loadMessageForPayload($message));
    }

    /**
     * @param  array<string, mixed>  $context
     * @param  array<string, mixed>  $payload
     * @return array<string, mixed>
     */
    public function editMessage(array $context, string|int $messageId, array $payload): array
    {
        $meeting = $this->requireMeeting($context);
        $participant = $this->requireParticipant($context);
        $message = $this->resolveMessage($meeting, $messageId);

        if (! $message) {
            throw new InvalidArgumentException('Message not found in this meeting.');
        }

        if (strtolower((string) $message->participant_public_id) !== strtolower((string) $participant->public_id)) {
            throw new InvalidArgumentException('Only the original sender can edit this message.');
        }

        $body = trim(strip_tags((string) ($payload['body'] ?? '')));
        if ($body === '') {
            throw new InvalidArgumentException('Message body cannot be empty.');
        }

        $metadata = is_array($message->metadata) ? $message->metadata : [];
        if (($metadata['is_deleted'] ?? false) === true) {
            throw new InvalidArgumentException('Deleted messages cannot be edited.');
        }

        $metadata['is_edited'] = true;
        $metadata['edited_at'] = now()->toIso8601String();
        $metadata['edited_by_participant_public_id'] = $participant->public_id;

        $message->forceFill([
            'body' => $body,
            'metadata' => $metadata,
        ])->save();

        return $this->normalizeMessage($this->loadMessageForPayload($message));
    }

    /**
     * @param  array<string, mixed>  $context
     * @return array<string, mixed>
     */
    public function deleteMessage(array $context, string|int $messageId): array
    {
        $meeting = $this->requireMeeting($context);
        $participant = $this->requireParticipant($context);
        $message = $this->resolveMessage($meeting, $messageId);

        if (! $message) {
            throw new InvalidArgumentException('Message not found in this meeting.');
        }

        $isOwner = strtolower((string) $message->participant_public_id) === strtolower((string) $participant->public_id);
        $isModerator = in_array(strtolower((string) $participant->role), ['host', 'co-host'], true);
        if (! $isOwner && ! $isModerator) {
            throw new InvalidArgumentException('Only the sender, host, or co-host can delete this message.');
        }

        $metadata = is_array($message->metadata) ? $message->metadata : [];
        if (($metadata['is_deleted'] ?? false) !== true) {
            $metadata['is_deleted'] = true;
            $metadata['deleted_at'] = now()->toIso8601String();
            $metadata['deleted_by_participant_public_id'] = $participant->public_id;
            unset($metadata['is_edited'], $metadata['edited_at'], $metadata['edited_by_participant_public_id']);

            $message->forceFill([
                'body' => '',
                'metadata' => $metadata,
                'is_pinned' => false,
                'pinned_at' => null,
                'pinned_by_participant_public_id' => null,
            ])->save();

            $message->clearMediaCollection(MeetingMessage::MEDIA_COLLECTION);
        }

        return $this->normalizeMessage($this->loadMessageForPayload($message));
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function requireMeeting(array $context): Meeting
    {
        if (($context['meeting'] ?? null) instanceof Meeting) {
            return $context['meeting'];
        }

        throw new InvalidArgumentException('Meeting adapter requires a Meeting model in context[meeting].');
    }

    /**
     * @param  array<string, mixed>  $context
     */
    protected function requireParticipant(array $context): MeetingParticipant
    {
        if (($context['participant'] ?? null) instanceof MeetingParticipant) {
            return $context['participant'];
        }

        throw new InvalidArgumentException('Meeting adapter requires a MeetingParticipant model in context[participant].');
    }

    protected function resolveReplyId(Meeting $meeting, mixed $replyTo): ?int
    {
        if ($replyTo === null || $replyTo === '') {
            return null;
        }

        if (is_numeric($replyTo)) {
            $id = (int) $replyTo;
            return MeetingMessage::where('meeting_id', $meeting->id)->whereKey($id)->exists() ? $id : null;
        }

        if (is_string($replyTo) && Str::length($replyTo) === 26) {
            $message = MeetingMessage::where('meeting_id', $meeting->id)
                ->where('public_id', $replyTo)
                ->first(['id']);
            return $message?->id;
        }

        return null;
    }

    protected function resolveMessage(Meeting $meeting, string|int $messageId): ?MeetingMessage
    {
        $query = MeetingMessage::where('meeting_id', $meeting->id);

        if (is_int($messageId) || (is_string($messageId) && ctype_digit($messageId))) {
            return (clone $query)->whereKey((int) $messageId)->first();
        }

        if (is_string($messageId) && Str::length($messageId) === 26) {
            return (clone $query)->whereRaw('LOWER(public_id) = ?', [strtolower($messageId)])->first();
        }

        return null;
    }

    /**
     * @return array<string, mixed>
     */
    protected function normalizeMessage(MeetingMessage $message): array
    {
        $metadata = is_array($message->metadata) ? $message->metadata : [];
        $editedAt = isset($metadata['edited_at']) && is_string($metadata['edited_at'])
            ? $metadata['edited_at']
            : null;
        $deletedAt = isset($metadata['deleted_at']) && is_string($metadata['deleted_at'])
            ? $metadata['deleted_at']
            : null;

        return [
            'id' => $message->id,
            'public_id' => $message->public_id,
            'participant_public_id' => $message->participant_public_id,
            'participant_name' => $message->participant?->display_name,
            'body' => $message->body,
            'temp_id' => $message->temp_id,
            'metadata' => $message->metadata,
            'attachments' => $message->toAttachmentPayload(),
            'reply_to_id' => $message->reply_to_message_id,
            'thread_root_id' => $message->thread_root_message_id,
            'reply_to' => $message->replyTo ? [
                'id' => $message->replyTo->id,
                'participant_public_id' => $message->replyTo->participant_public_id,
                'body' => $message->replyTo->body,
                'created_at' => $message->replyTo->created_at?->toIso8601String(),
            ] : null,
            'thread_root' => $message->threadRoot ? [
                'id' => $message->threadRoot->id,
                'participant_public_id' => $message->threadRoot->participant_public_id,
                'body' => $message->threadRoot->body,
                'created_at' => $message->threadRoot->created_at?->toIso8601String(),
            ] : null,
            'replies_count' => (int) ($message->replies_count ?? 0),
            'is_thread_reply' => $message->thread_root_message_id !== null,
            'is_pinned' => (bool) $message->is_pinned,
            'pinned_at' => $message->pinned_at?->toIso8601String(),
            'pinned_by_participant_public_id' => $message->pinned_by_participant_public_id,
            'pinned_by_name' => $message->pinnedByParticipant?->display_name,
            'is_edited' => ($metadata['is_edited'] ?? false) === true,
            'edited_at' => $editedAt,
            'is_deleted' => ($metadata['is_deleted'] ?? false) === true,
            'deleted_at' => $deletedAt,
            'created_at' => $message->created_at?->toIso8601String(),
        ];
    }

    protected function loadMessageForPayload(MeetingMessage $message): MeetingMessage
    {
        return $message->fresh([
            'participant:id,user_id,public_id,metadata',
            'participant.user:id,name',
            'media',
            'replyTo:id,participant_public_id,body,created_at',
            'threadRoot:id,participant_public_id,body,created_at',
            'pinnedByParticipant:id,user_id,public_id,metadata',
            'pinnedByParticipant.user:id,name',
        ])->loadCount('replies');
    }
}
