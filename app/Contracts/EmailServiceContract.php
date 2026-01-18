<?php

namespace App\Contracts;

use App\Models\Email;
use App\Models\EmailAccount;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface EmailServiceContract
{
    /**
     * List emails with optional filters.
     *
     * @param  array<string, mixed>  $filters  Keys: folder, search, date_from, date_to, is_read, is_starred
     */
    public function list(User $user, array $filters = [], int $perPage = 25): LengthAwarePaginator;

    /**
     * Get a single email by ID.
     */
    public function find(int $emailId): ?Email;

    /**
     * Get a single email by public ID.
     */
    public function findByPublicId(string $publicId): ?Email;

    /**
     * Send an email (dispatches job).
     *
     * @param  array<string, mixed>  $data  Keys: to, cc, bcc, subject, body, signature_id, attachments
     */
    public function send(User $user, EmailAccount $account, array $data): Email;

    /**
     * Send bulk emails (dispatches batch jobs).
     *
     * @param  array<array{email: string, name?: string}>  $recipients
     * @param  array<string, mixed>  $emailData
     */
    public function sendBulk(User $user, EmailAccount $account, array $recipients, \App\Models\Email $sourceEmail, array $emailData): void;

    /**
     * Save email as draft.
     *
     * @param  array<string, mixed>  $data
     */
    public function saveDraft(User $user, EmailAccount $account, array $data): Email;

    /**
     * Update an existing draft.
     *
     * @param  array<string, mixed>  $data
     */
    public function updateDraft(Email $email, array $data): Email;

    /**
     * Move email to a folder.
     */
    public function moveToFolder(Email $email, string $folder): Email;

    /**
     * Mark email as read.
     */
    public function markAsRead(Email $email): Email;

    /**
     * Mark email as unread.
     */
    public function markAsUnread(Email $email): Email;

    /**
     * Toggle starred status.
     */
    public function toggleStar(Email $email): Email;

    /**
     * Soft delete an email.
     */
    public function delete(Email $email): void;

    /**
     * Bulk delete emails.
     *
     * @param  array<int>  $emailIds
     */
    public function bulkDelete(array $emailIds, User $user): int;

    /**
     * Get unread count for user.
     */
    public function getUnreadCount(User $user, ?string $folder = null): int;

    /**
     * Get folder counts for user.
     *
     * @return array<string, int>
     */
    public function getFolderCounts(User $user): array;
}
