<?php

namespace App\Services;

use App\Contracts\TicketServiceContract;
use App\Enums\AuditAction;
use App\Enums\AuditCategory;
use App\Enums\TicketStatus;
use App\Events\TicketCommentAdded;
use App\Events\TicketUpdated;
use App\Models\Ticket;
use App\Models\TicketComment;
use App\Models\TicketInternalNote;
use App\Models\User;
use App\Notifications\TicketDeadlineReminder;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Facades\DB;

class TicketService implements TicketServiceContract
{
    public function __construct(
        protected AuditService $auditService,
        protected CacheService $cacheService
    ) {}

    /**
     * List tickets with optional filters.
     *
     * @param  array<string, mixed>  $filters
     */
    public function list(array $filters = [], int $perPage = 15): LengthAwarePaginator
    {
        $query = $this->getFilterQuery($filters);

        // Sorting
        $sortField = $filters['sort_by'] ?? 'created_at';
        $sortDirection = $filters['sort_direction'] ?? 'desc';
        $query->orderBy($sortField, $sortDirection);

        return $query->paginate($perPage);
    }

    /**
     * Get the query builder for filtering tickets.
     */
    public function getFilterQuery(array $filters = []): \Illuminate\Database\Eloquent\Builder
    {
        $query = Ticket::query()->with(['reporter', 'assignee', 'team']);

        // Archive filter
        if (isset($filters['archived']) && filter_var($filters['archived'], FILTER_VALIDATE_BOOLEAN)) {
            $query->archived();
        } else {
            $query->active();
        }

        // Status filter (supports comma-separated values)
        if (isset($filters['status']) && $filters['status'] !== 'all' && ! empty($filters['status'])) {
            $statuses = is_array($filters['status']) ? $filters['status'] : explode(',', $filters['status']);
            $query->whereIn('status', $statuses);
        }

        // Priority filter
        if (isset($filters['priority']) && $filters['priority'] !== 'all') {
            $query->where('priority', $filters['priority']);
        }

        // Type filter
        if (isset($filters['type']) && $filters['type'] !== 'all') {
            $query->where('type', $filters['type']);
        }

        // Assignment filter
        if (isset($filters['assigned_to'])) {
            if ($filters['assigned_to'] === 'unassigned') {
                $query->whereNull('assigned_to');
            } else {
                $query->where('assigned_to', $filters['assigned_to']);
            }
        }

        // Team filter
        if (isset($filters['team_id'])) {
            $query->where('team_id', $filters['team_id']);
        }

        // Reporter filter
        if (isset($filters['reporter_id'])) {
            $query->where('reporter_id', $filters['reporter_id']);
        }

        // User scope (for own tickets)
        if (isset($filters['for_user'])) {
            $query->forUser($filters['for_user']);
        }

        // Overdue filter
        if (isset($filters['overdue']) && $filters['overdue']) {
            $query->overdue();
        }

        // SLA breached filter
        if (isset($filters['sla_breached']) && $filters['sla_breached']) {
            $query->slaBreached();
        }

        // Search
        if (isset($filters['search']) && $filters['search']) {
            $search = $filters['search'];
            $query->where(function ($q) use ($search) {
                $q->where('title', 'like', "%{$search}%")
                    ->orWhere('description', 'like', "%{$search}%")
                    ->orWhere('public_id', 'like', "%{$search}%")
                    ->orWhere('ticket_number', 'like', "%{$search}%");
            });
        }

        // Date range
        if (isset($filters['date_from'])) {
            $query->whereDate('created_at', '>=', $filters['date_from']);
        }
        if (isset($filters['date_to'])) {
            $query->whereDate('created_at', '<=', $filters['date_to']);
        }

        return $query;
    }

    /**
     * Get workload statistics (tickets per user).
     *
     * @param  array<string, mixed>  $filters
     * @return array<int, array<string, mixed>>
     */
    public function getUserWorkload(array $filters = []): array
    {
        $query = $this->getFilterQuery($filters);

        // Default to active tickets if no status filter provided
        if (! isset($filters['status'])) {
            $query->whereIn('status', [TicketStatus::Open, TicketStatus::InProgress]);
        }

        return $query->whereNotNull('assigned_to')
            ->select('assigned_to', DB::raw('count(*) as count'))
            ->groupBy('assigned_to')
            ->with(['assignee' => function ($q) {
                $q->select('id', 'name', 'avatar');
            }])
            ->get()
            ->map(fn ($row) => [
                'user_id' => $row->assigned_to,
                'name' => $row->assignee->name ?? 'Unknown',
                'avatar_url' => $row->assignee->avatar_thumb_url ?? null,
                'count' => $row->count,
                'initials' => $row->assignee ? $row->assignee->initials : '?',
            ])
            ->values()
            ->toArray();
    }

    /**
     * Get ticket statistics.
     *
     * @return array<string, mixed>
     */
    public function getStats(array $filters = []): array
    {
        // Status counts
        $statusCounts = (clone $this->getFilterQuery($filters))
            ->select('status', DB::raw('count(*) as count'))
            ->groupBy('status')
            ->pluck('count', 'status')
            ->toArray();

        // Priority counts
        $priorityCounts = (clone $this->getFilterQuery($filters))
            ->select('priority', DB::raw('count(*) as count'))
            ->groupBy('priority')
            ->pluck('count', 'priority')
            ->toArray();

        $total = array_sum($statusCounts);
        // Map enum values
        $open = $statusCounts[TicketStatus::Open->value] ?? 0;
        $inProgress = $statusCounts[TicketStatus::InProgress->value] ?? 0;
        $resolved = $statusCounts[TicketStatus::Resolved->value] ?? 0;
        $closed = $statusCounts[TicketStatus::Closed->value] ?? 0;

        $unassigned = (clone $this->getFilterQuery($filters))
            ->whereNull('assigned_to')
            ->whereNotIn('status', [TicketStatus::Resolved, TicketStatus::Closed])
            ->count();

        $overdue = (clone $this->getFilterQuery($filters))->overdue()->count();
        $slaBreached = (clone $this->getFilterQuery($filters))->slaBreached()->count();

        return [
            'total' => $total,
            'open' => $open,
            'in_progress' => $inProgress,
            'resolved' => $resolved,
            'closed' => $closed,
            'unassigned' => $unassigned,
            'overdue' => $overdue,
            'sla_breached' => $slaBreached,
            'by_priority' => $priorityCounts,
        ];
    }

    /**
     * Create a new ticket.
     *
     * @param  array<string, mixed>  $data
     */
    public function create(array $data, User $reporter): Ticket
    {
        $ticket = DB::transaction(function () use ($data, $reporter) {
            $ticket = new Ticket;
            $ticket->fill([
                'title' => $data['title'],
                'description' => $data['description'] ?? null,
                'status' => $data['status'] ?? TicketStatus::Open,
                'priority' => $data['priority'] ?? 'medium',
                'type' => $data['type'] ?? 'task',
                'tags' => $data['tags'] ?? [],
                'assigned_to' => $data['assigned_to'] ?? null,
                'team_id' => $data['team_id'] ?? null,
                'sla_response_hours' => $data['sla_response_hours'] ?? null,
                'sla_resolution_hours' => $data['sla_resolution_hours'] ?? null,
                'due_date' => $data['due_date'] ?? null,
            ]);
            $ticket->reporter_id = $reporter->id;
            $ticket->save();

            // Auto-follow the reporter
            $ticket->followers()->attach($reporter->id);

            return $ticket;
        });

        $this->auditService->logModelChange(
            AuditAction::Created,
            $ticket
        );

        return $ticket->fresh(['reporter', 'assignee', 'team']);
    }

    /**
     * Update a ticket.
     *
     * @param  array<string, mixed>  $data
     */
    public function update(Ticket $ticket, array $data, ?string $reason = null): Ticket
    {
        // Check lock
        if ($ticket->is_locked) {
            // Allow updates if only removing parent? No, "unlink" is separate method.
            // But if user is Admin? Requirement doesn't specify.
            // Assumption: Locked means locked.
            throw new \Exception('This ticket is locked and cannot be edited.');
        }

        $oldValues = collect($ticket->getAttributes())
            ->only(['title', 'description', 'status', 'priority', 'type', 'tags', 'assigned_to', 'reporter_id', 'due_date'])
            ->toArray();

        // Check if assignment is changing
        $isAssignmentChange = isset($data['assigned_to']) && $data['assigned_to'] !== $ticket->assigned_to;

        $ticket->update($data);

        $newValues = collect($ticket->getAttributes())
            ->only(['title', 'description', 'status', 'priority', 'type', 'tags', 'assigned_to', 'reporter_id', 'due_date'])
            ->toArray();

        // Use specific action for assignment changes
        $action = $isAssignmentChange ? AuditAction::TicketAssigned : AuditAction::TicketUpdated;

        $this->auditService->log(
            $action,
            AuditCategory::DataModification,
            $ticket,
            oldValues: $oldValues,
            newValues: $newValues,
            context: [
                'reason' => $reason,
            ]
        );

        broadcast(new TicketUpdated($ticket))->toOthers();

        return $ticket->fresh(['reporter', 'assignee', 'team']);
    }

    /**
     * Delete a ticket.
     */
    public function delete(Ticket $ticket, ?string $reason = null): void
    {
        $this->auditService->log(
            AuditAction::Deleted,
            AuditCategory::DataModification,
            $ticket,
            context: ['reason' => $reason]
        );

        $ticket->delete();
    }

    /**
     * Assign a ticket to a user.
     */
    public function assign(Ticket $ticket, ?User $assignee): Ticket
    {
        $oldAssignee = $ticket->assigned_to;
        $ticket->assigned_to = $assignee?->id;

        // Record first response if this is the first assignment
        if ($assignee && ! $ticket->first_response_at) {
            $ticket->first_response_at = now();
        }

        $ticket->save();

        $this->auditService->log(
            AuditAction::Updated,
            AuditCategory::DataModification,
            $ticket,
            context: [
                'action' => 'assigned',
                'old_assignee' => $oldAssignee,
                'new_assignee' => $assignee?->id,
            ]
        );

        broadcast(new TicketUpdated($ticket))->toOthers();

        return $ticket->fresh(['reporter', 'assignee', 'team']);
    }

    /**
     * Change ticket status.
     */
    public function changeStatus(Ticket $ticket, TicketStatus $status): Ticket
    {
        if ($ticket->is_locked && $ticket->status !== $status) {
            throw new \Exception('This ticket is locked and cannot change status.');
        }

        $this->setStatus($ticket, $status);

        // Cascade to children
        if ($ticket->children()->exists()) {
            foreach ($ticket->children as $child) {
                // Force update for child
                $this->setStatus($child, $status, true);
            }
        }

        return $ticket->fresh(['reporter', 'assignee', 'team']);
    }

    /**
     * Internal set status helper.
     */
    protected function setStatus(Ticket $ticket, TicketStatus $status, bool $isSystem = false): void
    {
        if ($ticket->status === $status) {
            return;
        }

        $oldStatus = $ticket->status;
        $ticket->status = $status;

        // Set resolved/closed timestamps
        if ($status === TicketStatus::Resolved && ! $ticket->resolved_at) {
            $ticket->resolved_at = now();
        }
        if ($status === TicketStatus::Closed && ! $ticket->closed_at) {
            $ticket->closed_at = now();
        }

        $ticket->save();

        $this->auditService->log(
            AuditAction::Updated,
            AuditCategory::DataModification,
            $ticket,
            context: [
                'action' => 'status_changed',
                'old_status' => $oldStatus->value,
                'new_status' => $status->value,
                'system_update' => $isSystem,
            ]
        );

        broadcast(new TicketUpdated($ticket))->toOthers();
    }

    /**
     * Add a public comment to a ticket.
     */
    public function addComment(Ticket $ticket, User $author, string $content, array $attachments = []): TicketComment
    {
        // Sanitize HTML content to prevent XSS
        $sanitizedContent = \Mews\Purifier\Facades\Purifier::clean($content);

        $comment = $ticket->comments()->create([
            'user_id' => $author->id,
            'content' => $sanitizedContent,
        ]);

        // Record first response if this is the first comment from non-reporter
        if (! $ticket->first_response_at && $author->id !== $ticket->reporter_id) {
            $ticket->first_response_at = now();
            $ticket->save();
        }

        // Audit log
        $this->auditService->log(
            AuditAction::TicketCommentAdded,
            AuditCategory::Communication,
            $ticket,
            context: [
                'comment_id' => $comment->id,
                'excerpt' => \Illuminate\Support\Str::limit(strip_tags($content), 100),
                'has_attachments' => ! empty($attachments),
                'attachment_names' => ! empty($attachments) ? collect($attachments)->map(fn ($f) => $f->getClientOriginalName())->toArray() : [],
            ]
        );

        // Handle Attachments
        if (! empty($attachments)) {
            foreach ($attachments as $file) {
                $comment->addMedia($file)->toMediaCollection('attachments');
            }
        }

        broadcast(new TicketCommentAdded($ticket, $comment))->toOthers();

        return $comment->fresh(['author']);
    }

    /**
     * Add an internal note to a ticket.
     */
    public function addInternalNote(Ticket $ticket, User $author, string $content, array $attachments = []): TicketInternalNote
    {
        // Sanitize HTML content to prevent XSS
        $sanitizedContent = \Mews\Purifier\Facades\Purifier::clean($content);

        $note = $ticket->internalNotes()->create([
            'user_id' => $author->id,
            'content' => $sanitizedContent,
        ]);

        if (! empty($attachments)) {
            foreach ($attachments as $file) {
                $note->addMedia($file)->toMediaCollection('attachments');
            }
        }

        return $note->fresh(['author']);
    }

    /**
     * Follow a ticket.
     */
    public function follow(Ticket $ticket, User $user): void
    {
        if (! $ticket->isFollowedBy($user)) {
            $ticket->followers()->attach($user->id);
        }
    }

    /**
     * Unfollow a ticket.
     */
    public function unfollow(Ticket $ticket, User $user): void
    {
        $ticket->followers()->detach($user->id);
    }

    /**
     * Check and mark SLA breaches.
     */
    public function checkSlaBreaches(): int
    {
        $breachedCount = 0;

        // Find tickets that have breached SLA but not yet marked
        $tickets = Ticket::query()
            ->where('sla_breached', false)
            ->whereNotIn('status', [TicketStatus::Resolved, TicketStatus::Closed])
            ->where(function ($q) {
                $q->whereNotNull('sla_response_hours')
                    ->orWhereNotNull('sla_resolution_hours');
            })
            ->get();

        foreach ($tickets as $ticket) {
            if ($ticket->isResponseSlaBreached() || $ticket->isResolutionSlaBreached()) {
                $ticket->sla_breached = true;
                $ticket->save();
                $breachedCount++;
            }
        }

        return $breachedCount;
    }

    /**
     * Send deadline reminders.
     */
    public function sendDeadlineReminders(): int
    {
        $reminderCount = 0;

        // Find tickets due within 24 hours that haven't been reminded
        $tickets = Ticket::query()
            ->whereNotNull('due_date')
            ->whereNull('deadline_reminded_at')
            ->whereNotIn('status', [TicketStatus::Resolved, TicketStatus::Closed])
            ->where('due_date', '<=', now()->addHours(24))
            ->where('due_date', '>', now())
            ->with(['assignee', 'reporter', 'followers'])
            ->get();

        foreach ($tickets as $ticket) {
            // Notify assignee
            if ($ticket->assignee) {
                $ticket->assignee->notify(new TicketDeadlineReminder($ticket));
                $reminderCount++;
            }

            // Notify followers
            foreach ($ticket->followers as $follower) {
                if ($follower->id !== $ticket->assigned_to) {
                    $follower->notify(new TicketDeadlineReminder($ticket));
                    $reminderCount++;
                }
            }

            // Mark as reminded
            $ticket->deadline_reminded_at = now();
            $ticket->save();
        }

        return $reminderCount;
    }

    /**
     * Link a child ticket to a master ticket.
     */
    public function linkChild(Ticket $master, Ticket $child): void
    {
        if ($master->id === $child->id) {
            throw new \Exception('Cannot link ticket to itself.');
        }

        if ($child->parent_id === $master->id) {
            return; // Already linked
        }

        // Prevent loops (basic)
        if ($master->parent_id === $child->id) {
            throw new \Exception('Cannot link: Master is currently a child of the target ticket.');
        }

        // Prevent child having children? (Maintain 2-level hierarchy for simplicity per common patterns, or allow multi-level?)
        // Implementation Plan implies "Master" and "Child".
        // Let's not strictly block multi-level unless it causes issues, but simple loop check is good.

        $child->parent_id = $master->id;
        $child->save();

        $this->auditService->log(
            AuditAction::Updated,
            AuditCategory::DataModification,
            $child,
            context: ['action' => 'linked_to_master', 'master_id' => $master->id]
        );

        $this->auditService->log(
            AuditAction::Updated,
            AuditCategory::DataModification,
            $master,
            context: ['action' => 'child_linked', 'child_id' => $child->id]
        );

        // Sync status?
        // Requirement: "status cascade". When linked, should child adopt master status immediately?
        // "once child has been tagged ... read only".
        // Probably yes.
        if ($child->status !== $master->status) {
            $this->setStatus($child, $master->status, true);
        }
    }

    /**
     * Unlink a child ticket.
     */
    public function unlinkChild(Ticket $child): void
    {
        $oldParentId = $child->parent_id;
        if (! $oldParentId) {
            return;
        }

        $child->parent_id = null;
        $child->save();

        $this->auditService->log(
            AuditAction::Updated,
            AuditCategory::DataModification,
            $child,
            context: ['action' => 'unlinked_from_master', 'old_master_id' => $oldParentId]
        );
    }

    /**
     * Archive a ticket.
     */
    public function archive(Ticket $ticket, ?string $reason = null): void
    {
        if ($ticket->archived_at) {
            return;
        }

        if (! $ticket->status->isTerminal() && empty($reason)) {
            throw new \Exception('Reason is required to archive an open ticket.');
        }

        $ticket->archived_at = now();
        $ticket->archive_reason = $reason;
        $ticket->save();

        $this->auditService->log(
            AuditAction::Updated,
            AuditCategory::DataModification,
            $ticket,
            context: ['action' => 'archived', 'reason' => $reason]
        );
    }

    /**
     * Bulk archive tickets.
     */
    public function bulkArchive(array $ticketIds, ?string $reason = null): int
    {
        $tickets = Ticket::whereIn('id', $ticketIds)->whereNull('archived_at')->get();
        $count = 0;

        foreach ($tickets as $ticket) {
            try {
                $this->archive($ticket, $reason);
                $count++;
            } catch (\Exception $e) {
                // Continue or Stop?
                // Best effort.
            }
        }

        return $count;
    }
}
