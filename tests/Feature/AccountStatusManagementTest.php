<?php

namespace Tests\Feature;

use App\Events\TwoFactorEnforcementChanged;
use App\Events\UserRoleChanged;
use App\Events\UserStatusChanged;
use App\Models\RoleTwoFactorEnforcement;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Facades\Hash;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AccountStatusManagementTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $targetUser;

    protected function setUp(): void
    {
        parent::setUp();

        // Setup permissions matching the routes
        $adminRole = Role::create(['name' => 'administrator', 'guard_name' => 'web']);
        $userRole = Role::create(['name' => 'user', 'guard_name' => 'web']);
        $projectManagerRole = Role::create(['name' => 'project_manager', 'guard_name' => 'web']);

        // Create permissions matching the route middleware
        Permission::create(['name' => 'user_manage', 'guard_name' => 'web']);
        Permission::create(['name' => 'users.view', 'guard_name' => 'web']);
        Permission::create(['name' => 'users.update', 'guard_name' => 'web']);
        Permission::create(['name' => 'users.manage_status', 'guard_name' => 'web']);
        Permission::create(['name' => 'users.manage_roles', 'guard_name' => 'web']);

        $adminRole->givePermissionTo([
            'user_manage',
            'users.view',
            'users.update',
            'users.manage_status',
            'users.manage_roles',
        ]);

        // Create admin and target user
        $this->admin = User::factory()->create([
            'password' => Hash::make('admin-password'),
        ]);
        $this->admin->assignRole('administrator');

        $this->targetUser = User::factory()->create([
            'status' => 'active',
        ]);
        $this->targetUser->assignRole('user');
    }

    // ==================== STATUS CHANGE TESTS ====================

    public function test_admin_can_block_user_with_reason(): void
    {
        Event::fake([UserStatusChanged::class]);

        $response = $this->actingAs($this->admin)
            ->putJson("/api/users/{$this->targetUser->public_id}/status", [
                'status' => 'blocked',
                'reason' => 'Violation of terms of service',
            ]);

        $response->assertStatus(200);

        $this->targetUser->refresh();
        $this->assertEquals('blocked', $this->targetUser->status);
        $this->assertEquals('Violation of terms of service', $this->targetUser->status_reason);

        Event::assertDispatched(UserStatusChanged::class, function ($event) {
            return $event->user->id === $this->targetUser->id &&
                   $event->newStatus === 'blocked';
        });
    }

    public function test_admin_can_suspend_user_with_date(): void
    {
        Event::fake([UserStatusChanged::class]);

        $suspendUntil = now()->addDays(7)->toDateTimeString();

        $response = $this->actingAs($this->admin)
            ->putJson("/api/users/{$this->targetUser->public_id}/status", [
                'status' => 'suspended',
                'reason' => 'Temporary suspension for review',
                'suspended_until' => $suspendUntil,
            ]);

        $response->assertStatus(200);

        $this->targetUser->refresh();
        $this->assertEquals('suspended', $this->targetUser->status);
        $this->assertNotNull($this->targetUser->suspended_until);

        Event::assertDispatched(UserStatusChanged::class);
    }

    public function test_blocking_user_requires_reason(): void
    {
        $response = $this->actingAs($this->admin)
            ->putJson("/api/users/{$this->targetUser->public_id}/status", [
                'status' => 'blocked',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_suspension_requires_end_date(): void
    {
        $response = $this->actingAs($this->admin)
            ->putJson("/api/users/{$this->targetUser->public_id}/status", [
                'status' => 'suspended',
                'reason' => 'Test suspension',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['suspended_until']);
    }

    public function test_reason_minimum_length_validation(): void
    {
        $response = $this->actingAs($this->admin)
            ->putJson("/api/users/{$this->targetUser->public_id}/status", [
                'status' => 'blocked',
                'reason' => 'short', // Less than 10 characters
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_admin_can_reactivate_blocked_user(): void
    {
        $this->targetUser->update(['status' => 'blocked', 'status_reason' => 'Test']);

        $response = $this->actingAs($this->admin)
            ->putJson("/api/users/{$this->targetUser->public_id}/status", [
                'status' => 'active',
            ]);

        $response->assertStatus(200);

        $this->targetUser->refresh();
        $this->assertEquals('active', $this->targetUser->status);
        $this->assertNull($this->targetUser->status_reason);
    }

    public function test_non_admin_cannot_change_user_status(): void
    {
        $regularUser = User::factory()->create();
        $regularUser->assignRole('user');

        $response = $this->actingAs($regularUser)
            ->putJson("/api/users/{$this->targetUser->public_id}/status", [
                'status' => 'blocked',
                'reason' => 'Attempting unauthorized action',
            ]);

        $response->assertStatus(403);
    }

    public function test_status_history_endpoint_is_accessible(): void
    {
        // Test that the endpoint is accessible and returns proper structure
        $response = $this->actingAs($this->admin)
            ->getJson("/api/users/{$this->targetUser->public_id}/status-history");

        $response->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    // ==================== ROLE CHANGE TESTS ====================

    public function test_admin_can_change_user_role_with_password_confirmation(): void
    {
        Event::fake([UserRoleChanged::class]);

        $response = $this->actingAs($this->admin)
            ->putJson("/api/users/{$this->targetUser->public_id}/role", [
                'role' => 'project_manager',
                'reason' => 'Promoted to project manager',
                'password' => 'admin-password',
            ]);

        $response->assertStatus(200);

        $this->targetUser->refresh();
        $this->assertTrue($this->targetUser->hasRole('project_manager'));
        $this->assertFalse($this->targetUser->hasRole('user'));

        Event::assertDispatched(UserRoleChanged::class, function ($event) {
            return $event->user->id === $this->targetUser->id &&
                   $event->fromRole === 'user' &&
                   $event->toRole === 'project_manager';
        });
    }

    public function test_role_change_requires_password_confirmation(): void
    {
        $response = $this->actingAs($this->admin)
            ->putJson("/api/users/{$this->targetUser->public_id}/role", [
                'role' => 'project_manager',
                'reason' => 'Promotion',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_role_change_fails_with_wrong_password(): void
    {
        $response = $this->actingAs($this->admin)
            ->putJson("/api/users/{$this->targetUser->public_id}/role", [
                'role' => 'project_manager',
                'reason' => 'Promotion',
                'password' => 'wrong-password',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['password']);
    }

    public function test_role_change_requires_reason(): void
    {
        $response = $this->actingAs($this->admin)
            ->putJson("/api/users/{$this->targetUser->public_id}/role", [
                'role' => 'project_manager',
                'password' => 'admin-password',
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['reason']);
    }

    public function test_role_change_history_endpoint_is_accessible(): void
    {
        // Test that the endpoint is accessible and returns proper structure
        $response = $this->actingAs($this->admin)
            ->getJson("/api/users/{$this->targetUser->public_id}/role-history");

        $response->assertStatus(200)
            ->assertJsonStructure(['data']);
    }

    // ==================== 2FA ENFORCEMENT TESTS ====================

    public function test_admin_can_enforce_2fa_for_user(): void
    {
        Event::fake([TwoFactorEnforcementChanged::class]);

        $response = $this->actingAs($this->admin)
            ->postJson('/api/admin/2fa-enforcement', [
                'target_type' => 'user',
                'target_id' => $this->targetUser->public_id,
                'allowed_methods' => ['totp', 'sms'],
                'enforce' => true,
            ]);

        $response->assertStatus(200);

        $this->targetUser->refresh();
        $this->assertTrue($this->targetUser->two_factor_enforced);
        $this->assertEquals(['totp', 'sms'], $this->targetUser->two_factor_allowed_methods);

        Event::assertDispatched(TwoFactorEnforcementChanged::class, function ($event) {
            return $event->user->id === $this->targetUser->id &&
                   $event->enforced === true;
        });
    }

    public function test_admin_can_enforce_2fa_for_role(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/admin/2fa-enforcement', [
                'target_type' => 'role',
                'target_id' => 'user', // Role name, not ID
                'allowed_methods' => ['totp'],
                'enforce' => true,
            ]);

        $response->assertStatus(200);

        $role = Role::where('name', 'user')->first();
        $enforcement = RoleTwoFactorEnforcement::where('role_id', $role->id)->first();
        $this->assertNotNull($enforcement);
        $this->assertTrue($enforcement->is_active);
        $this->assertEquals(['totp'], $enforcement->allowed_methods);
    }

    public function test_2fa_enforcement_requires_at_least_one_method(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/admin/2fa-enforcement', [
                'target_type' => 'user',
                'target_id' => $this->targetUser->public_id,
                'allowed_methods' => [],
                'enforce' => true,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['allowed_methods']);
    }

    public function test_2fa_enforcement_validates_method_names(): void
    {
        $response = $this->actingAs($this->admin)
            ->postJson('/api/admin/2fa-enforcement', [
                'target_type' => 'user',
                'target_id' => $this->targetUser->public_id,
                'allowed_methods' => ['invalid_method'],
                'enforce' => true,
            ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['allowed_methods.0']);
    }

    public function test_admin_can_remove_user_2fa_enforcement(): void
    {
        // First enforce 2FA
        $this->targetUser->update([
            'two_factor_enforced' => true,
            'two_factor_allowed_methods' => ['totp'],
            'two_factor_enforced_by' => $this->admin->id,
            'two_factor_enforced_at' => now(),
        ]);

        // Remove enforcement by setting enforce to false
        $response = $this->actingAs($this->admin)
            ->postJson('/api/admin/2fa-enforcement', [
                'target_type' => 'user',
                'target_id' => $this->targetUser->public_id,
                'enforce' => false,
            ]);

        $response->assertStatus(200);

        $this->targetUser->refresh();
        $this->assertFalse($this->targetUser->two_factor_enforced);
    }

    public function test_admin_can_remove_role_2fa_enforcement(): void
    {
        $role = Role::where('name', 'user')->first();

        // First create enforcement
        RoleTwoFactorEnforcement::create([
            'role_id' => $role->id,
            'allowed_methods' => ['totp'],
            'is_active' => true,
            'enforced_by' => $this->admin->id,
        ]);

        // Remove enforcement
        $response = $this->actingAs($this->admin)
            ->postJson('/api/admin/2fa-enforcement', [
                'target_type' => 'role',
                'target_id' => 'user',
                'enforce' => false,
            ]);

        $response->assertStatus(200);

        $this->assertDatabaseMissing('role_two_factor_enforcement', [
            'role_id' => $role->id,
        ]);
    }

    public function test_user_requires_2fa_setup_returns_correct_structure(): void
    {
        $this->targetUser->update([
            'two_factor_enforced' => true,
            'two_factor_allowed_methods' => ['totp', 'passkey'],
            'two_factor_confirmed_at' => null, // No 2FA configured
        ]);

        $result = $this->targetUser->requires2FASetup();

        $this->assertIsArray($result);
        $this->assertTrue($result['required']);
        $this->assertEquals(['totp', 'passkey'], $result['methods']);
        $this->assertEquals('user', $result['source']);
    }

    public function test_user_with_2fa_configured_does_not_require_setup(): void
    {
        // User has TOTP configured (both secret and confirmed_at are set)
        // Use forceFill to bypass mass assignment protection
        $this->targetUser->forceFill([
            'two_factor_enforced' => true,
            'two_factor_allowed_methods' => ['totp'],
            'two_factor_secret' => encrypt('TESTSECRETKEY123456'),
            'two_factor_confirmed_at' => now(),
        ])->save();

        // Refresh to ensure the model has the updated values
        $this->targetUser->refresh();

        // Verify the values are set correctly
        $this->assertNotNull($this->targetUser->two_factor_secret);
        $this->assertNotNull($this->targetUser->two_factor_confirmed_at);

        $result = $this->targetUser->requires2FASetup();

        $this->assertFalse($result);
    }

    public function test_role_enforcement_list_endpoint(): void
    {
        $role = Role::where('name', 'user')->first();

        RoleTwoFactorEnforcement::create([
            'role_id' => $role->id,
            'allowed_methods' => ['totp'],
            'is_active' => true,
            'enforced_by' => $this->admin->id,
        ]);

        $response = $this->actingAs($this->admin)
            ->getJson('/api/admin/2fa-enforcement/roles');

        $response->assertStatus(200)
            ->assertJsonStructure([
                'enforcements' => [
                    '*' => ['id', 'role_id', 'allowed_methods', 'is_active', 'role'],
                ],
            ]);
    }

    // ==================== PERMISSION TESTS ====================

    public function test_non_admin_cannot_enforce_2fa(): void
    {
        $regularUser = User::factory()->create();
        $regularUser->assignRole('user');

        $response = $this->actingAs($regularUser)
            ->postJson('/api/admin/2fa-enforcement', [
                'target_type' => 'user',
                'target_id' => $this->targetUser->public_id,
                'allowed_methods' => ['totp'],
                'enforce' => true,
            ]);

        $response->assertStatus(403);
    }

    // ==================== BROADCASTING TESTS ====================

    public function test_status_changed_event_broadcasts_to_correct_channel(): void
    {
        $event = new UserStatusChanged(
            $this->targetUser,
            'blocked',
            'Test reason',
            null,
            $this->admin
        );

        $channel = $event->broadcastOn()[0];

        $this->assertEquals("private-App.Models.User.{$this->targetUser->public_id}", $channel->name);
    }

    public function test_role_changed_event_broadcasts_to_correct_channel(): void
    {
        $event = new UserRoleChanged(
            $this->targetUser,
            'user',
            'administrator',
            'Promotion',
            $this->admin
        );

        $channel = $event->broadcastOn()[0];

        $this->assertEquals("private-App.Models.User.{$this->targetUser->public_id}", $channel->name);
    }

    public function test_2fa_enforcement_event_broadcasts_to_correct_channel(): void
    {
        $event = new TwoFactorEnforcementChanged(
            $this->targetUser,
            true,
            ['totp'],
            $this->admin
        );

        $channel = $event->broadcastOn()[0];

        $this->assertEquals("private-App.Models.User.{$this->targetUser->public_id}", $channel->name);
    }
}
