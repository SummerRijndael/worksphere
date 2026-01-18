<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Models\AuditLog;
use App\Models\Project;
use App\Models\User;
use App\Services\DashboardService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class DashboardFixTest extends TestCase
{
    use RefreshDatabase;

    public function test_dashboard_service_activity_feed_handles_enums(): void
    {
        $user = User::factory()->create();
        $project = Project::factory()->create();

        // Create an audit log with an Enum action
        AuditLog::create([
            'user_id' => $user->id,
            'action' => AuditAction::Created,
            'auditable_type' => get_class($project),
            'auditable_id' => $project->id,
            'metadata' => ['name' => $project->name],
        ]);

        $service = new DashboardService;

        // This should not throw TypeError
        $activity = $service->getActivityFeed($user);

        $this->assertIsArray($activity);
        $this->assertNotEmpty($activity);
        $this->assertEquals('created project', $activity[0]['action']);
    }

    public function test_dashboard_service_project_status_chart_handles_enums(): void
    {
        $user = User::factory()->create();
        $team = \App\Models\Team::factory()->create();
        $user->teams()->attach($team);

        // Create projects with different statuses and add user as member
        $p1 = Project::factory()->create(['team_id' => $team->id, 'status' => \App\Enums\ProjectStatus::Active]);
        $p1->members()->attach($user);

        $p2 = Project::factory()->create(['team_id' => $team->id, 'status' => \App\Enums\ProjectStatus::Draft]);
        $p2->members()->attach($user);

        $service = new DashboardService;

        // This should not throw TypeError
        $charts = $service->getChartData($user, $team);

        $this->assertIsArray($charts);
        $this->assertArrayHasKey('project_status', $charts);
        $data = $charts['project_status'];

        $this->assertIsArray($data);
        $this->assertArrayHasKey('labels', $data);
        $this->assertArrayHasKey('data', $data);
        // "Active" and "Draft" should be present in labels or data indirectly
        $this->assertContains('Active', $data['labels']);
        $this->assertContains('Draft', $data['labels']);
    }
}
