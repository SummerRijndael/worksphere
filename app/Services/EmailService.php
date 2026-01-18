<?php

namespace App\Services;

use App\Contracts\EmailServiceContract;
use App\Enums\EmailFolderType;
use App\Jobs\SendBulkEmailJob;
use App\Jobs\SendEmailJob;
use App\Models\Email;
use App\Models\EmailAccount;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\RateLimiter;
use Illuminate\Support\Str;

class EmailService implements EmailServiceContract
{
    /**
     * List emails with optional filters.
     */
    public function list(User $user, array $filters = [], int $perPage = 25): LengthAwarePaginator
    {
        $query = Email::query()
            ->forUser($user->id)
            ->notDraft()
            ->with(['labels', 'emailAccount:id,email,name', 'media']);

        // Folder filter
        if (! empty($filters['folder'])) {
            if ($filters['folder'] === 'starred') {
                $query->starred();
            } else {
                $query->inFolder($filters['folder']);
            }
        }

        // Search filter
        if (! empty($filters['search'])) {
            $query->search($filters['search']);
        }

        // Date range filters
        if (! empty($filters['date_from'])) {
            $query->where('received_at', '>=', $filters['date_from']);
        }
        if (! empty($filters['date_to'])) {
            $query->where('received_at', '<=', $filters['date_to']);
        }

        // Read/unread filter
        if (isset($filters['is_read'])) {
            $query->where('is_read', $filters['is_read']);
        }

        // Starred filter
        if (isset($filters['is_starred']) && $filters['is_starred']) {
            $query->starred();
        }

        return $query
            ->orderByDesc('received_at')
            ->paginate($perPage);
    }

    /**
     * Get a single email by ID.
     */
    public function find(int $emailId): ?Email
    {
        return Email::with(['labels', 'emailAccount', 'media'])->find($emailId);
    }

    /**
     * Get a single email by public ID.
     */
    public function findByPublicId(string $publicId): ?Email
    {
        return Email::with(['labels', 'emailAccount', 'media'])
            ->where('public_id', $publicId)
            ->first();
    }

    /**
     * Send an email (dispatches job).
     */
    public function send(User $user, EmailAccount $account, array $data): Email
    {
        // Check rate limit
        $this->checkRateLimit($user);

        // Check if we should batch
        $recipientCount = count($data['to'] ?? [])
            + count($data['cc'] ?? [])
            + count($data['bcc'] ?? []);

        // Create email record first to handle media persistence
        $email = $this->createEmailRecord($user, $account, $data, isSent: true);

        if ($recipientCount > config('email.batch_threshold', 10)) {
            // Use bulk send for large recipient lists
            $this->sendBulk($user, $account, $data['to'], $email, $data);

            return $email;
        }

        // Dispatch send job
        SendEmailJob::dispatch($email->id, $account->id);

        return $email;
    }

    /**
     * Send bulk emails (dispatches batch jobs).
     */
    public function sendBulk(User $user, EmailAccount $account, array $recipients, Email $sourceEmail, array $emailData): void
    {
        $chunkSize = config('email.batch_chunk_size', 50);
        $chunks = array_chunk($recipients, $chunkSize);
        $delaySeconds = config('email.batch_delay_seconds', 30);

        foreach ($chunks as $index => $chunk) {
            SendBulkEmailJob::dispatch(
                $user->id,
                $account->id,
                $sourceEmail->id,
                $chunk,
                $emailData,
                $index,
                count($chunks)
            )->delay(now()->addSeconds($index * $delaySeconds));
        }
    }

    /**
     * Save email as draft.
     */
    public function saveDraft(User $user, EmailAccount $account, array $data): Email
    {
        return $this->createEmailRecord($user, $account, $data, isDraft: true);
    }

    /**
     * Update an existing draft.
     */
    public function updateDraft(Email $email, array $data): Email
    {
        $email->update([
            'to' => $data['to'] ?? $email->to,
            'cc' => $data['cc'] ?? $email->cc,
            'bcc' => $data['bcc'] ?? $email->bcc,
            'subject' => $data['subject'] ?? $email->subject,
            'body_html' => $data['body'] ?? $email->body_html,
            'preview' => $this->generatePreview($data['body'] ?? $email->body_html),
        ]);

        return $email->fresh();
    }

    /**
     * Move email to a folder.
     */
    public function moveToFolder(Email $email, string $folder): Email
    {
        return $email->moveToFolder($folder);
    }

    /**
     * Mark email as read.
     */
    public function markAsRead(Email $email): Email
    {
        return $email->markAsRead();
    }

    /**
     * Mark email as unread.
     */
    public function markAsUnread(Email $email): Email
    {
        return $email->markAsUnread();
    }

    /**
     * Toggle starred status.
     */
    public function toggleStar(Email $email): Email
    {
        return $email->toggleStar();
    }

    /**
     * Soft delete an email.
     */
    public function delete(Email $email): void
    {
        // If already in trash, permanently delete
        if ($email->folder === EmailFolderType::Trash->value) {
            $email->forceDelete();
        } else {
            // Move to trash first
            $email->moveToTrash();
        }
    }

    /**
     * Bulk delete emails.
     */
    public function bulkDelete(array $emailIds, User $user): int
    {
        $emails = Email::whereIn('id', $emailIds)
            ->forUser($user->id)
            ->get();

        $count = 0;
        foreach ($emails as $email) {
            $this->delete($email);
            $count++;
        }

        return $count;
    }

    /**
     * Get unread count for user.
     */
    public function getUnreadCount(User $user, ?string $folder = null): int
    {
        $query = Email::forUser($user->id)->unread()->notDraft();

        if ($folder) {
            $query->inFolder($folder);
        }

        return $query->count();
    }

    /**
     * Get folder counts for user.
     */
    public function getFolderCounts(User $user): array
    {
        $counts = [];

        foreach (EmailFolderType::cases() as $folder) {
            $counts[$folder->value] = Email::forUser($user->id)
                ->inFolder($folder->value)
                ->notDraft()
                ->count();
        }

        // Add unread counts
        $counts['unread'] = $this->getUnreadCount($user);
        $counts['starred'] = Email::forUser($user->id)->starred()->notDraft()->count();
        $counts['drafts'] = Email::forUser($user->id)->where('is_draft', true)->count();

        return $counts;
    }

    /**
     * Create an email record.
     */
    protected function createEmailRecord(
        User $user,
        EmailAccount $account,
        array $data,
        bool $isDraft = false,
        bool $isSent = true
    ): Email {
        $body = $data['body'] ?? '';

        return $emailModel = Email::create([
            'public_id' => (string) Str::uuid(),
            'email_account_id' => $account->id,
            'user_id' => $user->id,
            'folder' => $isDraft ? EmailFolderType::Drafts->value : EmailFolderType::Sent->value,
            'from_email' => $account->email,
            'from_name' => $account->name ?? $user->name,
            'to' => $data['to'] ?? [],
            'cc' => $data['cc'] ?? [],
            'bcc' => $data['bcc'] ?? [],
            'subject' => $data['subject'] ?? '',
            'preview' => $this->generatePreview($body),
            'body_html' => $body,
            'body_plain' => strip_tags($body),
            'is_read' => true,
            'is_draft' => $isDraft,
            'has_attachments' => ! empty($data['attachments']),
            'sent_at' => $isDraft ? null : now(),
        ]);

        // Handle attachments
        if (! empty($data['attachments'])) {
            foreach ($data['attachments'] as $file) {
                if ($file instanceof \Illuminate\Http\UploadedFile) {
                    $emailModel->addMedia($file)->toMediaCollection('attachments');
                }
            }
        }

        return $emailModel;
    }

    /**
     * Generate preview text from HTML body.
     */
    protected function generatePreview(string $body): string
    {
        $plain = strip_tags($body);
        $plain = preg_replace('/\s+/', ' ', $plain);
        $plain = trim($plain);

        return Str::limit($plain, config('email.preview_length', 200));
    }

    /**
     * Check rate limit for user.
     *
     * @throws \Illuminate\Http\Exceptions\ThrottleRequestsException
     */
    protected function checkRateLimit(User $user): void
    {
        $key = 'email-send:'.$user->id;
        $maxAttempts = config('email.rate_limit', 30);

        if (RateLimiter::tooManyAttempts($key, $maxAttempts)) {
            $seconds = RateLimiter::availableIn($key);
            throw new \Illuminate\Http\Exceptions\ThrottleRequestsException(
                "Too many emails sent. Please wait {$seconds} seconds."
            );
        }

        RateLimiter::hit($key, 60); // 1 minute window
    }
}
