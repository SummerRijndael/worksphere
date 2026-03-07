<?php

namespace App\Console\Commands;

use App\Enums\AuditAction;
use App\Enums\AuditCategory;
use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\AuditService;
use App\Services\MaintenanceService;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Log;
use Illuminate\Support\Facades\Notification;

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
                    $this->clearServiceFailureState($key);

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

                    $this->notifyAdminsOfFailure($key, $name, $status, (string) $message, (string) $latency);
                } else {
                    $this->info("[$name] Operational");
                    $this->notifyAdminsOfRecoveryIfNeeded($key, $name);
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

    protected function notifyAdminsOfFailure(string $serviceKey, string $serviceName, string $status, string $message, string $latency): void
    {
        $cooldownMinutes = (int) config('server-monitor.notifications.throttle_failing_notifications_for_minutes', 60);
        $notificationKey = "ext_service_alert:{$serviceKey}:{$status}";
        $stateKey = "ext_service_state:{$serviceKey}";

        // Throttle duplicate alerts with same status.
        if (! Cache::add($notificationKey, now()->timestamp, now()->addMinutes($cooldownMinutes))) {
            Cache::put($stateKey, $status, now()->addDays(7));

            return;
        }

        $admins = User::role('administrator')->get();
        if ($admins->isNotEmpty()) {
            Notification::send($admins, new SystemNotification(
                'system',
                "External Service Alert: {$serviceName}",
                "{$serviceName} is {$status}. {$message}",
                '/system/maintenance',
                'Open Maintenance',
                [
                    'service_key' => $serviceKey,
                    'service_name' => $serviceName,
                    'status' => $status,
                    'message' => $message,
                    'latency' => $latency,
                    'checked_at' => now()->toIso8601String(),
                ]
            ));
        }

        Cache::put($stateKey, $status, now()->addDays(7));
    }

    protected function notifyAdminsOfRecoveryIfNeeded(string $serviceKey, string $serviceName): void
    {
        $stateKey = "ext_service_state:{$serviceKey}";
        $previousStatus = Cache::get($stateKey);

        if (! $previousStatus || $previousStatus === 'Operational') {
            return;
        }

        $admins = User::role('administrator')->get();
        if ($admins->isNotEmpty()) {
            Notification::send($admins, new SystemNotification(
                'system',
                "External Service Recovered: {$serviceName}",
                "{$serviceName} is now Operational (previously {$previousStatus}).",
                '/system/maintenance',
                'Open Maintenance',
                [
                    'service_key' => $serviceKey,
                    'service_name' => $serviceName,
                    'status' => 'Operational',
                    'previous_status' => $previousStatus,
                    'checked_at' => now()->toIso8601String(),
                ]
            ));
        }

        Cache::forget($stateKey);
    }

    protected function clearServiceFailureState(string $serviceKey): void
    {
        $stateKey = "ext_service_state:{$serviceKey}";
        Cache::forget($stateKey);
    }
}
