<?php

namespace App\Enums;

enum EmailSyncStatus: string
{
    case Pending = 'pending';
    case Seeding = 'seeding';
    case Syncing = 'syncing';
    case Completed = 'completed';
    case Failed = 'failed';

    /**
     * Check if sync needs to be started or resumed.
     */
    public function needsSync(): bool
    {
        return match ($this) {
            self::Pending, self::Seeding, self::Syncing => true,
            self::Completed, self::Failed => false,
        };
    }

    /**
     * Check if this is a terminal state.
     */
    public function isTerminal(): bool
    {
        return match ($this) {
            self::Completed, self::Failed => true,
            default => false,
        };
    }

    /**
     * Get display label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Pending => 'Pending',
            self::Seeding => 'Initial Sync',
            self::Syncing => 'Syncing',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
        };
    }

    /**
     * Get badge color class.
     */
    public function color(): string
    {
        return match ($this) {
            self::Pending => 'bg-gray-500',
            self::Seeding => 'bg-blue-500',
            self::Syncing => 'bg-yellow-500',
            self::Completed => 'bg-green-500',
            self::Failed => 'bg-red-500',
        };
    }
}
