<?php

namespace App\Enums;

enum TicketType: string
{
    case Bug = 'bug';
    case Feature = 'feature';
    case Task = 'task';
    case Question = 'question';
    case Improvement = 'improvement';
    case Incident = 'incident';
    case Accounting = 'accounting';

    /**
     * Get human-readable label.
     */
    public function label(): string
    {
        return match ($this) {
            self::Bug => 'Bug',
            self::Feature => 'Feature Request',
            self::Task => 'Task',
            self::Question => 'Question',
            self::Improvement => 'Improvement',
            self::Incident => 'Incident',
            self::Accounting => 'Accounting',
        };
    }

    /**
     * Get ticket number prefix.
     */
    public function prefix(): string
    {
        return match ($this) {
            self::Bug, self::Incident => 'INC',
            self::Feature, self::Task, self::Improvement => 'IT',
            self::Question => 'INQ',
            self::Accounting => 'ACN',
        };
    }

    /**
     * Get icon name for UI.
     */
    public function icon(): string
    {
        return match ($this) {
            self::Bug => 'bug',
            self::Feature => 'sparkles',
            self::Task => 'check-square',
            self::Question => 'help-circle',
            self::Improvement => 'trending-up',
            self::Incident => 'alert-triangle',
            self::Accounting => 'banknote',
        };
    }

    /**
     * Get color for UI.
     */
    public function color(): string
    {
        return match ($this) {
            self::Bug, self::Incident => 'error',
            self::Feature, self::Improvement => 'primary',
            self::Task => 'secondary',
            self::Question => 'warning',
            self::Accounting => 'info',
        };
    }
}
