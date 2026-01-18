<?php

namespace Tests\Feature;

use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DashboardControllerTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        // Create permissions
        Permission::firstOrCreate(['name' => 'dashboard.view', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'projects.view', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'projects.view_assigned', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'tickets.view', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'tickets.view_own', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'tasks.view', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'tasks.view_assigned', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'invoices.view', 'guard_name' => 'web']);

        $this->user = User::factory()->create();
        $this->user->givePermissionTo([
            'dashboard.view',
            'projects.view',
            'tickets.view',
            'tasks.view',
        ]);

        $this->team = Team::factory()->create(['owner_id' => $this->user->id]);
        $this->team->members()->attach($this->user->id, [
            'role' => 'owner',
            'joined_at' => now(),
        ]);
    }

    public function test_authenticated_user_can_fetch_dashboard(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/dashboard');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'stats',
                    'features',
                    'activity',
                    'projects',
                    'charts',
                ],
            ]);
    }

    public function test_dashboard_returns_feature_flags_based_on_permissions(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.features.projects_enabled', true)
            ->assertJsonPath('data.features.tickets_enabled', true)
            ->assertJsonPath('data.features.tasks_enabled', true);
    }

    public function test_dashboard_stats_endpoint(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/dashboard/stats');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'stats',
                    'features',
                ],
            ]);
    }

    public function test_dashboard_activity_endpoint(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/dashboard/activity?limit=5');

        $response->assertOk()
            ->assertJsonStructure([
                'data',
            ]);
    }

    public function test_dashboard_charts_endpoint(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/dashboard/charts?period=week');

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    'activity',
                    'project_status',
                    'ticket_trends',
                ],
            ]);
    }

    public function test_dashboard_with_team_context(): void
    {
        $response = $this->actingAs($this->user)
            ->getJson('/api/dashboard?team_id='.$this->team->public_id);

        $response->assertOk();
    }

    public function test_dashboard_stats_reflect_actual_counts(): void
    {
        // Create some projects
        $projectCount = 3;
        for ($i = 0; $i < $projectCount; $i++) {
            Project::factory()->create([
                'team_id' => $this->team->id,
                'created_by' => $this->user->id,
            ]);
        }

        $response = $this->actingAs($this->user)
            ->getJson('/api/dashboard?team_id='.$this->team->public_id);

        $response->assertOk();

        // Find the projects stat
        $stats = $response->json('data.stats');
        $projectStat = collect($stats)->firstWhere('id', 'projects');

        $this->assertNotNull($projectStat);
        $this->assertEquals((string) $projectCount, $projectStat['value']);
    }

    public function test_unauthenticated_user_cannot_access_dashboard(): void
    {
        $response = $this->getJson('/api/dashboard');

        $response->assertUnauthorized();
    }

    public function test_user_without_project_permission_does_not_see_project_stats(): void
    {
        // Create user without project permission
        $limitedUser = User::factory()->create();
        $limitedUser->givePermissionTo(['dashboard.view', 'tickets.view_own']);

        $response = $this->actingAs($limitedUser)
            ->getJson('/api/dashboard');

        $response->assertOk()
            ->assertJsonPath('data.features.projects_enabled', false);

        // Check that projects stat is not in the stats array
        $stats = $response->json('data.stats');
        $projectStat = collect($stats)->firstWhere('id', 'projects');
        $this->assertNull($projectStat);
    }
}
