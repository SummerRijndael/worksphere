<?php

namespace Tests\Feature;

use App\Models\AppReview;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Laravel\Sanctum\Sanctum;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class AppReviewTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Reset cached roles and permissions
        $this->app->make(\Spatie\Permission\PermissionRegistrar::class)->forgetCachedPermissions();

        // Create the administrator role
        $adminRole = Role::firstOrCreate(['name' => 'administrator', 'guard_name' => 'web']);

        // Create permissions
        $pModerate = Permission::firstOrCreate(['name' => 'reviews.moderate', 'guard_name' => 'web']);
        $pCreate = Permission::firstOrCreate(['name' => 'reviews.create', 'guard_name' => 'web']);

        // Give permissions to the role
        $adminRole->givePermissionTo($pModerate);

        // Create admin user and assign role
        $this->admin = User::factory()->create();
        $this->admin->assignRole($adminRole);

        // Create regular user and give direct permission
        $this->user = User::factory()->create();
        $this->user->givePermissionTo($pCreate);
    }

    /** @test */
    public function guests_can_view_published_reviews()
    {
        AppReview::factory()->create(['is_published' => true, 'comment' => 'Published Review']);
        AppReview::factory()->create(['is_published' => false, 'comment' => 'Private Review']);

        $response = $this->getJson('/api/public/reviews');

        $response->assertStatus(200)
            ->assertJsonCount(1, 'data')
            ->assertJsonFragment(['comment' => 'Published Review']);
    }

    /** @test */
    public function authenticated_users_can_submit_reviews()
    {
        Sanctum::actingAs($this->user);

        $response = $this->postJson('/api/user/reviews', [
            'rating' => 5,
            'comment' => 'Great app!',
        ]);

        $response->assertStatus(201);

        $review = AppReview::first();
        $this->assertEquals(5, $review->rating);
        $this->assertFalse($review->is_published);
    }

    /** @test */
    public function only_admins_can_see_moderation_list()
    {
        Sanctum::actingAs($this->user);
        $this->getJson('/api/admin/reviews')
            ->assertStatus(403);

        Sanctum::actingAs($this->admin);
        $this->getJson('/api/admin/reviews')
            ->assertStatus(200);
    }

    /** @test */
    public function admins_can_toggle_review_status()
    {
        $review = AppReview::factory()->create(['is_published' => false]);

        Sanctum::actingAs($this->admin);
        $response = $this->putJson("/api/admin/reviews/{$review->public_id}/status", [
            'is_published' => true,
        ]);

        $response->assertStatus(200);
        $this->assertTrue($review->fresh()->is_published);
    }
}
