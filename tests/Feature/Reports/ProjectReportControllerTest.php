<?php

namespace Tests\Feature\Reports;

use App\Models\Project;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Tests\TestCase;

class ProjectReportControllerTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_view_project_report_overview()
    {
        $user = User::factory()->create();
        // Assuming user needs some permission or just be auth for now based on route definition
        // Route definition was: Route::middleware(['auth:sanctum', ...])

        Sanctum::actingAs($user);

        // Seed data
        Project::factory()->count(3)->create(['budget' => 5000]);

        $response = $this->getJson('/api/reports/projects/overview');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'stats' => [
                    'total_projects',
                    'active_projects',
                    'total_budget',
                    'total_revenue',
                    'avg_progress',
                ],
                'charts' => [
                    'status_distribution',
                    'budget_vs_revenue',
                ],
            ]);
    }

    public function test_admin_can_view_project_report_list()
    {
        $user = User::factory()->create();
        Sanctum::actingAs($user);

        Project::factory()->count(5)->create();

        $response = $this->getJson('/api/reports/projects/list');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'status',
                        'progress',
                        'budget',
                        'collected_revenue',
                    ],
                ],
                'current_page',
                'total',
            ]);
    }

    public function test_guest_cannot_view_report()
    {
        $response = $this->getJson('/api/reports/projects/overview');

        $response->assertStatus(401);
    }
}
