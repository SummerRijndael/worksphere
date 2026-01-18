<?php

namespace App\Contracts;

use App\Enums\TicketStatus;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketInternalNote;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;

interface TicketServiceContract
{
    /**
     * List tickets with optional filters.
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator;

    /**
     * Get ticket statistics.
     *
     * @param  array<string, mixed>  $filters
     * @return array<string, mixed>
     */
    public function getStats(array $filters = []): array;

    /**
     * Create a new ticket.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $reporter): Ticket;

    /**
     * Update a ticket.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Ticket $ticket, array $data, ?string $reason = null): Ticket;

    /**
     * Delete a ticket.
     */
    public function delete(Ticket $ticket, ?string $reason = null): void;

    /**
     * Assign a ticket to a user.
     */
    public function assign(Ticket $ticket, ?User $assignee): Ticket;

    /**
     * Change ticket status.
     */
    public function changeStatus(Ticket $ticket, TicketStatus $status): Ticket;

    /**
     * Add a public comment to a ticket.
     */
    public function addComment(Ticket $ticket, User $author, string $content, array $attachments = []): TicketComment;

    /**
     * Add an internal note to a ticket.
     */
    public function addInternalNote(Ticket $ticket, User $author, string $content, array $attachments = []): TicketInternalNote;

    /**
     * Follow a ticket.
     */
    public function follow(Ticket $ticket, User $user): void;

    /**
     * Unfollow a ticket.
     */
    public function unfollow(Ticket $ticket, User $user): void;

    /**
     * Check and mark SLA breaches. Returns count of newly breached tickets.
     */
    public function checkSlaBreaches(): int;

    /**
     * Send deadline reminders. Returns count of reminders sent.
     */
    public function sendDeadlineReminders(): int;

    /**
     * Link a child ticket to a master ticket.
     */
    public function linkChild(Ticket $master, Ticket $child): void;

    /**
     * Unlink a child ticket.
     */
    public function unlinkChild(Ticket $child): void;

    /**
     * Archive a ticket.
     */
    public function archive(Ticket $ticket, ?string $reason = null): void;

    /**
     * Bulk archive tickets.
     *
     * @param  array<int>  $ticketIds
     */
    public function bulkArchive(array $ticketIds, ?string $reason = null): int;

    /**
     * Get workload statistics (tickets per user).
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function getUserWorkload(array $filters = []): array;

    /**
     * Get the query builder for filtering tickets.
     * Use this for exports or other bulk operations.
     *
     * @param  array<string, mixed>  $filters
     */
    public function getFilterQuery(array $filters = []): \Illuminate\Database\Eloquent\Builder;
}
