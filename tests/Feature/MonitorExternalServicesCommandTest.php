<?php

namespace Tests\Feature;

use App\Models\User;
use App\Notifications\SystemNotification;
use App\Services\AuditService;
use App\Services\MaintenanceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Artisan;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Notification;
use Mockery;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class MonitorExternalServicesCommandTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        Role::firstOrCreate([
            'name' => 'administrator',
            'guard_name' => 'web',
        ]);

        Cache::flush();
    }

    public function test_it_notifies_admins_when_support_ai_is_rate_limited(): void
    {
        Notification::fake();

        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $maintenance = Mockery::mock(MaintenanceService::class);
        $maintenance->shouldReceive('getExternalServicesStatus')
            ->once()
            ->andReturn([
                'support_ai' => [
                    'name' => 'Support AI (Eden)',
                    'configured' => true,
                    'status' => 'Rate Limited',
                    'latency' => 220,
                    'message' => 'Provider returned 429 rate limit.',
                ],
            ]);

        $audit = Mockery::mock(AuditService::class);
        $audit->shouldReceive('log')->once();

        $this->app->instance(MaintenanceService::class, $maintenance);
        $this->app->instance(AuditService::class, $audit);

        $exitCode = Artisan::call('monitor:external-services');

        $this->assertSame(1, $exitCode);
        Notification::assertSentTo(
            $admin,
            SystemNotification::class,
            function (SystemNotification $notification): bool {
                return str_contains($notification->title, 'Support AI')
                    && str_contains($notification->message, 'Rate Limited');
            }
        );
    }

    public function test_it_skips_notifications_when_support_ai_is_disabled(): void
    {
        Notification::fake();

        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $maintenance = Mockery::mock(MaintenanceService::class);
        $maintenance->shouldReceive('getExternalServicesStatus')
            ->once()
            ->andReturn([
                'support_ai' => [
                    'name' => 'Support AI (Eden)',
                    'configured' => true,
                    'status' => 'Disabled',
                    'latency' => null,
                    'message' => 'Support AI is disabled in configuration.',
                ],
            ]);

        $audit = Mockery::mock(AuditService::class);
        $audit->shouldNotReceive('log');

        $this->app->instance(MaintenanceService::class, $maintenance);
        $this->app->instance(AuditService::class, $audit);

        $exitCode = Artisan::call('monitor:external-services');

        $this->assertSame(0, $exitCode);
        Notification::assertNothingSent();
    }
}

