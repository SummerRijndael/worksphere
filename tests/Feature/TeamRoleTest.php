<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TeamRoleTest extends TestCase
{
    use RefreshDatabase;

    protected User $owner;

    protected User $admin;

    protected User $member;

    protected Team $team;

    protected function setUp(): void
    {
        parent::setUp();

        // Create permissions
        Permission::findOrCreate('team_roles.view', 'web');
        Permission::findOrCreate('team_roles.create', 'web');
        Permission::findOrCreate('team_roles.update', 'web');
        Permission::findOrCreate('team_roles.delete', 'web');
        Permission::findOrCreate('team_roles.assign', 'web');

        // Create roles
        $adminRole = Role::findOrCreate('administrator', 'web');
        $adminRole->givePermissionTo([
            'team_roles.view',
            'team_roles.create',
            'team_roles.update',
            'team_roles.delete',
            'team_roles.assign',
        ]);

        $userRole = Role::findOrCreate('user', 'web');
        $userRole->givePermissionTo('team_roles.view');

        // Create users
        $this->owner = User::factory()->create(['email' => 'owner@test.com']);
        $this->owner->assignRole('administrator');

        $this->admin = User::factory()->create(['email' => 'admin@test.com']);
        $this->admin->assignRole('administrator');

        $this->member = User::factory()->create(['email' => 'member@test.com']);
        $this->member->assignRole('user');

        // Create team
        $this->team = Team::factory()->create([
            'name' => 'Test Team',
            'owner_id' => $this->owner->id,
        ]);

        // Add members
        $this->team->addMember($this->owner, 'owner');
        $this->team->addMember($this->admin, 'admin');
        $this->team->addMember($this->member, 'member');

        // Create default roles
        $this->team->createDefaultRoles($this->owner);
    }

    public function test_can_list_team_roles(): void
    {
        $response = $this->actingAs($this->owner)
            ->getJson("/api/teams/{$this->team->public_id}/roles");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'id',
                        'name',
                        'slug',
                        'description',
                        'color',
                        'level',
                        'is_default',
                        'is_system',
                        'permissions',
                    ],
                ],
            ]);

        // Should have 4 default roles: Owner, Admin, Member, Viewer
        $this->assertCount(4, $response->json('data'));
    }

    public function test_can_create_custom_role(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/roles", [
                'name' => 'Project Manager',
                'description' => 'Manages projects within the team',
                'color' => 'success',
                'level' => 60,
                'is_default' => false,
                'permissions' => ['projects.view', 'projects.create', 'projects.update'],
            ]);

        $response->assertCreated()
            ->assertJsonPath('name', 'Project Manager')
            ->assertJsonPath('color', 'success')
            ->assertJsonPath('level', 60);

        $this->assertDatabaseHas('team_roles', [
            'team_id' => $this->team->id,
            'name' => 'Project Manager',
            'slug' => 'project-manager',
        ]);
    }

    public function test_can_update_custom_role(): void
    {
        $role = $this->team->roles()->create([
            'name' => 'Custom Role',
            'description' => 'A custom role',
            'color' => 'primary',
            'level' => 40,
            'is_system' => false,
            'created_by' => $this->owner->id,
        ]);

        $response = $this->actingAs($this->owner)
            ->putJson("/api/teams/{$this->team->public_id}/roles/{$role->public_id}", [
                'name' => 'Updated Role',
                'description' => 'Updated description',
                'color' => 'warning',
                'level' => 45,
            ]);

        $response->assertOk()
            ->assertJsonPath('name', 'Updated Role')
            ->assertJsonPath('color', 'warning')
            ->assertJsonPath('level', 45);
    }

    public function test_cannot_update_system_role(): void
    {
        $systemRole = $this->team->roles()->where('is_system', true)->first();

        $response = $this->actingAs($this->owner)
            ->putJson("/api/teams/{$this->team->public_id}/roles/{$systemRole->public_id}", [
                'name' => 'Hacked Name',
            ]);

        $response->assertForbidden()
            ->assertJsonPath('message', 'System roles cannot be modified.');
    }

    public function test_can_delete_custom_role(): void
    {
        $role = $this->team->roles()->create([
            'name' => 'Temporary Role',
            'description' => 'Will be deleted',
            'color' => 'secondary',
            'level' => 30,
            'is_system' => false,
            'created_by' => $this->owner->id,
        ]);

        $response = $this->actingAs($this->owner)
            ->deleteJson("/api/teams/{$this->team->public_id}/roles/{$role->public_id}");

        $response->assertOk()
            ->assertJsonPath('message', 'Role deleted successfully.');

        $this->assertSoftDeleted('team_roles', [
            'id' => $role->id,
        ]);
    }

    public function test_cannot_delete_system_role(): void
    {
        $systemRole = $this->team->roles()->where('is_system', true)->first();

        $response = $this->actingAs($this->owner)
            ->deleteJson("/api/teams/{$this->team->public_id}/roles/{$systemRole->public_id}");

        $response->assertForbidden()
            ->assertJsonPath('message', 'System roles cannot be deleted.');
    }

    public function test_cannot_delete_role_with_members(): void
    {
        $role = $this->team->roles()->create([
            'name' => 'Active Role',
            'description' => 'Has members',
            'color' => 'primary',
            'level' => 35,
            'is_system' => false,
            'created_by' => $this->owner->id,
        ]);

        // Assign role to a member
        $this->team->members()->updateExistingPivot($this->member->id, [
            'team_role_id' => $role->id,
        ]);

        $response = $this->actingAs($this->owner)
            ->deleteJson("/api/teams/{$this->team->public_id}/roles/{$role->public_id}");

        $response->assertStatus(409)
            ->assertJsonPath('message', 'Cannot delete role that has members assigned. Please reassign members first.');
    }

    public function test_can_assign_role_to_member(): void
    {
        $role = $this->team->roles()->where('slug', 'admin')->first();

        $response = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/roles/{$role->public_id}/assign/{$this->member->public_id}");

        $response->assertOk()
            ->assertJsonPath('message', 'Role assigned successfully.');

        $this->assertDatabaseHas('team_user', [
            'team_id' => $this->team->id,
            'user_id' => $this->member->id,
            'team_role_id' => $role->id,
        ]);
    }

    public function test_cannot_assign_role_to_non_member(): void
    {
        $nonMember = User::factory()->create();
        $role = $this->team->roles()->where('slug', 'member')->first();

        $response = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/roles/{$role->public_id}/assign/{$nonMember->public_id}");

        $response->assertNotFound()
            ->assertJsonPath('message', 'User is not a member of this team.');
    }

    public function test_can_get_available_permissions(): void
    {
        $response = $this->actingAs($this->owner)
            ->getJson("/api/teams/{$this->team->public_id}/roles/permissions");

        $response->assertOk()
            ->assertJsonStructure([
                '*' => [
                    '*' => [
                        'key',
                        'label',
                    ],
                ],
            ]);
    }

    public function test_can_get_role_members(): void
    {
        $role = $this->team->roles()->where('slug', 'member')->first();
        $this->team->members()->updateExistingPivot($this->member->id, [
            'team_role_id' => $role->id,
        ]);

        $response = $this->actingAs($this->owner)
            ->getJson("/api/teams/{$this->team->public_id}/roles/{$role->public_id}/members");

        $response->assertOk()
            ->assertJsonStructure([
                'data' => [
                    '*' => [
                        'public_id',
                        'name',
                        'email',
                    ],
                ],
            ]);
    }

    public function test_cannot_access_other_team_roles(): void
    {
        $otherTeam = Team::factory()->create([
            'name' => 'Other Team',
            'owner_id' => $this->admin->id,
        ]);

        $role = $this->team->roles()->where('slug', 'member')->first();

        $response = $this->actingAs($this->owner)
            ->getJson("/api/teams/{$otherTeam->public_id}/roles/{$role->public_id}");

        // Should fail because user is not a member of other team
        // Returns 404 because role doesn't exist in other team OR 403 for permission
        $this->assertTrue($response->status() === 403 || $response->status() === 404);
    }

    public function test_role_name_validation_empty(): void
    {
        // Test empty name
        $response = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/roles", [
                'name' => '',
                'color' => 'primary',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_role_name_validation_too_long(): void
    {
        // Test too long name
        $response = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/roles", [
                'name' => str_repeat('a', 256),
                'color' => 'primary',
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['name']);
    }

    public function test_role_level_validation(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/roles", [
                'name' => 'Invalid Level Role',
                'level' => 150, // Should be between 1-99
            ]);

        $response->assertUnprocessable()
            ->assertJsonValidationErrors(['level']);
    }

    public function test_can_search_roles(): void
    {
        // Create additional roles
        $this->team->roles()->create([
            'name' => 'Developer',
            'description' => 'Develops features',
            'color' => 'info',
            'level' => 45,
            'created_by' => $this->owner->id,
        ]);

        $this->team->roles()->create([
            'name' => 'Designer',
            'description' => 'Designs UI',
            'color' => 'success',
            'level' => 44,
            'created_by' => $this->owner->id,
        ]);

        $response = $this->actingAs($this->owner)
            ->getJson("/api/teams/{$this->team->public_id}/roles?search=Developer");

        $response->assertOk();
        $data = $response->json('data');
        $this->assertCount(1, $data);
        $this->assertEquals('Developer', $data[0]['name']);
    }

    public function test_can_filter_custom_roles_only(): void
    {
        // Create a custom role
        $this->team->roles()->create([
            'name' => 'Custom Developer',
            'description' => 'Custom role',
            'color' => 'info',
            'level' => 45,
            'is_system' => false,
            'created_by' => $this->owner->id,
        ]);

        $response = $this->actingAs($this->owner)
            ->getJson("/api/teams/{$this->team->public_id}/roles?custom_only=1");

        $response->assertOk();
        $data = $response->json('data');

        // All returned roles should be non-system
        foreach ($data as $role) {
            $this->assertFalse($role['is_system']);
        }
    }

    public function test_role_permissions_sync(): void
    {
        $role = $this->team->roles()->create([
            'name' => 'Permission Test Role',
            'description' => 'Testing permission sync',
            'color' => 'warning',
            'level' => 50,
            'created_by' => $this->owner->id,
        ]);

        // Add permissions
        $role->syncPermissions(['projects.view', 'projects.create']);

        $this->assertTrue($role->hasPermission('projects.view'));
        $this->assertTrue($role->hasPermission('projects.create'));
        $this->assertFalse($role->hasPermission('projects.delete'));

        // Sync with new permissions
        $role->syncPermissions(['tasks.view', 'tasks.create']);

        $this->assertFalse($role->hasPermission('projects.view'));
        $this->assertTrue($role->hasPermission('tasks.view'));
    }

    public function test_role_level_hierarchy(): void
    {
        $highRole = $this->team->roles()->where('slug', 'owner')->first();
        $lowRole = $this->team->roles()->where('slug', 'member')->first();

        $this->assertTrue($highRole->hasHigherLevelThan($lowRole));
        $this->assertFalse($lowRole->hasHigherLevelThan($highRole));
    }

    public function test_default_role_uniqueness(): void
    {
        // Create a new default role
        $response = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/roles", [
                'name' => 'New Default',
                'color' => 'primary',
                'level' => 40,
                'is_default' => true,
            ]);

        $response->assertCreated();

        // Check that only one default role exists
        $defaultRoles = $this->team->roles()->where('is_default', true)->get();
        $this->assertCount(1, $defaultRoles);
        $this->assertEquals('New Default', $defaultRoles->first()->name);
    }

    public function test_audit_log_on_role_creation(): void
    {
        $response = $this->actingAs($this->owner)
            ->postJson("/api/teams/{$this->team->public_id}/roles", [
                'name' => 'Audit Test Role',
                'color' => 'info',
            ]);

        $response->assertCreated();

        // Check audit log was created
        $this->assertDatabaseHas('audit_logs', [
            'user_id' => $this->owner->id,
            'action' => 'created',
            'category' => 'team_management',
        ]);
    }
}
