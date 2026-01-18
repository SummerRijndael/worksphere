<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TaskManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $admin;

    protected User $member;

    protected Team $team;

    protected Project $project;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable async audit logging for tests to avoid UUID collisions
        config(['audit.async' => false]);

        // Create permissions
        $permissions = [
            'projects.view', 'projects.create', 'projects.update',
            'projects.delete', 'projects.archive', 'projects.manage_members',
            'projects.manage_files',
            'tasks.view', 'tasks.create', 'tasks.update', 'tasks.delete',
            'tasks.assign', 'tasks.submit_qa', 'tasks.qa_review',
            'tasks.send_to_client', 'tasks.client_response', 'tasks.complete',
            'tasks.archive', 'tasks.comment', 'tasks.manage_files',
        ];

        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        // Create roles
        $adminRole = Role::findOrCreate('administrator', 'web');
        $adminRole->givePermissionTo($permissions);

        $userRole = Role::findOrCreate('user', 'web');
        $userRole->givePermissionTo(['projects.view', 'tasks.view', 'tasks.update', 'tasks.comment']);

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

        // Create project
        $this->project = Project::factory()->forTeam($this->team)->createdBy($this->owner)->active()->create();
        $this->project->addMember($this->owner, 'manager');
        $this->project->addMember($this->admin, 'member');
        $this->project->addMember($this->member, 'member');
    }

    // ===== CRUD Tests =====

    public function test_can_list_project_tasks(): void
    {
        Task::factory()->count(3)->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
            'status' => TaskStatus::Open,
        ]);

        $response = $this->actingAs($this->owner)
            ->getJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'title',
                        'description',
                        'status',
                        'priority',
                        'due_date',
                    ],
                ],
            ]);

        $this->assertCount(3, $response->json('data'));
    }

    public function test_can_create_task(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks", [
                'title' => 'New Test Task',
                'description' => 'Task description',
                'priority' => 3,
                'due_date' => now()->addDays(7)->toDateString(),
            ]);

        $response->assertCreated()
            ->assertJsonPath('title', 'New Test Task')
            ->assertJsonPath('status.value', TaskStatus::Draft->value);

        $this->assertDatabaseHas('tasks', [
            'title' => 'New Test Task',
            'project_id' => $this->project->id,
        ]);
    }

    public function test_can_create_task_with_assignee(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks", [
                'title' => 'Assigned Task',
                'description' => 'Task with assignee',
                'assigned_to' => $this->member->public_id,
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('tasks', [
            'title' => 'Assigned Task',
            'assigned_to' => $this->member->id,
        ]);
    }

    public function test_can_show_task(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
            'status' => TaskStatus::Open,
        ]);

        $response = $this->actingAs($this->owner)
            ->getJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks/{$task->public_id}");

        $response->assertOk()
            ->assertJsonPath('id', $task->public_id)
            ->assertJsonPath('title', $task->title);
    }

    public function test_can_update_task(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
            'status' => TaskStatus::Draft,
        ]);

        $response = $this->actingAs($this->owner)
            ->putJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks/{$task->public_id}", [
                'title' => 'Updated Task Title',
                'priority' => 5,
            ]);

        $response->assertOk()
            ->assertJsonPath('title', 'Updated Task Title')
            ->assertJsonPath('priority', 5);
    }

    public function test_can_delete_task(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
        ]);

        $response = $this->actingAs($this->owner)
            ->deleteJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks/{$task->public_id}");

        $response->assertOk()
            ->assertJsonPath('message', 'Task deleted successfully.');

        $this->assertSoftDeleted('tasks', ['id' => $task->id]);
    }

    // ===== Assignment Tests =====

    public function test_can_assign_task(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
            'status' => TaskStatus::Open,
        ]);

        $response = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks/{$task->public_id}/assign", [
                'assigned_to' => $this->admin->public_id,
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Task assigned successfully.');

        $this->assertDatabaseHas('tasks', [
            'id' => $task->id,
            'assigned_to' => $this->admin->id,
        ]);
    }

    public function test_cannot_assign_task_to_non_project_member(): void
    {
        $nonMember = User::factory()->create();

        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
        ]);

        $response = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks/{$task->public_id}/assign", [
                'assigned_to' => $nonMember->public_id,
            ]);

        $response->assertStatus(422);
    }

    // ===== Workflow Tests =====

    public function test_can_start_task(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
            'status' => TaskStatus::Open,
        ]);

        $response = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks/{$task->public_id}/start");

        $response->assertOk()
            ->assertJsonPath('message', 'Task started successfully.')
            ->assertJsonPath('task.status.value', TaskStatus::InProgress->value);
    }

    public function test_cannot_start_task_from_invalid_status(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
            'status' => TaskStatus::Completed,
        ]);

        $response = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks/{$task->public_id}/start");

        $response->assertStatus(422)
            ->assertJsonPath('message', 'Cannot start this task. Invalid status transition.');
    }

    public function test_can_submit_task_for_qa(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
            'status' => TaskStatus::InProgress,
        ]);

        $response = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks/{$task->public_id}/submit-qa", [
                'notes' => 'Ready for review',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Task submitted for QA successfully.')
            ->assertJsonPath('task.status.value', TaskStatus::Submitted->value);
    }

    public function test_can_start_qa_review(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
            'status' => TaskStatus::Submitted,
        ]);

        $response = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks/{$task->public_id}/start-qa-review");

        $response->assertOk()
            ->assertJsonPath('message', 'QA review started successfully.')
            ->assertJsonPath('task.status.value', TaskStatus::InQa->value);
    }

    public function test_can_approve_task_in_qa_review(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
            'status' => TaskStatus::InQa,
        ]);

        // Create a QA review
        $task->qaReviews()->create([
            'reviewer_id' => $this->owner->id,
            'status' => 'in_progress',
        ]);

        $response = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks/{$task->public_id}/complete-qa-review", [
                'approved' => true,
                'notes' => 'Looks good!',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Task approved.')
            ->assertJsonPath('task.status.value', TaskStatus::Approved->value);
    }

    public function test_can_reject_task_in_qa_review(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
            'status' => TaskStatus::InQa,
        ]);

        // Create a QA review
        $task->qaReviews()->create([
            'reviewer_id' => $this->owner->id,
            'status' => 'in_progress',
        ]);

        $response = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks/{$task->public_id}/complete-qa-review", [
                'approved' => false,
                'notes' => 'Needs fixes',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Task rejected.')
            ->assertJsonPath('task.status.value', TaskStatus::Rejected->value);
    }

    public function test_can_send_task_to_client(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
            'status' => TaskStatus::Approved,
        ]);

        $response = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks/{$task->public_id}/send-to-client", [
                'message' => 'Please review',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Task sent to client successfully.')
            ->assertJsonPath('task.status.value', TaskStatus::SentToClient->value);
    }

    public function test_can_record_client_approval(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
            'status' => TaskStatus::SentToClient,
        ]);

        $response = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks/{$task->public_id}/client-approve", [
                'notes' => 'Client is happy',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Client approval recorded successfully.')
            ->assertJsonPath('task.status.value', TaskStatus::ClientApproved->value);
    }

    public function test_can_record_client_rejection(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
            'status' => TaskStatus::SentToClient,
        ]);

        $response = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks/{$task->public_id}/client-reject", [
                'reason' => 'Client wants changes',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Client rejection recorded successfully.')
            ->assertJsonPath('task.status.value', TaskStatus::ClientRejected->value);
    }

    public function test_can_return_task_to_progress_after_rejection(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
            'status' => TaskStatus::Rejected,
        ]);

        $response = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks/{$task->public_id}/return-to-progress", [
                'notes' => 'Working on fixes',
            ]);

        $response->assertOk()
            ->assertJsonPath('message', 'Task returned to in progress.')
            ->assertJsonPath('task.status.value', TaskStatus::InProgress->value);
    }

    public function test_can_complete_task(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
            'status' => TaskStatus::ClientApproved,
        ]);

        $response = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks/{$task->public_id}/complete");

        $response->assertOk()
            ->assertJsonPath('message', 'Task completed successfully.')
            ->assertJsonPath('task.status.value', TaskStatus::Completed->value);
    }

    public function test_can_archive_task(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
            'status' => TaskStatus::Completed,
        ]);

        $response = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks/{$task->public_id}/archive");

        $response->assertOk()
            ->assertJsonPath('message', 'Task archived successfully.')
            ->assertJsonPath('task.status.value', TaskStatus::Archived->value);
    }

    // ===== Comment Tests =====

    public function test_can_add_comment_to_task(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
        ]);

        $response = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks/{$task->public_id}/comments", [
                'content' => 'This is a test comment',
            ]);

        $response->assertCreated()
            ->assertJsonPath('message', 'Comment added successfully.')
            ->assertJsonPath('comment.content', 'This is a test comment');
    }

    public function test_can_add_internal_comment_to_task(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
        ]);

        $response = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks/{$task->public_id}/comments", [
                'content' => 'This is an internal comment',
                'is_internal' => true,
            ]);

        $response->assertCreated()
            ->assertJsonPath('comment.is_internal', true);
    }

    public function test_can_list_task_comments(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
        ]);

        $task->comments()->createMany([
            ['user_id' => $this->owner->id, 'content' => 'Comment 1', 'is_internal' => false],
            ['user_id' => $this->owner->id, 'content' => 'Comment 2', 'is_internal' => false],
        ]);

        $response = $this->actingAs($this->owner)
            ->getJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks/{$task->public_id}/comments");

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    // ===== Status History Tests =====

    public function test_can_view_task_status_history(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
            'status' => TaskStatus::Open,
        ]);

        // Transition to trigger status history
        $task->transitionTo(TaskStatus::InProgress, $this->owner, 'Starting work');

        $response = $this->actingAs($this->owner)
            ->getJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks/{$task->public_id}/status-history");

        $response->assertOk();
        $this->assertNotEmpty($response->json());
    }

    // ===== Filter Tests =====

    public function test_can_filter_tasks_by_status(): void
    {
        Task::factory()->count(2)->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
            'status' => TaskStatus::Open,
        ]);

        Task::factory()->count(3)->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
            'status' => TaskStatus::InProgress,
        ]);

        $response = $this->actingAs($this->owner)
            ->getJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks?status=in_progress");

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
    }

    public function test_can_filter_tasks_by_assignee(): void
    {
        Task::factory()->count(2)->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
            'assigned_to' => $this->admin->id,
            'status' => TaskStatus::Open,
        ]);

        Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
            'assigned_to' => $this->member->id,
            'status' => TaskStatus::Open,
        ]);

        $response = $this->actingAs($this->owner)
            ->getJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks?assignee={$this->admin->public_id}");

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_can_filter_unassigned_tasks(): void
    {
        Task::factory()->count(2)->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
            'assigned_to' => null,
            'status' => TaskStatus::Open,
        ]);

        Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
            'assigned_to' => $this->member->id,
            'status' => TaskStatus::Open,
        ]);

        $response = $this->actingAs($this->owner)
            ->getJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks?unassigned=1");

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_archived_tasks_excluded_by_default(): void
    {
        Task::factory()->count(2)->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
            'status' => TaskStatus::Open,
        ]);

        Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
            'status' => TaskStatus::Archived,
        ]);

        $response = $this->actingAs($this->owner)
            ->getJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks");

        $response->assertOk();
        $this->assertCount(2, $response->json('data'));
    }

    public function test_can_include_archived_tasks(): void
    {
        Task::factory()->count(2)->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
            'status' => TaskStatus::Open,
        ]);

        Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
            'status' => TaskStatus::Archived,
        ]);

        $response = $this->actingAs($this->owner)
            ->getJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks?include_archived=1");

        $response->assertOk();
        $this->assertCount(3, $response->json('data'));
    }

    // ===== Authorization Tests =====

    public function test_user_without_permission_cannot_create_task(): void
    {
        // Create a user who is NOT a team member at all
        $outsider = User::factory()->create();
        $outsider->assignRole('user');

        $response = $this->actingAs($outsider)
            ->postJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks", [
                'title' => 'Unauthorized Task',
            ]);

        $response->assertForbidden();
    }

    public function test_user_without_permission_cannot_delete_task(): void
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
        ]);

        $response = $this->actingAs($this->member)
            ->deleteJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks/{$task->public_id}");

        $response->assertForbidden();
    }

    // ===== Subtask Tests =====

    public function test_can_create_subtask(): void
    {
        $parentTask = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
            'status' => TaskStatus::Open,
        ]);

        $response = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks", [
                'title' => 'Subtask',
                'parent_id' => $parentTask->public_id,
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('tasks', [
            'title' => 'Subtask',
            'parent_id' => $parentTask->id,
        ]);
    }

    public function test_subtasks_not_included_in_main_list(): void
    {
        $parentTask = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
            'status' => TaskStatus::Open,
        ]);

        Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
            'parent_id' => $parentTask->id,
            'status' => TaskStatus::Open,
        ]);

        $response = $this->actingAs($this->owner)
            ->getJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks");

        $response->assertOk();
        $this->assertCount(1, $response->json('data')); // Only parent task
    }

    // ===== Cross-Team/Project Access Tests =====

    public function test_cannot_access_task_from_different_project(): void
    {
        $otherProject = Project::factory()->forTeam($this->team)->createdBy($this->owner)->create();
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'created_by' => $this->owner->id,
        ]);

        $response = $this->actingAs($this->owner)
            ->getJson("/api/teams/{$this->team->public_id}/projects/{$otherProject->public_id}/tasks/{$task->public_id}");

        $response->assertNotFound();
    }

    public function test_full_workflow_cycle(): void
    {
        // Create task
        $createResponse = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks", [
                'title' => 'Full Workflow Task',
                'status' => 'open',
            ]);

        $createResponse->assertCreated();
        $taskId = $createResponse->json('id');

        // Start task
        $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks/{$taskId}/start")
            ->assertOk();

        // Submit for QA
        $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks/{$taskId}/submit-qa")
            ->assertOk();

        // Start QA review
        $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks/{$taskId}/start-qa-review")
            ->assertOk();

        // Approve in QA
        $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks/{$taskId}/complete-qa-review", [
                'approved' => true,
            ])
            ->assertOk();

        // Send to client
        $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks/{$taskId}/send-to-client")
            ->assertOk();

        // Client approves
        $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks/{$taskId}/client-approve")
            ->assertOk();

        // Complete
        $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks/{$taskId}/complete")
            ->assertOk();

        // Archive
        $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks/{$taskId}/archive")
            ->assertOk();

        // Verify final state
        $task = Task::where('public_id', $taskId)->first();
        $this->assertEquals(TaskStatus::Archived, $task->status);
        $this->assertNotNull($task->completed_at);
        $this->assertNotNull($task->archived_at);
    }
}
