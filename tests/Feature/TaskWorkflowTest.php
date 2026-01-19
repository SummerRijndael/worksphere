<?php

namespace Tests\Feature;

use App\Enums\TaskStatus;
use App\Models\Project;
use App\Models\Task;
use App\Models\TaskTemplate;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Http\UploadedFile;
use Illuminate\Support\Facades\Storage;
use Spatie\Permission\Models\Permission;
use App\Services\PermissionService;
use Tests\TestCase;

class TaskWorkflowTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $team;
    protected $project;

    protected function setUp(): void
    {
        parent::setUp();
        
        $this->user = User::factory()->create();
        $this->team = Team::factory()->create();
        // $this->user->teams()->attach($this->team); // Redundant and causes unique constraint error
        $this->team->members()->attach($this->user, ['role' => 'admin']);
        $this->project = Project::factory()->create(['team_id' => $this->team->id]);
        $this->project->members()->attach($this->user);
        
        $this->actingAs($this->user);
    }

    public function test_can_create_task_with_checklist_items()
    {
        $response = $this->postJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks", [
            'title' => 'Task with Checklist',
            'checklist' => [
                ['text' => 'Item 1', 'is_completed' => false],
                ['text' => 'Item 2', 'is_completed' => true]
            ]
        ]);

        $response->assertStatus(201);
        $task = Task::first();
        
        $this->assertCount(2, $task->checklistItems);
        $this->assertEquals('Item 1', $task->checklistItems[0]->text);
        $this->assertEquals('todo', $task->checklistItems[0]->status->value);
        $this->assertEquals('Item 2', $task->checklistItems[1]->text);
        $this->assertEquals('done', $task->checklistItems[1]->status->value);
    }

    public function test_can_save_task_structures_as_template()
    {
        $response = $this->postJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks", [
            'title' => 'Template Task',
            'checklist' => [['text' => 'Step 1'], ['text' => 'Step 2']],
            'save_as_template' => true,
        ]);

        $response->assertStatus(201);
        
        $this->assertDatabaseHas('task_templates', [
            'name' => 'Template Task (Template)',
        ]);
        
        $template = TaskTemplate::where('name', 'Template Task (Template)')->first();
        $this->assertCount(2, $template->checklist_template);
    }

    public function test_submit_for_qa_blocked_by_incomplete_checklist()
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'status' => TaskStatus::InProgress,
        ]);
        
        $task->checklistItems()->create(['text' => 'Required Step', 'status' => 'todo']);

        $response = $this->postJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks/{$task->public_id}/submit-qa");

        $response->assertStatus(422);
        $response->assertJsonFragment(['message' => 'Cannot submit for QA. All checklist items must be completed.']);
    }

    public function test_submit_for_qa_success_with_complete_checklist()
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'status' => TaskStatus::InProgress,
        ]);
        
        $task->checklistItems()->create(['text' => 'Done Step', 'status' => 'done']);

        $response = $this->postJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks/{$task->public_id}/submit-qa");

        $response->assertStatus(200);
        $task->refresh();
        $this->assertEquals(TaskStatus::Submitted, $task->status);
    }

    public function test_toggle_hold_status()
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'status' => TaskStatus::InProgress,
        ]);

        // Pause
        $response = $this->postJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks/{$task->public_id}/toggle-hold");
        $response->assertStatus(200);
        $task->refresh();
        $this->assertEquals(TaskStatus::OnHold, $task->status);

        // Resume
        $response = $this->postJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks/{$task->public_id}/toggle-hold");
        $response->assertStatus(200);
        $task->refresh();
        $this->assertEquals(TaskStatus::InProgress, $task->status);
    }

    public function test_send_to_pm_review()
    {
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
            'status' => TaskStatus::Approved, // Must be Approved (QA Approved) first typically, or coming from flow
        ]);

        $response = $this->postJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks/{$task->public_id}/send-to-pm");
        
        $response->assertStatus(200);
        $task->refresh();
        $this->assertEquals(TaskStatus::PmReview, $task->status);
    }
    public function test_can_upload_and_delete_attachments()
    {
        Storage::fake('public');
        
        // Grant permission
        Permission::create(['name' => 'tasks.manage_files', 'guard_name' => 'web']);
        app(PermissionService::class)->grantTeamPermission($this->user, $this->team, 'tasks.manage_files');
        
        $task = Task::factory()->create([
            'project_id' => $this->project->id,
        ]);
        
        $file = UploadedFile::fake()->create('document.txt', 100);
        
        // Upload
        $response = $this->postJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks/{$task->public_id}/files", [
            'file' => $file
        ]);
        
        $response->assertStatus(201);
        
        // Verify media attached
        $this->assertCount(1, $task->getMedia('attachments'));
        
        $media = $task->getMedia('attachments')->first();
        $this->assertEquals('document.txt', $media->file_name);
        
        // Delete
        $response = $this->deleteJson("/api/teams/{$this->team->public_id}/projects/{$this->project->public_id}/tasks/{$task->public_id}/files/{$media->id}");
        
        $response->assertStatus(200);
        
        // Verify media removed
        $task->refresh();
        $this->assertCount(0, $task->getMedia('attachments'));
    }
}
