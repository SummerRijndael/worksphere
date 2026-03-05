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
            throw new \InvalidArgumentException('You cannot impersonate yourself.');
        }

        if ($this->isImpersonating()) {
            throw new \RuntimeException('You are already impersonating a user.');
        }

        // Security Check: Prevent impersonating other admins
        if ($target->hasRole('administrator') || $target->hasPermissionTo('users.impersonate')) {
            throw new \RuntimeException('You cannot impersonate another administrator.');
        }

        // Store the original user ID in the session
        Session::put('impersonator_id', $impersonator->id);

        \Illuminate\Support\Facades\Log::info("IMPERSONATE START - Put impersonator_id: " . Session::get('impersonator_id'));

        // Log the user in as the target
        Auth::guard('web')->login($target);

        \Illuminate\Support\Facades\Log::info("IMPERSONATE START - Auth user ID is now: " . Auth::guard('web')->id());

        // Regenerate the session to prevent session fixation and concurrent request clobbering
        Session::regenerate();
        
        \Illuminate\Support\Facades\Log::info("IMPERSONATE START - Session regenerated. New ID: " . Session::getId() . " impersonator_id: " . Session::get('impersonator_id'));

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
        \Illuminate\Support\Facades\Log::info("IMPERSONATE STOP - Session ID: " . Session::getId() . " impersonator_id: " . Session::get('impersonator_id') . " Auth ID: " . Auth::guard('web')->id());

        if (! $this->isImpersonating()) {
            \Illuminate\Support\Facades\Log::error("IMPERSONATE STOP FAILED - No impersonator_id in session!");
            throw new \RuntimeException('No active impersonation session found.');
        }

        $impersonatorId = Session::get('impersonator_id');
        $impersonator = User::find($impersonatorId);

        if (! $impersonator) {
            // Fallback safety: Logout if original user not found
            Auth::guard('web')->logout();
            Session::forget('impersonator_id');
            Session::regenerate();
            throw new \RuntimeException('Original user not found. You have been logged out.');
        }

        $target = Auth::guard('web')->user() ?? Auth::user();

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
        Auth::guard('web')->login($impersonator);
        Session::forget('impersonator_id');
        Session::regenerate();
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
