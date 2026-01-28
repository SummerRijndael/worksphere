<?php

namespace App\Services;

use App\Enums\AuditAction;
use App\Enums\AuditCategory;
use App\Models\User;
use Illuminate\Support\Facades\Auth;
use Illuminate\Support\Facades\Session;

class ImpersonationService
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * Start impersonating a user.
     */
    public function impersonate(User $impersonator, User $target): void
    {
        if ($impersonator->id === $target->id) {
            throw new \InvalidArgumentException("You cannot impersonate yourself.");
        }

        if ($this->isImpersonating()) {
            throw new \RuntimeException("You are already impersonating a user.");
        }

        // Security Check: Prevent impersonating other admins
        if ($target->hasRole('administrator') || $target->hasPermissionTo('users.impersonate')) {
             throw new \RuntimeException("You cannot impersonate another administrator.");
        }
        
        // Store the original user ID in the session
        Session::put('impersonator_id', $impersonator->id);
        
        // Log the user in as the target
        Auth::login($target);

        // Audit Log
        $this->auditService->log(
            AuditAction::ImpersonationStarted,
            AuditCategory::Authentication,
            $target,
            $impersonator,
            null,
            null,
            ['reason' => 'Administrative investigation']
        );
    }

    /**
     * Stop impersonating.
     */
    public function stopImpersonating(): void
    {
        if (! $this->isImpersonating()) {
             throw new \RuntimeException("No active impersonation session found.");
        }

        $impersonatorId = Session::get('impersonator_id');
        $impersonator = User::find($impersonatorId);

        if (! $impersonator) {
            // Fallback safety: Logout if original user not found
            Auth::logout();
            Session::forget('impersonator_id');
            throw new \RuntimeException("Original user not found. You have been logged out.");
        }

        $target = Auth::user();

        // Audit Log (before switching back)
        if ($target) {
            $this->auditService->log(
                AuditAction::ImpersonationEnded,
                AuditCategory::Authentication,
                $target,
                $impersonator,
                null,
                null,
                ['duration' => 'ENDED']
            );
        }

        // Restore original session
        Auth::login($impersonator);
        Session::forget('impersonator_id');
    }

    /**
     * Check if currently impersonating.
     */
    public function isImpersonating(): bool
    {
        return Session::has('impersonator_id');
    }

    /**
     * Get the original user (impersonator).
     */
    public function getImpersonator(): ?User
    {
        if (! $this->isImpersonating()) {
            return null;
        }

        return User::find(Session::get('impersonator_id'));
    }
}
