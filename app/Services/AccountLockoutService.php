<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Models\User;
use App\Notifications\AccountLockedNotification;
use Carbon\Carbon;

class AccountLockoutService
{
    /**
     * Decay period for strikes (in days).
     * If no incidents for X days, strikes reset.
     */
    protected const STRIKE_DECAY_DAYS = 7;

    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * Handle a lockout event for a user.
     */
    public function handleLockout(User $user): void
    {
        // 1. Check if user is already currently suspended
        if ($user->suspended_until?->isFuture()) {
            // Already serving a penalty. Do not escalate further until this expires.
            // (Prevents bots from banning a user instantly by spamming 100 times)
            return;
        }

        // 2. Retrieve current security stats
        $securityPrefs = $user->getPreference('security', []);
        $strikes = $securityPrefs['lockout_strikes'] ?? 0;
        $lastLockout = $securityPrefs['last_lockout_at'] ?? null;

        // 3. Check for decay (reset strikes if last incident was long ago)
        if ($lastLockout) {
            $lastLockoutDate = Carbon::parse($lastLockout);
            if ($lastLockoutDate->diffInDays(now()) >= self::STRIKE_DECAY_DAYS) {
                $strikes = 0;
            }
        }

        // 4. Increment strikes
        $strikes++;

        // 5. Determine Penalty
        $this->applyPenalty($user, $strikes);

        // 6. Update Security Preferences
        $user->setPreference('security', [
            'lockout_strikes' => $strikes,
            'last_lockout_at' => now()->toIso8601String(),
        ]);
        $user->save();
    }

    /**
     * Apply penalty based on strike count.
     */
    protected function applyPenalty(User $user, int $strikes): void
    {
        $duration = 0;
        $isPermanent = false;
        $reason = 'System Lockout';
        $status = 'suspended';

        switch ($strikes) {
            case 1:
                $duration = 1; // 1 hour
                $user->suspended_until = now()->addHour();
                $reason = 'System Lockout (1 hour)';
                break;
            case 2:
                $duration = 8; // 8 hours
                $user->suspended_until = now()->addHours(8);
                $reason = 'System Lockout (8 hours)';
                break;
            case 3:
                $duration = 24; // 24 hours
                $user->suspended_until = now()->addHours(24);
                $reason = 'System Lockout (24 hours). Password Reset Required.';
                break;
            default:
                // 4 or more -> Ban
                $isPermanent = true;
                $user->suspended_until = null; // Indefinite
                $status = 'banned';
                $reason = 'Use of account suspended due to suspicious activity (Permanent Ban).';
                break;
        }

        // Update User
        $user->status = $status;
        $user->status_reason = $reason;

        // Log to Audit
        if ($isPermanent) {
            $this->auditService->logSecurity(
                action: AuditAction::AccountBanned,
                user: $user,
                metadata: ['strikes' => $strikes, 'reason' => $reason]
            );
        } else {
            $this->auditService->logSecurity(
                action: AuditAction::AccountSuspended,
                user: $user,
                metadata: ['strikes' => $strikes, 'duration_hours' => $duration, 'until' => $user->suspended_until]
            );
        }

        // Notify User
        $durationText = $duration.' '.($duration === 1 ? 'hour' : 'hours');
        $user->notify(new AccountLockedNotification($durationText, $isPermanent, $strikes));
    }

    /**
     * Reset lockout strikes and lift suspension.
     */
    public function resetStrikes(User $user): void
    {
        // Reset Preferences
        $user->setPreference('security', [
            'lockout_strikes' => 0,
            'last_lockout_at' => null,
        ]);

        // Lift Suspension if active
        if (in_array($user->status, ['suspended', 'banned']) && str_starts_with($user->status_reason ?? '', 'System Lockout')) {
            $user->status = 'active';
            $user->status_reason = null;
            $user->suspended_until = null;
        } else {
            // Just plain reset strikes, ensure suspension is cleared if it was a lockout
            if ($user->suspended_until) {
                $user->suspended_until = null;
            }
        }

        $user->save();

        // Audit Log
        $this->auditService->logSecurity(
            action: AuditAction::Updated, // Or customized action?
            user: $user,
            metadata: ['reason' => 'Lockout strikes reset due to password change/reset']
        );
    }
}
