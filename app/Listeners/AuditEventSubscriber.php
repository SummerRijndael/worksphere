<?php

namespace App\Listeners;

use App\Enums\AuditAction;
use App\Events\AuditableEvent;
use App\Services\AuditService;
use Illuminate\Auth\Events\Failed;
use Illuminate\Auth\Events\Lockout;
use Illuminate\Auth\Events\Login;
use Illuminate\Auth\Events\Logout;
use Illuminate\Auth\Events\PasswordReset;
use Illuminate\Auth\Events\Verified;
use Illuminate\Contracts\Events\Dispatcher;

class AuditEventSubscriber
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * Handle user login events.
     */
    public function handleLogin(Login $event): void
    {
        $this->auditService->logAuth(
            action: AuditAction::Login,
            user: $event->user,
            metadata: ['guard' => $event->guard]
        );
    }

    /**
     * Handle user logout events.
     */
    public function handleLogout(Logout $event): void
    {
        if ($event->user) {
            $this->auditService->logAuth(
                action: AuditAction::Logout,
                user: $event->user,
                metadata: ['guard' => $event->guard]
            );
        }
    }

    /**
     * Handle failed login attempts.
     */
    public function handleLoginFailed(Failed $event): void
    {
        $this->auditService->logAuth(
            action: AuditAction::LoginFailed,
            user: $event->user,
            metadata: [
                'credentials' => [
                    'email' => $event->credentials['email'] ?? 'unknown',
                ],
                'guard' => $event->guard,
            ]
        );
    }

    /**
     * Handle lockout events (Rate Limit).
     */
    public function handleLockout(Lockout $event): void
    {
        $this->auditService->logSecurity(
            action: AuditAction::RateLimitExceeded,
            metadata: [
                'email' => $event->request->input('email') ?? $event->request->input('username'),
                'ip_address' => $event->request->ip(),
                'user_agent' => $event->request->userAgent(),
                'endpoint' => $event->request->fullUrl(),
            ]
        );
    }

    /**
     * Handle password reset events.
     */
    public function handlePasswordReset(PasswordReset $event): void
    {
        $this->auditService->logAuth(
            action: AuditAction::PasswordReset,
            user: $event->user
        );
    }

    /**
     * Handle email verification events.
     */
    public function handleEmailVerified(Verified $event): void
    {
        $this->auditService->logAuth(
            action: AuditAction::EmailVerified,
            user: $event->user
        );
    }

    /**
     * Handle custom auditable events.
     */
    public function handleAuditableEvent(AuditableEvent $event): void
    {
        $this->auditService->log(
            action: $event->action,
            category: $event->category,
            auditable: $event->auditable,
            user: $event->user ?? auth()->user(),
            oldValues: $event->oldValues,
            newValues: $event->newValues,
            context: $event->metadata
        );
    }

    /**
     * Register the listeners for the subscriber.
     *
     * @return array<string, string>
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            Login::class => 'handleLogin',
            Logout::class => 'handleLogout',
            Lockout::class => 'handleLockout',
            Failed::class => 'handleLoginFailed',
            PasswordReset::class => 'handlePasswordReset',
            Verified::class => 'handleEmailVerified',
            AuditableEvent::class => 'handleAuditableEvent',
        ];
    }
}
