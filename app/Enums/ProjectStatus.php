<?php

namespace App\Enums;

enum ProjectStatus: string
{
    case Draft = 'draft';
    case Active = 'active';
    case OnHold = 'on_hold';
    case Completed = 'completed';
    case Archived = 'archived';

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Draft => 'Draft',
            self::Active => 'Active',
            self::OnHold => 'On Hold',
            self::Completed => 'Completed',
            self::Archived => 'Archived',
        };
    }

    /**
     * Get color for UI.
     */
    public function color(): string
    {
        return match ($this) {
            self::Draft => 'secondary',
            self::Active => 'primary',
            self::OnHold => 'warning',
            self::Completed => 'success',
            self::Archived => 'secondary',
        };
    }

    /**
     * Check if status is terminal (no further action expected).
     */
    public function isTerminal(): bool
    {
        return in_array($this, [self::Completed, self::Archived]);
    }

    /**
     * Check if status is active (work in progress).
     */
    public function isActive(): bool
    {
        return $this === self::Active;
    }
}
