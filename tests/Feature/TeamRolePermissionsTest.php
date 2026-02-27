<?php

namespace Tests\Feature;

use App\Models\Client;
use App\Models\Team;
use App\Models\User;
use App\Models\TeamRole;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Role;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class TeamRolePermissionsTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Setup permissions needed for InvoicePolicy (which uses PermissionService)
        Permission::firstOrCreate(['name' => 'invoices.create', 'guard_name' => 'web']);
        Permission::firstOrCreate(['name' => 'clients.create', 'guard_name' => 'web']);
    }

    public function test_team_lead_can_create_client()
    {
        $user = User::factory()->create();
        $team = Team::factory()->create(); // User NOT the owner_id
        
        // Add as team_lead
        $team->addMember($user, 'team_lead');

        Sanctum::actingAs($user);

        // We check the policy directly since checking API would require full controller implementation
        $this->assertTrue($user->can('create', Client::class));
    }

    public function test_operator_cannot_create_client()
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        
        // Add as operator
        $team->addMember($user, 'operator');

        Sanctum::actingAs($user);

        $this->assertFalse($user->can('create', Client::class));
    }

    public function test_team_lead_can_create_invoice()
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        
        // Add as team_lead - this should grant 'invoices.create' via PermissionService
        $team->addMember($user, 'team_lead');

        Sanctum::actingAs($user);

        // Note: InvoicePolicy->create expects (User $user, Team $team)
        $this->assertTrue($user->can('create', [\App\Models\Invoice::class, $team]));
    }

    public function test_operator_cannot_create_invoice()
    {
        $user = User::factory()->create();
        $team = Team::factory()->create();
        
        // Add as operator
        $team->addMember($user, 'operator');

        Sanctum::actingAs($user);

        $this->assertFalse($user->can('create', [\App\Models\Invoice::class, $team]));
    }
}
