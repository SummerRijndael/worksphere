<?php

namespace Tests\Feature;

use App\Models\Team;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AuthorizationPersonaTest extends TestCase
{
    use RefreshDatabase;

    protected PermissionService $permissionService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->permissionService = app(PermissionService::class);

        // Ensure roles exist
        Role::findOrCreate('administrator', 'web');
        Role::findOrCreate('user', 'web');

        // Create a global permission
        Permission::findOrCreate('system.settings', 'web');
        // Create a team permission
        Permission::findOrCreate('projects.view', 'web');
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function administrator_persona_has_all_permissions_regardless_of_scope()
    {
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $persona = $this->permissionService->getPersona($admin);

        $this->assertTrue($persona->isSuperAdmin);
        // Admin should have global permissions even if not assigned
        $this->assertTrue($persona->hasPermission('system.settings'));

        // Admin should have team permissions even without being in a team
        $this->assertTrue($persona->hasTeamPermission(999, 'projects.view'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function regular_user_persona_is_correctly_scoped()
    {
        $user = User::factory()->create();
        $user->assignRole('user');

        // Assign global permission to the 'user' role
        $userRole = Role::findByName('user');
        $userRole->givePermissionTo('system.settings');

        $persona = $this->permissionService->getPersona($user);

        $this->assertFalse($persona->isSuperAdmin);
        // User should have the global permission assigned to their role
        $this->assertTrue($persona->hasPermission('system.settings'));

        // User should NOT have team permissions for a team they aren't in
        $this->assertFalse($persona->hasTeamPermission(1, 'projects.view'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function persona_respects_manual_overrides()
    {
        $user = User::factory()->create();
        $user->assignRole('user');

        // Create a block override for a permission the user might otherwise have (if role had it)
        $admin = User::factory()->create();
        $admin->assignRole('administrator');

        $this->permissionService->blockOverride($user, 'system.settings', 'Testing block', $admin);

        $persona = $this->permissionService->getPersona($user);

        $this->assertTrue(in_array('system.settings', $persona->overrides['blocked']));
        $this->assertFalse($persona->hasPermission('system.settings'));

        // Create a grant override
        $this->permissionService->grantOverride($user, 'secret.access', 'Testing grant', $admin);

        // Need to refetch persona or clear cache
        $this->permissionService->invalidateUserPermissionCache($user);
        $persona = $this->permissionService->getPersona($user);

        $this->assertTrue(in_array('secret.access', $persona->overrides['granted']));
        $this->assertTrue($persona->hasPermission('secret.access'));
    }

    #[\PHPUnit\Framework\Attributes\Test]
    public function persona_correctly_identifies_team_membership_and_permissions()
    {
        $user = User::factory()->create();
        $user->assignRole('user');

        $team = Team::factory()->create(['owner_id' => $user->id]);
        $team->members()->attach($user->id, ['role' => 'lead', 'joined_at' => now()]);

        $this->permissionService->grantTeamPermission($user, $team, 'projects.view');

        // Refresh user relation
        $user->unsetRelation('teams');

        $persona = $this->permissionService->getPersona($user);

        $this->assertTrue($persona->hasTeamPermission($team->id, 'projects.view'));
        $this->assertFalse($persona->hasTeamPermission($team->id, 'projects.delete'));
    }
}
