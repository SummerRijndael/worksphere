<?php

namespace Tests\Feature;

use App\Models\User;
use App\Models\Team;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\DB;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserOptimizationTest extends TestCase
{
    use RefreshDatabase;

    public function test_requires2FASetup_generates_one_query_for_roles(): void
    {
        // Setup user with multiple roles
        $user = User::factory()->create();
        
        $role1 = Role::findOrCreate('role1', 'web');
        $role2 = Role::findOrCreate('role2', 'web');
        $role3 = Role::findOrCreate('role3', 'web');

        $user->assignRole(['role1', 'role2', 'role3']);

        // Enable DB query logging
        DB::enableQueryLog();
        DB::flushQueryLog();

        // Call the method
        $user->requires2FASetup();

        $queries = DB::getQueryLog();
        $enforcementQueries = array_filter($queries, function ($query) {
            return str_contains($query['query'], 'role_two_factor_enforcements');
        });

        $this->assertLessThanOrEqual(1, count($enforcementQueries));
    }

    public function test_generate_username_efficient_collision_check(): void
    {
        // Create an initial user
        User::factory()->create(['username' => 'first.last']);
        User::factory()->create(['username' => 'first.last_1']);
        User::factory()->create(['username' => 'first.last_2']);

        DB::enableQueryLog();
        DB::flushQueryLog();

        $username = User::generateUsername('J', 'first.last@example.com');
        
        $this->assertEquals('first.last_3', $username);

        $queries = DB::getQueryLog();
        $selectQueries = array_filter($queries, function ($query) {
            return str_contains(strtolower($query['query']), 'like');
        });

        // We only expect 1 like query for username generation
        $this->assertLessThanOrEqual(1, count($selectQueries));
    }

    public function test_permission_service_lazy_loads_team_permissions(): void
    {
        $user = User::factory()->create();
        $team1 = Team::factory()->create(['owner_id' => $user->id]);
        $team2 = Team::factory()->create(['owner_id' => $user->id]);

        $user->teams()->attach([$team1->id, $team2->id]);

        $service = app(PermissionService::class);

        DB::enableQueryLog();
        DB::flushQueryLog();

        // Getting persona should NOT trigger team permission queries
        $persona = $service->getPersona($user);

        // Accessing global permissons shouldn't trigger team queries
        $persona->hasPermission('some.permission');

        $queriesBeforeTeamLookup = DB::getQueryLog();
        $queryCountBefore = count($queriesBeforeTeamLookup);

        // Checking team permission SHOULD trigger query once
        $persona->hasTeamPermission($team1->id, 'some.team.permission');

        $queriesAfterTeamLookup = DB::getQueryLog();
        
        // Checking it should increase total query count
        $this->assertGreaterThan($queryCountBefore, count($queriesAfterTeamLookup));

        // It should cache the result, so checking again shouldn't increase query count
        $persona->hasTeamPermission($team1->id, 'some.team.permission');
        $this->assertCount(count($queriesAfterTeamLookup), DB::getQueryLog());
    }
}
