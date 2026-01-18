<?php

namespace App\Enums;

enum InvoiceStatus: string
{
    case Draft = 'draft';
    case Sent = 'sent';
    case Viewed = 'viewed';
    case Paid = 'paid';
    case Overdue = 'overdue';
    case Cancelled = 'cancelled';

    /**
     * Get the display label for the status.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Sent => 'Sent',
            self::Viewed => 'Viewed',
            self::Paid => 'Paid',
            self::Overdue => 'Overdue',
            self::Cancelled => 'Cancelled',
        };
    }

    /**
     * Get the color for UI display.
     */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'secondary',
            self::Sent => 'info',
            self::Viewed => 'warning',
            self::Paid => 'success',
            self::Overdue => 'error',
            self::Cancelled => 'muted',
        };
    }

    /**
     * Check if the invoice can be edited.
     */
    public function canEdit(): bool
    {
        return in_array($this, [self::Draft]);
    }

    /**
     * Check if the invoice can be sent.
     */
    public function canSend(): bool
    {
        return in_array($this, [self::Draft, self::Overdue]);
    }

    /**
     * Check if a payment can be recorded.
     */
    public function canRecordPayment(): bool
    {
        return in_array($this, [self::Sent, self::Viewed, self::Overdue]);
    }

    /**
     * Get allowed transitions from this status.
     *
     * @return array<InvoiceStatus>
     */
    public function allowedTransitions(): array
    {
        return match ($this) {
            self::Draft => [self::Sent, self::Cancelled],
            self::Sent => [self::Viewed, self::Paid, self::Overdue, self::Cancelled],
            self::Viewed => [self::Paid, self::Overdue, self::Cancelled],
            self::Overdue => [self::Paid, self::Sent, self::Cancelled],
            self::Paid => [],
            self::Cancelled => [],
        };
    }

    /**
     * Check if transition to new status is allowed.
     */
    public function canTransitionTo(InvoiceStatus $newStatus): bool
    {
        return in_array($newStatus, $this->allowedTransitions());
    }

    /**
     * Get all values as array for validation.
     *
     * @return array<string>
     */
    public static function values(): array
    {
        return array_column(self::cases(), 'value');
    }
}
