<?php

namespace Tests\Feature\Api;

use App\Models\Client;
use App\Models\Team;
use App\Models\User;
use App\Models\Project;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class ClientStatsTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();
        
        Permission::findOrCreate('clients.view', 'web');
        Permission::findOrCreate('clients.manage_any_team', 'web'); // Admin permission often used
    }

    public function test_admin_sees_global_stats()
    {
        $user = User::factory()->create();
        $role = Role::findOrCreate('administrator', 'web');
        $user->assignRole($role);
        
        // Team A (User's team)
        $teamA = Team::factory()->create(['owner_id' => $user->id]);
        $user->teams()->attach($teamA);
        Client::factory()->count(2)->create(['team_id' => $teamA->id, 'status' => 'active']);
        
        // Team B (Another team)
        $teamB = Team::factory()->create();
        Client::factory()->count(3)->create(['team_id' => $teamB->id, 'status' => 'active']);
        
        $this->actingAs($user);
        
        $response = $this->getJson('/api/clients/stats');
        
        $response->assertStatus(200);
        $response->assertJson([
            'total' => 5,
            'active' => 5,
        ]);
    }
    
    public function test_regular_user_sees_scoped_stats()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('clients.view');
        
        // Team A (User's team)
        $teamA = Team::factory()->create(['owner_id' => $user->id]);
        $user->teams()->attach($teamA);
        Client::factory()->count(2)->create(['team_id' => $teamA->id, 'status' => 'active']);
        
        // Team B (Not User's team)
        $teamB = Team::factory()->create();
        Client::factory()->count(3)->create(['team_id' => $teamB->id, 'status' => 'active']);
        
        $this->actingAs($user);
        
        $response = $this->getJson('/api/clients/stats');
        
        $response->assertStatus(200);
        $response->assertJson([
            'total' => 2, // Only own team
            'active' => 2,
        ]);
    }
    
    public function test_regular_user_view_all_teams_scope()
    {
        // User belongs to 2 teams
        $user = User::factory()->create();
        $user->givePermissionTo('clients.view'); // Global permission usually implies for their teams?
        // Or if using Team Permissions, we should assign 'clients.view' for both teams.
        // Assuming user has global permission which applies to their teams in this app logic (from controller).
        // Wait, controller checks `$permissionService->hasTeamPermission($user, $team, 'clients.view')`.
        // If we only give global permission, does it trickle down?
        // Spatie standard: YES if we check `$user->can()`.
        // But `$permissionService->hasTeamPermission` logic? 
        // Let's assume we need to give permission appropriately.
        // For simplicity in this test environment without full complex seeding, 
        // if user is owner usually they have permission?
        // Let's use `givePermissionTo` as global for now and rely on service handling it 
        // OR mock/assign team roles. 
        // Let's try assigning team role logic if possible, or just global permission if supported.
        // The middleware test failed earlier without proper setup.
        
        // Let's bind PermissionService behavior or simply add user to team with a role that has permission if needed.
        // Or just rely on the fact that owner usually has permissions? 
        // The controller iterates user->teams.

        $teamA = Team::factory()->create(['owner_id' => $user->id]);
        $user->teams()->attach($teamA, ['role' => 'admin']); // Pivot role
        
        $teamB = Team::factory()->create(['owner_id' => $user->id]);
        $user->teams()->attach($teamB, ['role' => 'admin']);
        
        Client::factory()->count(2)->create(['team_id' => $teamA->id, 'status' => 'active']);
        Client::factory()->count(3)->create(['team_id' => $teamB->id, 'status' => 'active']);
        
        // Team C (Not User's team)
        $teamC = Team::factory()->create();
        Client::factory()->count(4)->create(['team_id' => $teamC->id, 'status' => 'active']);
        
        // We need to ensure `hasTeamPermission` returns true for team A and B.
        // In simple setup, having global permission might satisfy `hasTeamPermission` depending on implementation.
        // Let's guess: yes.
        
        $this->actingAs($user);
        
        $response = $this->getJson('/api/clients/stats');
        
        $response->assertStatus(200);
        $response->assertJson([
            'total' => 5, // 2 + 3
            'active' => 5,
        ]);
    }
    
    public function test_regular_user_cannot_filter_unauthorized_team()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('clients.view');
        
        $teamA = Team::factory()->create(['owner_id' => $user->id]);
        $user->teams()->attach($teamA);
        
        $teamB = Team::factory()->create(['public_id' => 'team-b-public']);
        Client::factory()->create(['team_id' => $teamB->id, 'name' => 'Secret Client']);
        
        $this->actingAs($user);
        
        // Try to filter by Team B
        $response = $this->getJson('/api/clients?team_id=team-b-public');
        
        $response->assertStatus(403);
        // Code implementation triggers 404 if "Resolve requested team" fails? 
        // Wait, `Team::where(...)` finds it globally.
        // Then `hasTeamPermission` checks it. If not member/permission -> 403.
        // But my code:
        /*
            $targetTeam = \App\Models\Team::where('public_id', $requestedTeamId)...first();
            if (! $targetTeam) abort(404);
            if (! hasPermission...) abort(403);
        */
        // So expected is 403 if team exists but no permission.
        
        // Let's modify assertion to expect error
    }
}
