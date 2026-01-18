<?php

namespace App\Enums;

enum AuditSeverity: string
{
    case Debug = 'debug';
    case Info = 'info';
    case Notice = 'notice';
    case Warning = 'warning';
    case Error = 'error';
    case Critical = 'critical';
    case Alert = 'alert';
    case Emergency = 'emergency';

    /**
     * Get the human-readable label for the severity.
     */
    public function label(): string
    {
        return match ($this) {
            self::Debug => 'Debug',
            self::Info => 'Info',
            self::Notice => 'Notice',
            self::Warning => 'Warning',
            self::Error => 'Error',
            self::Critical => 'Critical',
            self::Alert => 'Alert',
            self::Emergency => 'Emergency',
        };
    }

    /**
     * Get the color associated with the severity.
     */
    public function color(): string
    {
        return match ($this) {
            self::Debug => 'gray',
            self::Info => 'blue',
            self::Notice => 'cyan',
            self::Warning => 'yellow',
            self::Error => 'orange',
            self::Critical => 'red',
            self::Alert => 'pink',
            self::Emergency => 'purple',
        };
    }

    /**
     * Get the numeric level for comparison.
     */
    public function numericLevel(): int
    {
        return match ($this) {
            self::Debug => 100,
            self::Info => 200,
            self::Notice => 250,
            self::Warning => 300,
            self::Error => 400,
            self::Critical => 500,
            self::Alert => 550,
            self::Emergency => 600,
        };
    }

    /**
     * Check if this severity is at or above the given level.
     */
    public function isAtLeast(self $severity): bool
    {
        return $this->numericLevel() >= $severity->numericLevel();
    }

    /**
     * Check if this severity requires immediate attention.
     */
    public function requiresAttention(): bool
    {
        return $this->numericLevel() >= self::Error->numericLevel();
    }
}
