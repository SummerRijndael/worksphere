<?php

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Enums\AuditCategory;
use App\Services\AuditService;
use App\Services\MaintenanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Log;

class MonitorExternalServices extends Command
{
    /**
     * The name and signature of the console command.
     *
     * @var string
     */
    protected $signature = 'monitor:external-services';

    /**
     * The console command description.
     *
     * @var string
     */
    protected $description = 'Check external services status and log failures';

    /**
     * Execute the console command.
     */
    public function handle(MaintenanceService $maintenanceService, AuditService $auditService)
    {
        $this->info('Checking external services...');

        $statuses = $maintenanceService->getExternalServicesStatus();
        $hasFailures = false;

        foreach ($statuses as $key => $service) {
            try {
                $name = $service['name'] ?? $key;
                $status = $service['status'] ?? 'Unknown';
                $configured = $service['configured'] ?? false; // Some services have this key

                // Skip if explicitly "Not Configured" or meant to be ignored
                if ($status === 'Not Configured' || ($key === 'recaptcha' && ! $configured) || ($key === 'twilio' && ! $configured)) {
                    $this->line("[$name] Not Configured - Skipped");

                    continue;
                }

                if ($status !== 'Operational') {
                    $hasFailures = true;
                    $message = $service['message'] ?? 'Unknown error';
                    $latency = $service['latency'] ?? 'N/A';

                    // 1. Log to System Log
                    Log::error("External Service Failure: [$name] Status: $status. Message: $message. Latency: $latency ms");
                    $this->error("[$name] $status - $message");

                    // 2. Log to Audit Trail
                    $auditService->log(
                        action: AuditAction::SystemError,
                        category: AuditCategory::System,
                        user: null, // System action
                        context: [
                            'service' => $name,
                            'status' => $status,
                            'message' => $message,
                            'latency' => $latency,
                        ]
                    );
                } else {
                    $this->info("[$name] Operational");
                }
            } catch (\Exception $e) {
                // Prevent one service check failure from crashing the entire monitor command
                Log::error("Monitor Command Exception for service [$key]: ".$e->getMessage());
                $this->error("[$key] Exception - ".$e->getMessage());
                $hasFailures = true;
            }
        }

        if (! $hasFailures) {
            $this->info('All services operational.');
        }

        return $hasFailures ? 1 : 0;
    }
}
