<?php

namespace Tests\Feature\Api;

use App\Models\Client;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class NavigationControllerTest extends TestCase
{
    use RefreshDatabase;

    public function setUp(): void
    {
        parent::setUp();
        
        // Clear permission cache
        app()[\Spatie\Permission\PermissionRegistrar::class]->forgetCachedPermissions();

        // Create permissions used in navigation
        Permission::findOrCreate('clients.view', 'web');
        Permission::findOrCreate('user_manage', 'web');
    }

    public function test_regular_user_sees_clients_top_level_navigation()
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $user->teams()->attach($team);
        
        $user->givePermissionTo('clients.view');
        
        // Create some clients for the team
        Client::factory()->count(3)->active()->create(['team_id' => $team->id]);

        $this->actingAs($user);

        $response = $this->getJson('/api/navigation');

        $response->assertStatus(200);
        
        // Verify 'clients' item exists and route is /clients
        $sidebar = collect($response->json('sidebar'));
        $clientsItem = $sidebar->firstWhere('id', 'clients');
        
        $this->assertNotNull($clientsItem, 'Clients item should be present');
        $this->assertEquals('/clients', $clientsItem['route']);
        $this->assertNotEmpty($clientsItem['children']);
        
        // Verify children structure
        // 1. View All
        $this->assertEquals('clients-all', $clientsItem['children'][0]['id']);
        
        // 2. Recent Clients
        $this->assertCount(3, $clientsItem['children']); 
    }

    public function test_admin_sees_both_client_navigations()
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $user->id]);
        $user->teams()->attach($team);
        
        $role = Role::findOrCreate('administrator', 'web');
        $user->assignRole($role);
        
        // Admin usually has all permissions or at least user_manage
        $user->givePermissionTo('user_manage');
        $user->givePermissionTo('clients.view');

        $this->actingAs($user);

        $response = $this->getJson('/api/navigation');

        $response->assertStatus(200);
        $sidebar = collect($response->json('sidebar'));

        // 1. Top level clients
        $clientsItem = $sidebar->firstWhere('id', 'clients');
        $this->assertNotNull($clientsItem);
        $this->assertEquals('/clients', $clientsItem['route']);

        // 2. Admin level clients (under user-management)
        $userManagement = $sidebar->firstWhere('id', 'user-management');
        $this->assertNotNull($userManagement);
        
        $adminClients = collect($userManagement['children'])->firstWhere('id', 'admin-clients');
        $this->assertNotNull($adminClients, 'Admin clients item should be present in user management');
        $this->assertEquals('/admin/clients', $adminClients['route']);
    }

    public function test_scoping_limits_clients_per_team()
    {
        $user = User::factory()->create();
        $user->givePermissionTo('clients.view');
        
        // Team A: 5 clients
        $teamA = Team::factory()->create(['owner_id' => $user->id]);
        $user->teams()->attach($teamA);
        Client::factory()->count(5)->active()->create(['team_id' => $teamA->id, 'updated_at' => now()]);

        // Team B: 5 clients (older)
        $teamB = Team::factory()->create(['owner_id' => $user->id]);
        $user->teams()->attach($teamB);
        Client::factory()->count(5)->active()->create(['team_id' => $teamB->id, 'updated_at' => now()->subDay()]);

        $this->actingAs($user);

        $response = $this->getJson('/api/navigation');
        
        $sidebar = collect($response->json('sidebar'));
        $clientsItem = $sidebar->firstWhere('id', 'clients');
        
        $this->assertNotNull($clientsItem, 'Clients item should be present (scoping test)');
        
        $children = collect($clientsItem['children']);

        // Remove "View All"
        $clientNodes = $children->filter(fn($item) => str_starts_with($item['id'], 'client-'));
        
        // We expect:
        // 2 from Team A
        // 2 from Team B
        // Total 4 clients shown (since we limit 2 per team in the loop)
        
        $this->assertCount(4, $clientNodes);
        
        // Verify unique teams
        $teamBadges = $clientNodes->pluck('team_badge')->unique();
        $this->assertCount(2, $teamBadges);
    }
}
