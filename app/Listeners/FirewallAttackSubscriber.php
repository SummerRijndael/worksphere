<?php

namespace App\Listeners;

use Akaunting\Firewall\Events\AttackDetected;
use App\Enums\AuditAction;
use App\Enums\AuditCategory;
use App\Services\AuditService;
use Illuminate\Contracts\Events\Dispatcher;

class FirewallAttackSubscriber
{
    public function __construct(
        protected AuditService $auditService
    ) {}

    /**
     * Handle firewall attack detection events.
     */
    public function handleAttackDetected(AttackDetected $event): void
    {
        $log = $event->log;

        // Map firewall middleware to a semi-useful description
        $reason = match($log->middleware) {
            'sqli' => 'SQL Injection attempt detected',
            'xss'  => 'Cross-site Scripting (XSS) attempt detected',
            'lfi'  => 'Local File Inclusion (LFI) attempt detected',
            'rfi'  => 'Remote File Inclusion (RFI) attempt detected',
            'php'  => 'Malicious PHP code injection detected',
            'bot'  => 'Blocked bot/crawler activity detected',
            default => 'Suspicious request blocked by ' . $log->middleware,
        };

        $this->auditService->log(
            action: AuditAction::LinkBlocked, // Closest matching action for a firewall block
            category: AuditCategory::Security,
            context: [
                'firewall_log_id' => $log->id,
                'middleware'      => $log->middleware,
                'reason'          => $reason,
                'url'             => $log->url,
                'ip_address'      => $log->ip,
                'user_agent'      => $log->user_agent,
                'level'           => $log->level,
                'request_data'    => $log->request,
            ]
        );
    }

    /**
     * Register the listeners for the subscriber.
     */
    public function subscribe(Dispatcher $events): array
    {
        return [
            AttackDetected::class => 'handleAttackDetected',
        ];
    }
}
