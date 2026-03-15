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
    public function send(User $user, EmailAccount $account, array $data, ?Email $draft = null): Email
    {
        // Check rate limit
        $this->checkRateLimit($user);

        // Check if we should batch
        $recipientCount = count($data['to'] ?? [])
            + count($data['cc'] ?? [])
            + count($data['bcc'] ?? []);

        $isScheduled = ! empty($data['scheduled_at']) && \Illuminate\Support\Carbon::parse($data['scheduled_at'])->isFuture();
        $targetFolder = $isScheduled ? EmailFolderType::Scheduled->value : EmailFolderType::Sent->value;

        if ($draft) {
            $email = $this->updateDraft($draft, $data);
            $email->update([
                'folder' => $targetFolder,
                'is_draft' => $isScheduled,
                'sent_at' => $isScheduled ? null : now(),
                'email_account_id' => $account->id,
                'from_email' => $account->email,
                'from_name' => $account->name ?? $user->name,
            ]);
        } else {
            // Create email record first to handle media persistence
            $email = $this->createEmailRecord($user, $account, $data, isDraft: $isScheduled, isSent: ! $isScheduled);
            if ($isScheduled) {
                $email->update(['folder' => $targetFolder]);
            }
        }

        if ($recipientCount > config('email.batch_threshold', 10)) {
            // Use bulk send for large recipient lists
            $this->sendBulk($user, $account, $data['to'], $email, $data);

            return $email;
        }

        // Dispatch send job only if not scheduled for the future
        if (! $email->scheduled_at || $email->scheduled_at->isPast()) {
            SendEmailJob::dispatch($email->id, $account->id);
        }

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
        $headers = $email->headers ?? [];
        if (isset($data['request_read_receipt'])) {
            $headers['request_read_receipt'] = $data['request_read_receipt'];
        }

        $email->update([
            'to' => $data['to'] ?? $email->to,
            'cc' => $data['cc'] ?? $email->cc,
            'bcc' => $data['bcc'] ?? $email->bcc,
            'subject' => $data['subject'] ?? $email->subject,
            'body_html' => $data['body'] ?? $email->body_html,
            'preview' => $this->generatePreview($data['body'] ?? $email->body_html),
            'headers' => $headers,
            'scheduled_at' => $data['scheduled_at'] ?? $email->scheduled_at,
            'is_important' => $data['is_important'] ?? $email->is_important,
        ]);

        // Handle attachments
        if (! empty($data['attachments'])) {
            foreach ($data['attachments'] as $file) {
                if ($file instanceof \Illuminate\Http\UploadedFile) {
                    $email->addMedia($file)->toMediaCollection('attachments');
                }
            }
        }

        // Process inline images (Base64 to CID)
        if (isset($data['body']) || isset($data['body_html'])) {
            $this->processInlineImages($email);
        }

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
     * Toggle pinned status.
     */
    public function togglePin(Email $email): Email
    {
        return $email->togglePin();
    }

    /**
     * Toggle important status.
     */
    public function toggleImportant(Email $email): Email
    {
        return $email->toggleImportant();
    }

    /**
     * Soft delete an email.
     */
    public function delete(Email $email): void
    {
        // Invalidate body cache
        app(CacheService::class)->forget("email_body:{$email->id}");

        // If already in trash, permanently delete (hard prune)
        if ($email->folder === EmailFolderType::Trash->value) {
            $email->prune(forceDelete: true);
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
            $query = Email::forUser($user->id);

            if ($folder === EmailFolderType::Scheduled) {
                // Scheduled is virtual: drafts with a future schedule
                $query->where('is_draft', true)
                    ->whereNotNull('scheduled_at')
                    ->where('scheduled_at', '>', now());
            } else {
                $query->inFolder($folder->value)->notDraft();
            }

            $counts[$folder->value] = $query->count();
        }

        // Add special counts
        $counts['unread'] = $this->getUnreadCount($user);
        $counts['starred'] = Email::forUser($user->id)->starred()->notDraft()->count();

        // Regular drafts (not scheduled for future)
        $counts['drafts'] = Email::forUser($user->id)
            ->where('is_draft', true)
            ->where(function ($q) {
                $q->whereNull('scheduled_at')
                    ->orWhere('scheduled_at', '<=', now());
            })
            ->count();

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

        $emailModel = Email::create([
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
            'headers' => [ // Initialize headers with read receipt preference
                'request_read_receipt' => $data['request_read_receipt'] ?? false,
            ],
            'sent_at' => $isDraft ? null : now(),
            'scheduled_at' => $data['scheduled_at'] ?? null,
            'is_important' => $data['is_important'] ?? false,
        ]);

        // Handle attachments
        if (! empty($data['attachments'])) {
            foreach ($data['attachments'] as $file) {
                if ($file instanceof \Illuminate\Http\UploadedFile) {
                    $emailModel->addMedia($file)->toMediaCollection('attachments');
                }
            }
        }

        // Process inline images (Base64 to CID)
        if (! empty($emailModel->body_html)) {
            $this->processInlineImages($emailModel);
        }

        return $emailModel;
    }

    /**
     * Process inline images in the email body.
     * Extracts base64 images, converts to attachments, and replaces with CID.
     */
    protected function processInlineImages(Email $email): void
    {
        $body = $email->body_html;
        if (empty($body) || ! str_contains($body, 'data:image/')) {
            return;
        }

        // Use DOMDocument to parse HTML
        $dom = new \DOMDocument;
        // Suppress errors for invalid HTML structure (common in email bodies)
        libxml_use_internal_errors(true);
        // UTF-8 hack
        $dom->loadHTML('<?xml encoding="UTF-8">'.$body, LIBXML_HTML_NOIMPLIED | LIBXML_HTML_NODEFDTD);
        libxml_clear_errors();

        $images = $dom->getElementsByTagName('img');
        $hasChanges = false;

        foreach ($images as $img) {
            $src = $img->getAttribute('src');

            // Check if it's a base64 image
            if (str_starts_with($src, 'data:image/')) {
                // Parse base64 data
                // Format: data:image/png;base64,.....
                if (preg_match('/^data:image\/(\w+);base64,/', $src, $type)) {
                    $extension = strtolower($type[1]);
                    // Fix jpg extension
                    if ($extension === 'jpeg') {
                        $extension = 'jpg';
                    }

                    $data = substr($src, strpos($src, ',') + 1);
                    $data = base64_decode($data);

                    if ($data === false) {
                        continue;
                    }

                    // Create a temporary file
                    $tmpFilePath = tempnam(sys_get_temp_dir(), 'email_inline_');
                    file_put_contents($tmpFilePath, $data);

                    // Generate a unique CID
                    $cid = Str::random(20).'@worksphere.local';
                    $filename = 'image-'.Str::random(10).'.'.$extension;

                    // Attach to the email model using MediaLibrary
                    try {
                        $media = $email->addMedia($tmpFilePath)
                            ->usingName(pathinfo($filename, PATHINFO_FILENAME))
                            ->usingFileName($filename)
                            ->withCustomProperties([
                                'content_id' => $cid,
                                'disposition' => 'inline',
                            ])
                            ->toMediaCollection('attachments');

                        // Update src to CID
                        $img->setAttribute('src', 'cid:'.$cid);
                        // Add data-cid attribute for reference/debugging
                        $img->setAttribute('data-cid', $cid);
                        $hasChanges = true;

                    } catch (\Exception $e) {
                        \Illuminate\Support\Facades\Log::error('Failed to process inline image: '.$e->getMessage(), [
                            'email_id' => $email->id,
                            'cid' => $cid,
                            'exception' => $e,
                        ]);
                    }

                    // Cleanup temp file (MediaLibrary moves it usually, but if copy is made, temp remains. addMedia from path usually copies)
                    // We should check if we need to delete. `addMedia` copies by default.
                    // unlink($tmpFilePath); // Safe to delete after add
                }
            }
        }

        if ($hasChanges) {
            // Save the modified body
            $html = $dom->saveHTML();
            // Remove the UTF-8 hack and common additions by loadHTML
            $html = str_replace('<?xml encoding="UTF-8">', '', $html);
            $email->body_html = $html;
            $email->save();
        }
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

    /**
     * Fetch the body content for an email if it was skipped during sync.
     */
    public function fetchBody(Email $email): Email
    {
        return app(\App\Services\EmailSyncService::class)->fetchBody($email);
    }

    /**
     * Send a read receipt (MDN).
     */
    public function sendReadReceipt(User $user, Email $email): void
    {
        // Must have a DNT header
        $dnt = $email->headers['Disposition-Notification-To']
            ?? $email->headers['disposition-notification-to']
            ?? null;

        if (! $dnt) {
            return;
        }

        // Dispatch job to send MDN
        \App\Jobs\SendReadReceiptJob::dispatch($email->id, $dnt);
    }
}
