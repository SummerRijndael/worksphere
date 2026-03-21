<?php

namespace Tests\Unit;

use App\Models\User;
use App\Services\Chat\PresenceService;
use App\Services\MaintenanceService;
use Mockery;
use Tests\TestCase;

class MaintenanceServiceTest extends TestCase
{
    public function test_get_online_user_stats_returns_correct_counts()
    {
        // Arrange
        $presenceService = Mockery::mock(PresenceService::class);

        $admin = Mockery::mock(User::class);
        $admin->shouldReceive('getAttribute')->with('internalTeams')->andReturn(collect([]));

        $support = Mockery::mock(User::class);
        $support->shouldReceive('getAttribute')->with('internalTeams')->andReturn(collect(['dummy_team']));

        $user = Mockery::mock(User::class);
        $user->shouldReceive('getAttribute')->with('internalTeams')->andReturn(collect([]));

        $activeUsers = collect([$admin, $support, $user]);

        $presenceService->shouldReceive('getActiveUsers')->once()->andReturn($activeUsers);

        $service = new MaintenanceService($presenceService);

        // Act
        $stats = $service->getOnlineUserStats();

        // Assert
        $this->assertEquals(3, $stats['total']);
        $this->assertEquals(1, $stats['support_staff']);
    }

    public function test_get_online_user_stats_handles_empty_list()
    {
        // Arrange
        $presenceService = Mockery::mock(PresenceService::class);
        $presenceService->shouldReceive('getActiveUsers')->once()->andReturn(collect([]));

        $service = new MaintenanceService($presenceService);

        // Act
        $stats = $service->getOnlineUserStats();

        // Assert
        $this->assertEquals(0, $stats['total']);
        $this->assertEquals(0, $stats['support_staff']);
    }
}
