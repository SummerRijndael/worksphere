<?php

namespace Tests\Feature\Api;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamScopingTest extends TestCase
{
    use RefreshDatabase;

    public function test_admin_can_see_all_teams()
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator'); // Ensure roles/permissions are set up
        
        Team::factory()->count(3)->create(); // Random teams
        $usersTeam = Team::factory()->create();
        $usersTeam->members()->attach($admin, ['role' => 'team_lead']);

        $this->actingAs($admin)
            ->getJson('/api/teams')
            ->assertStatus(200)
            ->assertJsonCount(4, 'data'); // 3 random + 1 joined
    }

    public function test_regular_user_sees_only_own_teams()
    {
        $user = User::factory()->create();
        // No admin role

        $ownTeam = Team::factory()->create();
        $ownTeam->members()->attach($user, ['role' => 'team_lead']);

        $otherTeam = Team::factory()->create();

        $this->actingAs($user)
            ->getJson('/api/teams')
            ->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['name' => $ownTeam->name])
            ->assertJsonMissing(['name' => $otherTeam->name]);
    }

    public function test_regular_user_cannot_view_other_team_profile()
    {
        $user = User::factory()->create();
        $otherTeam = Team::factory()->create();

        $this->actingAs($user)
            ->getJson("/api/teams/{$otherTeam->public_id}")
            ->assertStatus(403);
    }
}
