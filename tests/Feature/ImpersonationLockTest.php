<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\ImpersonationService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Session;
use Tests\TestCase;

class ImpersonationLockTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;
    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        
        // Create roles safely
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'administrator', 'guard_name' => 'web']);
        \Spatie\Permission\Models\Role::firstOrCreate(['name' => 'user', 'guard_name' => 'web']);

        $this->admin = User::factory()->create();
        $this->admin->assignRole('administrator');
        
        $this->user = User::factory()->create();
        $this->user->assignRole('user');
    }

    /** @test */
    public function it_blocks_sensitive_routes_during_impersonation()
    {
        $this->actingAs($this->admin);

        // Start impersonation
        $impersonationService = app(ImpersonationService::class);
        $impersonationService->impersonate($this->admin, $this->user);

        // Verify session has impersonator_id
        $this->assertTrue($impersonationService->isImpersonating());

        // Test Email Route
        $response = $this->getJson('/api/emails/folders');
        $response->assertStatus(403)
            ->assertJson(['code' => 'feature_locked_impersonation']);

        // Test Chat Route
        $response = $this->getJson('/api/chat');
        $response->assertStatus(403)
            ->assertJson(['code' => 'feature_locked_impersonation']);

        // Test Calendar Route
        $response = $this->getJson('/api/calendar/events');
        $response->assertStatus(403)
            ->assertJson(['code' => 'feature_locked_impersonation']);
    }

    /** @test */
    public function it_allows_access_when_not_impersonating()
    {
        $this->actingAs($this->user);

        // Verify not impersonating
        $impersonationService = app(ImpersonationService::class);
        $this->assertFalse($impersonationService->isImpersonating());

        // Test Email Route (might 401 if no accounts, but shouldn't be 403 locked)
        $response = $this->getJson('/api/emails/folders');
        $response->assertStatus(200);

        // Test Chat Route
        $response = $this->getJson('/api/chat');
        $response->assertStatus(200);

        // Test Calendar Route
        $response = $this->getJson('/api/calendar/events?start=' . now()->startOfMonth()->toDateTimeString() . '&end=' . now()->endOfMonth()->toDateTimeString());
        $response->assertStatus(200);
    }
}
