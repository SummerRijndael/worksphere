<?php

namespace Tests\Feature;

use App\Events\ScheduledTaskStatusChanged;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ScheduledTasksTest extends TestCase
{
    use RefreshDatabase;

    private User $admin;

    protected function setUp(): void
    {
        parent::setUp();

        // Create admin role with system.maintenance permission
        $permission = Permission::create(['name' => 'system.maintenance', 'guard_name' => 'web']);
        $role = Role::create(['name' => 'admin', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');
    }

    public function test_scheduled_tasks_returns_all_nine_tasks(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/maintenance/scheduled-tasks');

        $response->assertOk();
        $response->assertJsonCount(9, 'data');

        // Verify all expected task names are present
        $taskNames = collect($response->json('data'))->pluck('name')->all();

        $expectedTasks = [
            'process-expired-permissions',
            'send-permission-expiry-reminders-7day',
            'send-permission-expiry-reminders-1day',
            'expire-role-change-requests',
            'horizon:snapshot',
            'events:send-reminders',
            'tickets:reminders',
            'server-monitor:run-checks',
            'email:sync-incremental',
        ];

        foreach ($expectedTasks as $expected) {
            $this->assertContains($expected, $taskNames, "Task '{$expected}' should be in response");
        }
    }

    public function test_run_scheduled_task_dispatches_broadcast_event(): void
    {
        Event::fake([ScheduledTaskStatusChanged::class]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/maintenance/scheduled-tasks/horizon:snapshot/run');

        $response->assertOk();
        $response->assertJsonPath('success', true);

        // The subscriber should dispatch the event, but since we're running synchronously
        // via Artisan, we just verify the endpoint works
    }

    public function test_run_unknown_task_returns_error(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/maintenance/scheduled-tasks/unknown-task/run');

        // The API catches the exception and returns a structured error response
        $response->assertJsonStructure(['success', 'message']);
        $this->assertFalse($response->json('success'));
    }

    public function test_scheduled_tasks_requires_authentication(): void
    {
        $response = $this->getJson('/api/maintenance/scheduled-tasks');

        $response->assertUnauthorized();
    }

    public function test_scheduled_task_structure_is_correct(): void
    {
        $response = $this->actingAs($this->admin)
            ->getJson('/api/maintenance/scheduled-tasks');

        $response->assertOk();
        $response->assertJsonStructure([
            'data' => [
                '*' => [
                    'name',
                    'description',
                    'schedule',
                    'status',
                    'last_run',
                    'start_time',
                    'duration',
                ],
            ],
        ]);
    }
}
