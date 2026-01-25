<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Project;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ProjectClientTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;
    protected Team $team;
    protected Client $client;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup permissions
        $permissions = ['projects.view', 'projects.create', 'projects.update', 'clients.view'];
        foreach ($permissions as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        $adminRole = Role::findOrCreate('administrator', 'web');
        $adminRole->givePermissionTo($permissions);

        $this->owner = User::factory()->create();
        $this->owner->assignRole('administrator');

        $this->team = Team::factory()->create([
            'owner_id' => $this->owner->id,
        ]);
        $this->team->addMember($this->owner, 'owner');

        $this->client = Client::factory()->create([
            'team_id' => $this->team->id,
            'status' => 'active',
        ]);
    }

    public function test_can_create_project_with_client(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/projects", [
                'name' => 'Client Project',
                'status' => 'active',
                'priority' => 'medium',
                'client_id' => $this->client->public_id,
            ]);

        $response->assertCreated();

        $this->assertDatabaseHas('projects', [
            'team_id' => $this->team->id,
            'name' => 'Client Project',
            'client_id' => $this->client->id,
        ]);
    }

    public function test_can_update_project_client(): void
    {
        $project = Project::factory()->forTeam($this->team)->createdBy($this->owner)->create();
        
        $response = $this->actingAs($this->owner)
            ->putJson("/api/teams/{$this->team->public_id}/projects/{$project->public_id}", [
                'client_id' => $this->client->public_id,
            ]);

        $response->assertOk();

        $this->assertEquals($this->client->id, $project->fresh()->client_id);
    }

    public function test_project_response_includes_client(): void
    {
        $project = Project::factory()->forTeam($this->team)->createdBy($this->owner)->create([
            'client_id' => $this->client->id,
        ]);

        $response = $this->actingAs($this->owner)
            ->getJson("/api/teams/{$this->team->public_id}/projects/{$project->public_id}");

        $response->assertOk()
            ->assertJsonPath('client.id', $this->client->public_id)
            ->assertJsonPath('client.name', $this->client->name);
    }

    public function test_can_filter_projects_by_client(): void
    {
        Project::factory()->forTeam($this->team)->createdBy($this->owner)->active()->create([
            'client_id' => $this->client->id,
        ]);
        Project::factory()->forTeam($this->team)->createdBy($this->owner)->active()->create(); // No client

        $response = $this->actingAs($this->owner)
            ->getJson("/api/teams/{$this->team->public_id}/projects?client_id={$this->client->public_id}");

        $response->assertOk();
        $this->assertCount(1, $response->json('data'));
    }
}
