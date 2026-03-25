<?php

namespace App\Enums;

enum DialerCallStatus: string
{
    case Queued = 'queued';
    case Initiated = 'initiated';
    case Ringing = 'ringing';
    case InProgress = 'in_progress';
    case Completed = 'completed';
    case Failed = 'failed';
    case Cancelled = 'cancelled';

    public function label(): string
    {
        return match ($this) {
            self::Queued => 'Queued',
            self::Initiated => 'Initiated',
            self::Ringing => 'Ringing',
            self::InProgress => 'Live',
            self::Completed => 'Completed',
            self::Failed => 'Failed',
            self::Cancelled => 'Cancelled',
        };
    }

    public function tone(): string
    {
        return match ($this) {
            self::Queued => 'secondary',
            self::Initiated => 'info',
            self::Ringing => 'warning',
            self::InProgress => 'success',
            self::Completed => 'secondary',
            self::Failed => 'danger',
            self::Cancelled => 'muted',
        };
    }

    public function isActive(): bool
    {
        return in_array($this, [
            self::Queued,
            self::Initiated,
            self::Ringing,
            self::InProgress,
        ], true);
    }

    public static function fromProviderStatus(?string $status): self
    {
        return match (strtolower(trim((string) $status))) {
            'queued' => self::Queued,
            'initiated' => self::Initiated,
            'ringing' => self::Ringing,
            'in-progress', 'in_progress', 'live' => self::InProgress,
            'completed' => self::Completed,
            'canceled', 'cancelled' => self::Cancelled,
            'busy', 'no-answer', 'no_answer', 'failed' => self::Failed,
            default => self::Failed,
        };
    }
}
