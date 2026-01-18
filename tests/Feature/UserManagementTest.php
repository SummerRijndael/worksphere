<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class UserManagementTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup permissions
        $role = Role::create(['name' => 'authed-admin', 'guard_name' => 'web']);
        $permission = Permission::create(['name' => 'user_manage', 'guard_name' => 'web']);
        $role->givePermissionTo($permission);

        // Also need basic permissions potentially used by policies
        Permission::create(['name' => 'users.view', 'guard_name' => 'web']);
        Permission::create(['name' => 'users.create', 'guard_name' => 'web']);
        Permission::create(['name' => 'users.update', 'guard_name' => 'web']);
        Permission::create(['name' => 'users.delete', 'guard_name' => 'web']);

        $role->givePermissionTo(['users.view', 'users.create', 'users.update', 'users.delete']);

        // Create Team permissions
        Permission::create(['name' => 'teams.view', 'guard_name' => 'web']);
        Permission::create(['name' => 'teams.create', 'guard_name' => 'web']);
        Permission::create(['name' => 'teams.update', 'guard_name' => 'web']);
        Permission::create(['name' => 'teams.delete', 'guard_name' => 'web']);
    }

    public function test_admin_can_list_users()
    {
        $admin = User::factory()->create();
        $admin->assignRole('authed-admin');

        User::factory()->count(3)->create();

        $response = $this->actingAs($admin)
            ->getJson('/api/users');

        $response->assertStatus(200)
            ->assertJsonCount(4, 'data'); // 3 + admin
    }

    public function test_admin_can_create_user()
    {
        $admin = User::factory()->create();
        $admin->assignRole('authed-admin');

        $role = Role::create(['name' => 'member', 'guard_name' => 'web']);

        $response = $this->actingAs($admin)
            ->postJson('/api/users', [
                'name' => 'New User',
                'email' => 'new@example.com',
                'username' => 'newuser',
                'password' => 'password',
                'password_confirmation' => 'password',
                'role' => 'member',
                'status' => 'active',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('users', ['email' => 'new@example.com']);
    }

    public function test_admin_can_update_user()
    {
        $admin = User::factory()->create();
        $admin->assignRole('authed-admin');

        $user = User::factory()->create(['name' => 'Old Name']);

        $response = $this->actingAs($admin)
            ->putJson("/api/users/{$user->public_id}", [
                'name' => 'New Name',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('users', ['id' => $user->id, 'name' => 'New Name']);
    }

    public function test_admin_can_delete_user()
    {
        $admin = User::factory()->create();
        $admin->assignRole('authed-admin');

        $user = User::factory()->create();

        $response = $this->actingAs($admin)
            ->deleteJson("/api/users/{$user->public_id}");

        $response->assertStatus(200);
        $this->assertDatabaseMissing('users', ['id' => $user->id]);
    }

    public function test_admin_can_list_teams()
    {
        $admin = User::factory()->create();
        $admin->assignRole('authed-admin');

        // Create user for owner
        $owner = User::factory()->create();

        // Manual creation to ensure foreign keys
        Team::create([
            'name' => 'Team A',
            'owner_id' => $owner->id,
            'public_id' => 'uuid-1',
            'slug' => 'team-a',
        ]);

        Team::create([
            'name' => 'Team B',
            'owner_id' => $owner->id,
            'public_id' => 'uuid-2',
            'slug' => 'team-b',
        ]);

        $response = $this->actingAs($admin)
            ->getJson('/api/teams');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_admin_can_create_team()
    {
        $admin = User::factory()->create();
        $admin->assignRole('authed-admin');

        $owner = User::factory()->create();

        $response = $this->actingAs($admin)
            ->postJson('/api/teams', [
                'name' => 'New Team',
                'description' => 'Test Description',
                'owner_id' => $owner->public_id,
                'status' => 'active',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('teams', ['name' => 'New Team']);
    }
}
