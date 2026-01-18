<?php

namespace App\Enums;

enum TaskStatus: string
{
    case Draft = 'draft';
    case Open = 'open';
    case InProgress = 'in_progress';
    case Submitted = 'submitted';
    case InQa = 'in_qa';
    case Approved = 'approved';
    case Rejected = 'rejected';
    case SentToClient = 'sent_to_client';
    case ClientApproved = 'client_approved';
    case ClientRejected = 'client_rejected';
    case Completed = 'completed';
    case Archived = 'archived';

    /**
     * Get the human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Open => 'Open',
            self::InProgress => 'In Progress',
            self::Submitted => 'Submitted',
            self::InQa => 'In QA Review',
            self::Approved => 'Approved',
            self::Rejected => 'Rejected',
            self::SentToClient => 'Sent to Client',
            self::ClientApproved => 'Client Approved',
            self::ClientRejected => 'Client Rejected',
            self::Completed => 'Completed',
            self::Archived => 'Archived',
        };
    }

    /**
     * Get the color for UI display.
     */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'secondary',
            self::Open => 'info',
            self::InProgress => 'primary',
            self::Submitted => 'warning',
            self::InQa => 'warning',
            self::Approved => 'success',
            self::Rejected => 'error',
            self::SentToClient => 'info',
            self::ClientApproved => 'success',
            self::ClientRejected => 'error',
            self::Completed => 'success',
            self::Archived => 'secondary',
        };
    }

    /**
     * Get the icon name.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Draft => 'file-text',
            self::Open => 'circle',
            self::InProgress => 'play-circle',
            self::Submitted => 'upload',
            self::InQa => 'search',
            self::Approved => 'check-circle',
            self::Rejected => 'x-circle',
            self::SentToClient => 'send',
            self::ClientApproved => 'check-circle-2',
            self::ClientRejected => 'x-octagon',
            self::Completed => 'check-square',
            self::Archived => 'archive',
        };
    }

    /**
     * Check if this is a terminal status.
     */
    public function isTerminal(): bool
    {
        return in_array($this, [
            self::Completed,
            self::Archived,
        ]);
    }

    /**
     * Check if this is an active/working status.
     */
    public function isActive(): bool
    {
        return in_array($this, [
            self::Open,
            self::InProgress,
            self::Submitted,
            self::InQa,
        ]);
    }

    /**
     * Check if this requires client action.
     */
    public function awaitingClient(): bool
    {
        return $this === self::SentToClient;
    }

    /**
     * Check if this requires internal action.
     */
    public function requiresInternalAction(): bool
    {
        return in_array($this, [
            self::Open,
            self::InProgress,
            self::Rejected,
            self::ClientRejected,
        ]);
    }

    /**
     * Check if this is a rejected status.
     */
    public function isRejected(): bool
    {
        return in_array($this, [
            self::Rejected,
            self::ClientRejected,
        ]);
    }

    /**
     * Get allowed next statuses for workflow.
     *
     * @return array<TaskStatus>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Open, self::Archived],
            self::Open => [self::InProgress, self::Archived],
            self::InProgress => [self::Submitted, self::Open],
            self::Submitted => [self::InQa, self::InProgress],
            self::InQa => [self::Approved, self::Rejected],
            self::Approved => [self::SentToClient, self::Completed],
            self::Rejected => [self::InProgress],
            self::SentToClient => [self::ClientApproved, self::ClientRejected],
            self::ClientApproved => [self::Completed],
            self::ClientRejected => [self::InProgress],
            self::Completed => [self::Archived],
            self::Archived => [],
        };
    }

    /**
     * Check if transition to given status is allowed.
     */
    public function canTransitionTo(TaskStatus $status): bool
    {
        return in_array($status, $this->allowedTransitions());
    }

    /**
     * Get the workflow step number.
     */
    public function stepNumber(): int
    {
        return match ($this) {
            self::Draft => 0,
            self::Open => 1,
            self::InProgress => 2,
            self::Submitted => 3,
            self::InQa => 4,
            self::Approved => 5,
            self::Rejected => 4,
            self::SentToClient => 6,
            self::ClientApproved => 7,
            self::ClientRejected => 6,
            self::Completed => 8,
            self::Archived => 9,
        };
    }
}
