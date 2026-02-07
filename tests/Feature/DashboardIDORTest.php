<?php

namespace Tests\Feature;

use App\Models\Invoice;
use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class DashboardIDORTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Create necessary permissions
        Permission::firstOrCreate(['name' => 'invoices.view', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'projects.view', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'tickets.view', 'guard_name' => 'web']);
    }

    public function test_user_cannot_access_other_team_dashboard_stats_idor()
    {
        // 1. Create User A and Team A
        $userA = User::factory()->create();
        $teamA = Team::factory()->create(['owner_id' => $userA->id]);
        $teamA->members()->attach($userA, ['role' => 'admin']);

        // Grant User A permission to view invoices
        $userA->givePermissionTo('invoices.view');

        // 2. Create User B and Team B
        $userB = User::factory()->create();
        $teamB = Team::factory()->create(['owner_id' => $userB->id]);
        $teamB->members()->attach($userB, ['role' => 'admin']);

        // 3. Create sensitive data for Team B
        Invoice::factory()->create([
            'team_id' => $teamB->id,
            'status' => \App\Enums\InvoiceStatus::Paid,
            'total' => 5000.00,
        ]);

        // 4. Authenticate as User A
        $this->actingAs($userA);

        // 5. Try to access Team B's dashboard stats
        $response = $this->getJson('/api/dashboard?team_id=' . $teamB->public_id);

        // 6. Assert Forbidden
        $response->assertStatus(403);
    }

    public function test_user_can_access_own_team_dashboard_stats()
    {
        // 1. Create User A and Team A
        $userA = User::factory()->create();
        $teamA = Team::factory()->create(['owner_id' => $userA->id]);
        $teamA->members()->attach($userA, ['role' => 'admin']);

        $userA->givePermissionTo('invoices.view');

        // 2. Create sensitive data for Team A
        Invoice::factory()->create([
            'team_id' => $teamA->id,
            'status' => \App\Enums\InvoiceStatus::Paid,
            'total' => 1000.00,
        ]);

        // 3. Authenticate as User A
        $this->actingAs($userA);

        // 4. Access Team A's dashboard
        $response = $this->getJson('/api/dashboard?team_id=' . $teamA->public_id);

        // 5. Assert OK
        $response->assertStatus(200);
        // Use loose comparison or assertEquals for float
        $this->assertEquals(1000, $response->json('data.financial.collected.raw'));
    }
}
