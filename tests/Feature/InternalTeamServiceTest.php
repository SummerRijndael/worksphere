<?php

namespace Tests\Feature;

use App\Enums\InternalTeamRole;
use App\Models\InternalTeam;
use App\Models\SupportSkill;
use App\Models\User;
use App\Services\InternalTeamService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class InternalTeamServiceTest extends TestCase
{
    use RefreshDatabase;

    protected InternalTeamService $service;
    protected User $actor;

    protected function setUp(): void
    {
        parent::setUp();
        $this->service = app(InternalTeamService::class);
        $this->actor = User::factory()->create();
    }

    public function test_it_creates_an_internal_team()
    {
        $data = [
            'name' => 'Tech Support Team',
            'department' => 'IT',
            'status' => 'active',
        ];

        $team = $this->service->create($data, $this->actor);

        $this->assertInstanceOf(InternalTeam::class, $team);
        $this->assertEquals('Tech Support Team', $team->name);
        $this->assertEquals('tech-support-team', $team->slug);
        $this->assertEquals('IT', $team->department);
        $this->assertEquals('active', $team->status);

        $this->assertDatabaseHas('internal_teams', [
            'id' => $team->id,
            'name' => 'Tech Support Team',
            'slug' => 'tech-support-team',
            'department' => 'IT',
        ]);
    }

    public function test_it_updates_an_internal_team()
    {
        $team = InternalTeam::create([
            'name' => 'Old Name',
            'slug' => 'old-name',
            'department' => 'Old Dept',
            'status' => 'inactive',
        ]);

        $updatedTeam = $this->service->update($team, [
            'name' => 'New Name',
            'department' => 'New Dept',
            'status' => 'active',
        ], $this->actor);

        $this->assertEquals('New Name', $updatedTeam->name);
        $this->assertEquals('new-name', $updatedTeam->slug);
        $this->assertEquals('New Dept', $updatedTeam->department);
        $this->assertEquals('active', $updatedTeam->status);

        $this->assertDatabaseHas('internal_teams', [
            'id' => $team->id,
            'name' => 'New Name',
            'slug' => 'new-name',
        ]);
    }

    public function test_it_adds_and_removes_members()
    {
        $team = InternalTeam::create([
            'name' => 'Network Ops',
            'slug' => 'network-ops',
            'department' => 'IT',
            'status' => 'active',
        ]);

        $user = User::factory()->create();

        // Add Member
        $this->service->addMember($team, $user, 'manager', $this->actor);

        $this->assertDatabaseHas('internal_team_user', [
            'internal_team_id' => $team->id,
            'user_id' => $user->id,
            'role' => 'manager',
        ]);

        $this->assertTrue($team->hasRole($user, 'manager'));

        // Removing Member
        $this->service->removeMember($team, $user, $this->actor);

        $this->assertDatabaseMissing('internal_team_user', [
            'internal_team_id' => $team->id,
            'user_id' => $user->id,
        ]);
    }

    public function test_it_syncs_support_skills()
    {
        $team = InternalTeam::create([
            'name' => 'Customer Success',
            'slug' => 'customer-success',
            'department' => 'Support',
            'status' => 'active',
        ]);

        $skill1 = SupportSkill::create([
            'name' => 'Billing Support',
            'slug' => 'billing',
            'department' => 'Finance',
            'is_active' => true,
        ]);

        $skill2 = SupportSkill::create([
            'name' => 'Technical Support',
            'slug' => 'technical',
            'department' => 'IT',
            'is_active' => true,
        ]);

        $this->service->syncSupportSkills($team, [$skill1->id, $skill2->id], $this->actor);

        $this->assertDatabaseHas('support_skill_internal_team', [
            'internal_team_id' => $team->id,
            'support_skill_id' => $skill1->id,
        ]);

        $this->assertDatabaseHas('support_skill_internal_team', [
            'internal_team_id' => $team->id,
            'support_skill_id' => $skill2->id,
        ]);

        // Sync to only skill1
        $this->service->syncSupportSkills($team, [$skill1->id], $this->actor);

        $this->assertDatabaseMissing('support_skill_internal_team', [
            'internal_team_id' => $team->id,
            'support_skill_id' => $skill2->id,
        ]);
    }

    public function test_it_deletes_an_internal_team()
    {
        $team = InternalTeam::create([
            'name' => 'Delete Me',
            'slug' => 'delete-me',
            'department' => 'Trash',
            'status' => 'inactive',
        ]);

        $this->service->delete($team, $this->actor);

        $this->assertDatabaseMissing('internal_teams', [
            'id' => $team->id,
        ]);
    }
}
