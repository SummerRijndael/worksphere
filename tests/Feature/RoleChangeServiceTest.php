<?php

namespace Tests\Feature;

use App\Models\RoleChangeApproval;
use App\Models\RoleChangeRequest;
use App\Models\User;
use App\Services\RoleChangeService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class RoleChangeServiceTest extends TestCase
{
    use RefreshDatabase;

    protected RoleChangeService $roleChangeService;

    protected User $admin1;

    protected User $admin2;

    protected User $admin3;

    protected Role $testRole;

    protected function setUp(): void
    {
        parent::setUp();

        $this->roleChangeService = app(RoleChangeService::class);

        // Create admin role
        $adminRole = Role::findOrCreate('administrator', 'web');

        // Create test permissions
        Permission::findOrCreate('test.view', 'web');
        Permission::findOrCreate('test.create', 'web');

        // Create test role
        $this->testRole = Role::findOrCreate('test-role', 'web');
        $this->testRole->givePermissionTo('test.view');

        // Create admin users
        $this->admin1 = User::factory()->create(['password' => bcrypt('password123')]);
        $this->admin1->assignRole('administrator');

        $this->admin2 = User::factory()->create(['password' => bcrypt('password123')]);
        $this->admin2->assignRole('administrator');

        $this->admin3 = User::factory()->create(['password' => bcrypt('password123')]);
        $this->admin3->assignRole('administrator');
    }

    public function test_can_create_role_title_change_request(): void
    {
        $request = $this->roleChangeService->requestRoleTitleChange(
            $this->testRole,
            'new-test-role',
            'Renaming for clarity',
            $this->admin1
        );

        $this->assertInstanceOf(RoleChangeRequest::class, $request);
        $this->assertEquals('role_title_change', $request->type);
        $this->assertEquals('pending', $request->status);
        $this->assertEquals($this->testRole->id, $request->target_role_id);
    }

    public function test_can_create_role_permission_change_request(): void
    {
        $request = $this->roleChangeService->requestRolePermissionChange(
            $this->testRole,
            ['test.view', 'test.create'],
            'Adding create permission',
            $this->admin1
        );

        $this->assertInstanceOf(RoleChangeRequest::class, $request);
        $this->assertEquals('role_permission_change', $request->type);
        $this->assertContains('test.create', $request->requested_changes['new_permissions']);
    }

    public function test_can_approve_request(): void
    {
        $request = $this->roleChangeService->requestRoleTitleChange(
            $this->testRole,
            'renamed-role',
            'Test rename',
            $this->admin1
        );

        $approval = $this->roleChangeService->approveRequest(
            $request,
            $this->admin2,
            'password123',
            'Looks good to me'
        );

        $this->assertInstanceOf(RoleChangeApproval::class, $approval);
        $this->assertEquals('approve', $approval->action);
        $this->assertEquals($this->admin2->id, $approval->admin_id);
    }

    public function test_cannot_approve_own_request(): void
    {
        $request = $this->roleChangeService->requestRoleTitleChange(
            $this->testRole,
            'renamed-role',
            'Test rename',
            $this->admin1
        );

        $this->expectException(\InvalidArgumentException::class);

        $this->roleChangeService->approveRequest(
            $request,
            $this->admin1, // Same admin who created request
            'password123'
        );
    }

    public function test_cannot_approve_with_wrong_password(): void
    {
        $request = $this->roleChangeService->requestRoleTitleChange(
            $this->testRole,
            'renamed-role',
            'Test rename',
            $this->admin1
        );

        $this->expectException(\InvalidArgumentException::class);
        $this->expectExceptionMessage('Invalid password');

        $this->roleChangeService->approveRequest(
            $request,
            $this->admin2,
            'wrong-password'
        );
    }

    public function test_request_applies_after_full_approval(): void
    {
        // Set required approvals to 2
        config(['roles.role_change_approval_count' => 2]);

        $request = $this->roleChangeService->requestRoleTitleChange(
            $this->testRole,
            'fully-approved-role',
            'Test full approval',
            $this->admin1
        );

        // First approval
        $this->roleChangeService->approveRequest($request, $this->admin2, 'password123');
        $request->refresh();
        $this->assertEquals('pending', $request->status);

        // Second approval - should trigger apply
        $this->roleChangeService->approveRequest($request, $this->admin3, 'password123');
        $request->refresh();

        $this->assertEquals('approved', $request->status);
        $this->assertNotNull($request->completed_at);

        // Verify the role was actually renamed
        $this->testRole->refresh();
        $this->assertEquals('fully-approved-role', $this->testRole->name);
    }

    public function test_can_reject_request(): void
    {
        $request = $this->roleChangeService->requestRoleTitleChange(
            $this->testRole,
            'bad-name',
            'Bad idea',
            $this->admin1
        );

        $this->roleChangeService->rejectRequest(
            $request,
            $this->admin2,
            'password123',
            'This name is inappropriate'
        );

        $request->refresh();
        $this->assertEquals('rejected', $request->status);
        $this->assertNotNull($request->completed_at);
    }

    public function test_cannot_approve_twice(): void
    {
        $request = $this->roleChangeService->requestRoleTitleChange(
            $this->testRole,
            'renamed-role',
            'Test',
            $this->admin1
        );

        // First approval
        $this->roleChangeService->approveRequest($request, $this->admin2, 'password123');

        // Try to approve again
        $this->expectException(\InvalidArgumentException::class);

        $this->roleChangeService->approveRequest($request, $this->admin2, 'password123');
    }

    public function test_can_get_pending_requests(): void
    {
        // Create a few requests
        $this->roleChangeService->requestRoleTitleChange($this->testRole, 'name1', 'Test1', $this->admin1);
        $this->roleChangeService->requestRoleTitleChange($this->testRole, 'name2', 'Test2', $this->admin2);

        $pending = $this->roleChangeService->getPendingRequests();

        $this->assertCount(2, $pending);
    }

    public function test_expire_old_requests(): void
    {
        // Create an expired request
        $request = RoleChangeRequest::create([
            'type' => 'role_title_change',
            'target_role_id' => $this->testRole->id,
            'requested_changes' => ['old_name' => 'test', 'new_name' => 'expired'],
            'reason' => 'Expired test',
            'requested_by' => $this->admin1->id,
            'required_approvals' => 2,
            'expires_at' => now()->subDay(),
        ]);

        $count = $this->roleChangeService->expireOldRequests();

        $this->assertEquals(1, $count);
        $request->refresh();
        $this->assertEquals('expired', $request->status);
    }

    public function test_can_request_role_creation(): void
    {
        $request = $this->roleChangeService->requestRoleCreate(
            [
                'name' => 'new-custom-role',
                'permissions' => ['test.view'],
            ],
            'Need a new role for the team',
            $this->admin1
        );

        $this->assertEquals('role_create', $request->type);
        $this->assertNull($request->target_role_id);
        $this->assertEquals('new-custom-role', $request->requested_changes['name']);
    }

    public function test_can_request_role_deletion(): void
    {
        $request = $this->roleChangeService->requestRoleDelete(
            $this->testRole,
            'Role is no longer needed',
            $this->admin1
        );

        $this->assertEquals('role_delete', $request->type);
        $this->assertEquals($this->testRole->id, $request->target_role_id);
    }
}
