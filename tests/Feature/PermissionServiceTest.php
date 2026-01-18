<?php

namespace Tests\Feature;

use App\Models\PermissionOverride;
use App\Models\Team;
use App\Models\User;
use App\Services\PermissionService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class PermissionServiceTest extends TestCase
{
    use RefreshDatabase;

    protected PermissionService $permissionService;

    protected User $adminUser;

    protected User $regularUser;

    protected function setUp(): void
    {
        parent::setUp();

        $this->permissionService = app(PermissionService::class);

        // Create test permissions
        Permission::findOrCreate('test.view', 'web');
        Permission::findOrCreate('test.create', 'web');
        Permission::findOrCreate('test.edit', 'web');
        Permission::findOrCreate('test.delete', 'web');

        // Create roles
        $adminRole = Role::findOrCreate('administrator', 'web');
        $userRole = Role::findOrCreate('user', 'web');
        $userRole->givePermissionTo('test.view');

        // Create users
        $this->adminUser = User::factory()->create();
        $this->adminUser->assignRole('administrator');

        $this->regularUser = User::factory()->create();
        $this->regularUser->assignRole('user');
    }

    public function test_can_grant_permission_override(): void
    {
        $override = $this->permissionService->grantOverride(
            $this->regularUser,
            'test.create',
            'User needs access for project',
            $this->adminUser
        );

        $this->assertInstanceOf(PermissionOverride::class, $override);
        $this->assertEquals('grant', $override->type);
        $this->assertEquals('test.create', $override->permission);
        $this->assertEquals($this->regularUser->id, $override->user_id);
        $this->assertEquals($this->adminUser->id, $override->granted_by);
    }

    public function test_can_block_permission_override(): void
    {
        $override = $this->permissionService->blockOverride(
            $this->regularUser,
            'test.view',
            'Access temporarily revoked for investigation',
            $this->adminUser
        );

        $this->assertInstanceOf(PermissionOverride::class, $override);
        $this->assertEquals('block', $override->type);
        $this->assertEquals('test.view', $override->permission);
    }

    public function test_has_permission_with_overrides_respects_blocks(): void
    {
        // User has test.view from role
        $this->assertTrue($this->permissionService->hasPermission($this->regularUser, 'test.view'));

        // Block the permission
        $this->permissionService->blockOverride(
            $this->regularUser,
            'test.view',
            'Blocked for testing',
            $this->adminUser
        );

        // Now user should not have permission via override check
        $this->assertFalse(
            $this->permissionService->hasPermissionWithOverrides($this->regularUser, 'test.view')
        );
    }

    public function test_has_permission_with_overrides_respects_grants(): void
    {
        // User doesn't have test.create from role
        $this->assertFalse($this->permissionService->hasPermission($this->regularUser, 'test.create'));

        // Grant the permission
        $this->permissionService->grantOverride(
            $this->regularUser,
            'test.create',
            'Granted for testing',
            $this->adminUser
        );

        // Now user should have permission via override check
        $this->assertTrue(
            $this->permissionService->hasPermissionWithOverrides($this->regularUser, 'test.create')
        );
    }

    public function test_can_revoke_override(): void
    {
        $override = $this->permissionService->grantOverride(
            $this->regularUser,
            'test.edit',
            'Granted for testing',
            $this->adminUser
        );

        $this->assertTrue($override->isActive());

        $this->permissionService->revokeOverride(
            $override,
            'No longer needed',
            $this->adminUser
        );

        $override->refresh();
        $this->assertFalse($override->isActive());
        $this->assertNotNull($override->revoked_at);
    }

    public function test_temporary_permission_expires(): void
    {
        $override = $this->permissionService->grantOverride(
            $this->regularUser,
            'test.delete',
            'Temporary access for 1 hour',
            $this->adminUser,
            [
                'is_temporary' => true,
                'expires_at' => now()->subHour(), // Already expired
                'expiry_behavior' => 'auto_revoke',
            ]
        );

        $this->assertTrue($override->isExpired());
        $this->assertFalse($override->isActive());
    }

    public function test_can_renew_temporary_permission(): void
    {
        $override = $this->permissionService->grantOverride(
            $this->regularUser,
            'test.edit',
            'Temporary access',
            $this->adminUser,
            [
                'is_temporary' => true,
                'expires_at' => now()->addDay(),
                'expiry_behavior' => 'auto_revoke',
            ]
        );

        $newExpiry = now()->addWeek();

        $this->permissionService->renewTemporaryPermission(
            $override,
            $newExpiry,
            $this->adminUser
        );

        $override->refresh();
        $this->assertTrue($override->expires_at->isSameDay($newExpiry));
    }

    public function test_process_expired_permissions(): void
    {
        // Create expired permission
        $override = PermissionOverride::create([
            'user_id' => $this->regularUser->id,
            'permission' => 'test.view',
            'type' => 'grant',
            'scope' => 'global',
            'is_temporary' => true,
            'expires_at' => now()->subDay(),
            'expiry_behavior' => 'auto_revoke',
            'reason' => 'Test expired',
            'granted_by' => $this->adminUser->id,
            'approved_at' => now()->subWeek(),
        ]);

        $count = $this->permissionService->processExpiredPermissions();

        $this->assertEquals(1, $count);
        $override->refresh();
        $this->assertNotNull($override->revoked_at);
    }

    public function test_get_effective_permissions(): void
    {
        // Grant an additional permission
        $this->permissionService->grantOverride(
            $this->regularUser,
            'test.create',
            'Additional access',
            $this->adminUser
        );

        // Block an existing permission
        $this->permissionService->blockOverride(
            $this->regularUser,
            'test.view',
            'Blocked',
            $this->adminUser
        );

        $effective = $this->permissionService->getEffectivePermissions($this->regularUser);

        $this->assertContains('test.create', $effective['granted']);
        $this->assertContains('test.view', $effective['blocked']);
    }

    public function test_super_admin_bypasses_blocks(): void
    {
        // Block permission for admin
        $this->permissionService->blockOverride(
            $this->adminUser,
            'test.view',
            'Should be bypassed',
            $this->adminUser
        );

        // Super admin should still have access
        $this->assertTrue(
            $this->permissionService->hasPermissionWithOverrides($this->adminUser, 'test.view')
        );
    }

    public function test_team_scoped_override(): void
    {
        $team = Team::factory()->create([
            'name' => 'Test Team',
            'owner_id' => $this->adminUser->id,
        ]);

        $override = $this->permissionService->grantOverride(
            $this->regularUser,
            'team.manage',
            'Team admin access',
            $this->adminUser,
            [
                'scope' => 'team',
                'team_id' => $team->id,
            ]
        );

        $this->assertEquals('team', $override->scope);
        $this->assertEquals($team->id, $override->team_id);
        $this->assertTrue($override->isTeamScoped());
    }
}
