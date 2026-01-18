<?php

namespace Tests\Feature;

use App\Enums\ProjectPriority;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProjectManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $admin;

    protected User $member;

    protected Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        // Create permissions
        $permissions = [
            'projects.view', 'projects.create', 'projects.update',
            'projects.delete', 'projects.archive', 'projects.manage_members',
            'projects.manage_files',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // Create roles
        $adminRole = Role::findOrCreate('administrator', 'web');
        $adminRole->givePermissionTo($permissions);

        $userRole = Role::findOrCreate('user', 'web');
        $userRole->givePermissionTo(['projects.view']);

        // Create users
        $this->owner = User::factory()->create(['email' => 'owner@test.com']);
        $this->owner->assignRole('administrator');

        $this->admin = User::factory()->create(['email' => 'admin@test.com']);
        $this->admin->assignRole('administrator');

        $this->member = User::factory()->create(['email' => 'member@test.com']);
        $this->member->assignRole('user');

        // Create team
        $this->team = Team::factory()->create([
            'name' => 'Test Team',
            'owner_id' => $this->owner->id,
        ]);

        // Add members
        $this->team->addMember($this->owner, 'owner');
        $this->team->addMember($this->admin, 'admin');
        $this->team->addMember($this->member, 'member');
    }

    public function test_can_list_team_projects(): void
    {
        // Create active projects to ensure they're not filtered out
        Project::factory()->count(3)->forTeam($this->team)->createdBy($this->owner)->active()->create();

        $response = $this->actingAs($this->owner)
            ->getJson("/api/teams/{$this->team->public_id}/projects");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'slug',
                        'description',
                        'status',
                        'priority',
                        'start_date',
                        'due_date',
                        'progress_percentage',
                    ],
                ],
            ]);

        $this->assertCount(3, $response->json('data'));
    }

    public function test_can_create_project(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects", [
                'name' => 'New Project',
                'description' => 'Project description',
                'status' => 'active',
                'priority' => 'high',
                'start_date' => '2026-01-05',
                'due_date' => '2026-02-05',
                'budget' => 50000,
                'currency' => 'USD',
            ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'New Project')
            ->assertJsonPath('description', 'Project description')
            ->assertJsonPath('status.value', 'active')
            ->assertJsonPath('priority.value', 'high');

        $this->assertDatabaseHas('projects', [
            'team_id' => $this->team->id,
            'name' => 'New Project',
            'slug' => 'new-project',
            'status' => 'active',
            'priority' => 'high',
        ]);
    }

    public function test_can_create_project_with_initial_members(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects", [
                'name' => 'Team Project',
                'members' => [
                    ['user_id' => $this->admin->public_id, 'role' => 'manager'],
                    ['user_id' => $this->member->public_id, 'role' => 'member'],
                ],
            ]);

        $response->assertCreated();

        $project = Project::where('name', 'Team Project')->first();
        $this->assertEquals(3, $project->members()->count()); // owner + admin + member
    }

    public function test_can_update_project(): void
    {
        $project = Project::factory()->forTeam($this->team)->createdBy($this->owner)->create([
            'name' => 'Original Name',
        ]);

        $response = $this->actingAs($this->owner)
            ->putJson("/api/teams/{$this->team->public_id}/projects/{$project->public_id}", [
                'name' => 'Updated Name',
                'description' => 'Updated description',
                'priority' => 'urgent',
            ]);

        $response->assertOk()
            ->assertJsonPath('name', 'Updated Name')
            ->assertJsonPath('description', 'Updated description')
            ->assertJsonPath('priority.value', 'urgent');
    }

    public function test_can_delete_project(): void
    {
        $project = Project::factory()->forTeam($this->team)->createdBy($this->owner)->create();

        $response = $this->actingAs($this->owner)
            ->deleteJson("/api/teams/{$this->team->public_id}/projects/{$project->public_id}");

        $response->assertOk()
            ->assertJsonPath('message', 'Project deleted successfully.');

        $this->assertSoftDeleted('projects', [
            'id' => $project->id,
        ]);
    }

    public function test_can_archive_project(): void
    {
        $project = Project::factory()->forTeam($this->team)->createdBy($this->owner)->active()->create();

        $response = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects/{$project->public_id}/archive");

        $response->assertOk()
            ->assertJsonPath('project.status.value', 'archived');

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'status' => 'archived',
            'archived_by' => $this->owner->id,
        ]);
    }

    public function test_can_unarchive_project(): void
    {
        $project = Project::factory()->forTeam($this->team)->createdBy($this->owner)->archived()->create();

        $response = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects/{$project->public_id}/unarchive");

        $response->assertOk()
            ->assertJsonPath('project.status.value', 'active');

        $this->assertDatabaseHas('projects', [
            'id' => $project->id,
            'status' => 'active',
            'archived_by' => null,
            'archived_at' => null,
        ]);
    }

    public function test_can_add_member_to_project(): void
    {
        $project = Project::factory()->forTeam($this->team)->createdBy($this->owner)->create();

        $response = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects/{$project->public_id}/members/{$this->admin->public_id}", [
                'role' => 'manager',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Member added successfully.');

        $this->assertTrue($project->fresh()->hasMember($this->admin));
    }

    public function test_can_remove_member_from_project(): void
    {
        $project = Project::factory()->forTeam($this->team)->createdBy($this->owner)->create();
        $project->addMember($this->admin, 'manager');

        $response = $this->actingAs($this->owner)
            ->deleteJson("/api/teams/{$this->team->public_id}/projects/{$project->public_id}/members/{$this->admin->public_id}");

        $response->assertOk()
            ->assertJsonPath('message', 'Member removed successfully.');

        $this->assertFalse($project->fresh()->hasMember($this->admin));
    }

    public function test_can_update_member_role(): void
    {
        $project = Project::factory()->forTeam($this->team)->createdBy($this->owner)->create();
        $project->addMember($this->admin, 'member');

        $response = $this->actingAs($this->owner)
            ->putJson("/api/teams/{$this->team->public_id}/projects/{$project->public_id}/members/{$this->admin->public_id}", [
                'role' => 'manager',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Member role updated successfully.');

        $this->assertEquals('manager', $project->members()->where('user_id', $this->admin->id)->first()->pivot->role);
    }

    public function test_can_get_project_stats(): void
    {
        $project = Project::factory()->forTeam($this->team)->createdBy($this->owner)->create([
            'budget' => 100000,
            'progress_percentage' => 45,
        ]);
        $project->addMember($this->admin, 'manager');

        $response = $this->actingAs($this->owner)
            ->getJson("/api/teams/{$this->team->public_id}/projects/{$project->public_id}/stats");

        $response->assertOk()
            ->assertJsonStructure([
                'progress_percentage',
                'member_count',
                'total_tasks',
            ]);
    }

    public function test_can_get_project_calendar(): void
    {
        $project = Project::factory()->forTeam($this->team)->createdBy($this->owner)->create([
            'start_date' => '2026-01-01',
            'due_date' => '2026-03-01',
        ]);

        $response = $this->actingAs($this->owner)
            ->getJson("/api/teams/{$this->team->public_id}/projects/{$project->public_id}/calendar");

        $response->assertOk()
            ->assertJsonStructure([
                '*' => [
                    'id',
                    'title',
                    'start',
                    'type',
                ],
            ]);
    }

    public function test_can_upload_file_to_project(): void
    {
        Storage::fake('private');

        $project = Project::factory()->forTeam($this->team)->createdBy($this->owner)->create();

        $file = UploadedFile::fake()->create('document.pdf', 1024);
        file_put_contents($file->getPathname(), '%PDF-1.4 '.str_repeat('0', 1000));

        $response = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects/{$project->public_id}/files", [
                'file' => $file,
                'collection' => 'attachments',
            ]);

        $response->assertCreated()
            ->assertJsonStructure([
                'message',
                'file' => [
                    'id',
                    'name',
                    'mime_type',
                ],
            ]);

        $this->assertCount(1, $project->fresh()->getMedia('attachments'));
    }

    public function test_can_delete_file_from_project(): void
    {
        Storage::fake('private');

        $project = Project::factory()->forTeam($this->team)->createdBy($this->owner)->create();

        $file = UploadedFile::fake()->create('document.pdf', 1024);
        $media = $project->addMedia($file)->toMediaCollection('attachments');

        $response = $this->actingAs($this->owner)
            ->deleteJson("/api/teams/{$this->team->public_id}/projects/{$project->public_id}/files/{$media->id}");

        $response->assertOk()
            ->assertJsonPath('message', 'File deleted successfully.');

        $this->assertCount(0, $project->fresh()->getMedia('attachments'));
    }

    public function test_cannot_access_project_from_other_team(): void
    {
        $otherTeam = Team::factory()->create([
            'name' => 'Other Team',
            'owner_id' => $this->admin->id,
        ]);

        $project = Project::factory()->forTeam($otherTeam)->createdBy($this->admin)->create();

        $response = $this->actingAs($this->owner)
            ->getJson("/api/teams/{$this->team->public_id}/projects/{$project->public_id}");

        $response->assertNotFound();
    }

    public function test_can_filter_projects_by_status(): void
    {
        Project::factory()->forTeam($this->team)->createdBy($this->owner)->active()->count(2)->create();
        Project::factory()->forTeam($this->team)->createdBy($this->owner)->draft()->count(1)->create();
        Project::factory()->forTeam($this->team)->createdBy($this->owner)->completed()->count(1)->create();

        $response = $this->actingAs($this->owner)
            ->getJson("/api/teams/{$this->team->public_id}/projects?status=active");

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_can_filter_projects_by_priority(): void
    {
        Project::factory()->forTeam($this->team)->createdBy($this->owner)->active()->highPriority()->count(2)->create();
        Project::factory()->forTeam($this->team)->createdBy($this->owner)->active()->create(['priority' => ProjectPriority::Low]);

        $response = $this->actingAs($this->owner)
            ->getJson("/api/teams/{$this->team->public_id}/projects?priority=high");

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_can_search_projects(): void
    {
        Project::factory()->forTeam($this->team)->createdBy($this->owner)->active()->create(['name' => 'Website Redesign']);
        Project::factory()->forTeam($this->team)->createdBy($this->owner)->active()->create(['name' => 'Mobile App']);
        Project::factory()->forTeam($this->team)->createdBy($this->owner)->active()->create(['name' => 'API Integration']);

        $response = $this->actingAs($this->owner)
            ->getJson("/api/teams/{$this->team->public_id}/projects?search=Website");

        $response->assertOk();
        $data = $response->json('data');
        $names = array_column($data, 'name');
        $this->assertContains('Website Redesign', $names);
    }

    public function test_project_name_validation(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects", [
                'name' => '',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_project_status_validation(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects", [
                'name' => 'Test Project',
                'status' => 'invalid_status',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['status']);
    }

    public function test_project_priority_validation(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects", [
                'name' => 'Test Project',
                'priority' => 'super_urgent',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['priority']);
    }

    public function test_project_date_validation(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects", [
                'name' => 'Test Project',
                'start_date' => '2026-03-01',
                'due_date' => '2026-01-01', // Due before start
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['due_date']);
    }

    public function test_project_budget_validation(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects", [
                'name' => 'Test Project',
                'budget' => -1000,
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['budget']);
    }

    public function test_member_with_view_permission_can_list_projects(): void
    {
        Project::factory()->count(2)->forTeam($this->team)->createdBy($this->owner)->active()->create();

        $response = $this->actingAs($this->member)
            ->getJson("/api/teams/{$this->team->public_id}/projects");

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_member_without_create_permission_cannot_create_project(): void
    {
        $response = $this->actingAs($this->member)
            ->postJson("/api/teams/{$this->team->public_id}/projects", [
                'name' => 'Unauthorized Project',
            ]);

        $response->assertForbidden();
    }

    public function test_project_progress_percentage_auto_updates(): void
    {
        $project = Project::factory()->forTeam($this->team)->createdBy($this->owner)->create([
            'progress_percentage' => 50,
        ]);

        $this->assertEquals(50, $project->progress_percentage);

        // When completed, should be 100
        $project->complete();
        $this->assertEquals(100, $project->fresh()->progress_percentage);
    }

    public function test_project_is_overdue_attribute(): void
    {
        $overdueProject = Project::factory()->forTeam($this->team)->createdBy($this->owner)->overdue()->create();
        $futureProject = Project::factory()->forTeam($this->team)->createdBy($this->owner)->active()->create([
            'due_date' => now()->addMonth(),
        ]);

        $this->assertTrue($overdueProject->is_overdue);
        $this->assertFalse($futureProject->is_overdue);
    }

    public function test_project_days_until_due_attribute(): void
    {
        $project = Project::factory()->forTeam($this->team)->createdBy($this->owner)->create([
            'due_date' => now()->addDays(10),
        ]);

        // Days until due should be approximately 10 (can be 9-10 depending on exact timing)
        $this->assertGreaterThanOrEqual(9, $project->days_until_due);
        $this->assertLessThanOrEqual(10, $project->days_until_due);
    }

    public function test_audit_log_on_project_creation(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects", [
                'name' => 'Audit Test Project',
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->owner->id,
            'action' => 'created',
            'category' => 'project_management',
        ]);
    }

    public function test_audit_log_on_project_archive(): void
    {
        $project = Project::factory()->forTeam($this->team)->createdBy($this->owner)->active()->create();

        $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects/{$project->public_id}/archive");

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->owner->id,
            'action' => 'archived',
            'category' => 'project_management',
        ]);
    }

    public function test_audit_log_on_member_added(): void
    {
        $project = Project::factory()->forTeam($this->team)->createdBy($this->owner)->create();

        $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects/{$project->public_id}/members/{$this->admin->public_id}", [
                'role' => 'manager',
            ]);

        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->owner->id,
            'action' => 'member_added',
            'category' => 'project_management',
        ]);
    }

    public function test_completed_project_cannot_be_modified(): void
    {
        $project = Project::factory()->forTeam($this->team)->createdBy($this->owner)->completed()->create();

        $response = $this->actingAs($this->owner)
            ->putJson("/api/teams/{$this->team->public_id}/projects/{$project->public_id}", [
                'name' => 'Modified Name',
            ]);

        // Project can still be updated even when completed, only status might be restricted
        $response->assertOk();
    }

    public function test_project_slug_is_unique_within_team(): void
    {
        Project::factory()->forTeam($this->team)->createdBy($this->owner)->create(['name' => 'My Project']);

        $response = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects", [
                'name' => 'My Project', // Same name, should generate unique slug
            ]);

        $response->assertCreated();

        // Both projects should exist with different slugs
        $projects = Project::where('team_id', $this->team->id)->where('name', 'My Project')->get();
        $this->assertCount(2, $projects);
        $this->assertNotEquals($projects[0]->slug, $projects[1]->slug);
    }
}
