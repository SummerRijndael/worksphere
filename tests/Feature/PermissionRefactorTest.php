<?php

namespace Tests\Feature;

use App\Enums\AuditAction;
use App\Models\Team;
use App\Models\User;
use App\Services\ImpersonationService;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Config;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class PermissionRefactorTest extends TestCase
{
    use RefreshDatabase;

    protected $permissionService;
    protected $impersonationService;

    protected function setUp(): void
    {
        parent::setUp();
        $this->permissionService = app(PermissionService::class);
        $this->impersonationService = app(ImpersonationService::class);

        // Seed roles and permissions
        $this->seed(\Database\Seeders\RolesAndPermissionsSeeder::class);
    }

    public function test_get_permission_scope_identifies_global_and_team_perms()
    {
        // Assert global permission (from config/roles.php)
        $this->assertEquals('global', $this->permissionService->getPermissionScope('users.view'));

        // Assert team permission
        $this->assertEquals('team', $this->permissionService->getPermissionScope('projects.view'));
    }

    public function test_team_owner_gets_only_team_permissions_via_wildcard()
    {
        $owner = User::factory()->create();
        $team = Team::factory()->create(['owner_id' => $owner->id]);
        $owner->teams()->attach($team, ['role' => 'owner']);

        $permissions = $this->permissionService->getTeamPermissions($owner, $team);

        // Should have team permissions
        $this->assertTrue($permissions->contains('projects.view'));
        $this->assertTrue($permissions->contains('tasks.create'));

        // Should NOT have global permissions
        $this->assertFalse($permissions->contains('users.view'));
        $this->assertFalse($permissions->contains('system.settings'));
    }

    public function test_impersonation_service_starts_and_stops()
    {
        $admin = User::factory()->create();
        $target = User::factory()->create();

        // Start
        $this->impersonationService->impersonate($admin, $target);

        $this->assertTrue($this->impersonationService->isImpersonating());
        $this->assertEquals($target->id, auth()->id());
        $this->assertEquals($admin->id, $this->impersonationService->getImpersonator()->id);

        // Stop
        $this->impersonationService->stopImpersonating();

        $this->assertFalse($this->impersonationService->isImpersonating());
        $this->assertEquals($admin->id, auth()->id());
    }

    public function test_impersonation_prevent_self_impersonation()
    {
        $user = User::factory()->create();

        $this->expectException(\InvalidArgumentException::class);
        $this->impersonationService->impersonate($user, $user);
    }
}
