<?php

namespace App\Enums;

enum TicketStatus: string
{
    case Open = 'open';
    case InProgress = 'in_progress';
    case Resolved = 'resolved';
    case Closed = 'closed';

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Open => 'Open',
            self::InProgress => 'In Progress',
            self::Resolved => 'Resolved',
            self::Closed => 'Closed',
        };
    }

    /**
     * Get color for UI.
     */
    public function color(): string
    {
        return match ($this) {
            self::Open => 'warning',
            self::InProgress => 'primary',
            self::Resolved => 'success',
            self::Closed => 'secondary',
        };
    }

    /**
     * Check if status is terminal (no further action expected).
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Resolved, self::Closed]);
    }
}
