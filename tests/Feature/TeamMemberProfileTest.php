<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class TeamMemberProfileTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        cache()->flush();

        // Seed necessary permissions
        app(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();
        \Spatie\Permission\Models\Permission::create(['name' => 'user_manage', 'guard_name' => 'web']);
        \Spatie\Permission\Models\Permission::create(['name' => 'team_roles.assign', 'guard_name' => 'web']);
        \Spatie\Permission\Models\Permission::create(['name' => 'teams.update', 'guard_name' => 'web']);
    }

    public function test_user_can_view_teammate_profile()
    {
        $team = Team::factory()->create();
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $team->members()->syncWithoutDetaching([
            $user1->id => ['role' => 'member'],
            $user2->id => ['role' => 'member'],
        ]);

        $response = $this->actingAs($user1)
            ->getJson("/api/users/{$user2->public_id}/profile");

        $response->assertStatus(200)
            ->assertJsonPath('data.name', $user2->name);
    }

    public function test_user_cannot_view_non_teammate_profile()
    {
        $team1 = Team::factory()->create();
        $team2 = Team::factory()->create();
        $user1 = User::factory()->create();
        $user2 = User::factory()->create();

        $team1->members()->syncWithoutDetaching([$user1->id => ['role' => 'member']]);
        $team2->members()->syncWithoutDetaching([$user2->id => ['role' => 'member']]);

        $response = $this->actingAs($user1)
            ->getJson("/api/users/{$user2->public_id}/profile");

        $response->assertStatus(403);
    }

    public function test_team_admin_can_update_member_role()
    {
        $team = Team::factory()->create();
        $admin = User::factory()->create();
        $member = User::factory()->create();

        $team->members()->syncWithoutDetaching([
            $admin->id => ['role' => 'admin'],
            $member->id => ['role' => 'member'],
        ]);

        $response = $this->actingAs($admin)
            ->putJson("/api/teams/{$team->public_id}/members/{$member->public_id}/role", [
                'role' => 'admin',
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseHas('team_user', [
            'team_id' => $team->id,
            'user_id' => $member->id,
            'role' => 'admin',
        ]);
    }

    public function test_team_member_cannot_update_role()
    {
        $team = Team::factory()->create();
        $member1 = User::factory()->create();
        $member2 = User::factory()->create();

        $team->members()->syncWithoutDetaching([
            $member1->id => ['role' => 'member'],
            $member2->id => ['role' => 'member'],
        ]);

        $response = $this->actingAs($member1)
            ->putJson("/api/teams/{$team->public_id}/members/{$member2->public_id}/role", [
                'role' => 'admin',
            ]);

        $response->assertStatus(403);
    }

    public function test_cannot_change_owner_role()
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $admin = User::factory()->create();

        $team->members()->syncWithoutDetaching([
            $owner->id => ['role' => 'owner'],
            $admin->id => ['role' => 'admin'],
        ]);

        $response = $this->actingAs($admin)
            ->putJson("/api/teams/{$team->public_id}/members/{$owner->public_id}/role", [
                'role' => 'member',
            ]);

        $response->assertStatus(403);
    }
    public function test_team_owner_can_upload_avatar()
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        
        $team->members()->syncWithoutDetaching([
            $owner->id => ['role' => 'owner'],
        ]);

        $file = \Illuminate\Http\UploadedFile::fake()->image('avatar.jpg');

        $response = $this->actingAs($owner)
            ->postJson("/api/teams/{$team->public_id}/avatar", [
                'avatar' => $file,
            ]);

        $response->assertStatus(200);
        
        // Assert file exists in storage/media
        $this->assertCount(1, $team->fresh()->getMedia('avatar'));
    }
}
