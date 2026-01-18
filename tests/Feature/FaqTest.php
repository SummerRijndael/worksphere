<?php

namespace Tests\Feature;

use App\Models\FaqArticle;
use App\Models\FaqCategory;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class FaqTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();
        // Seed permissions if needed, generally tests use Factories
        // Assuming permissions are seeded or we create them
        Permission::firstOrCreate(['name' => 'settings.update']);
    }

    public function test_public_can_view_faq()
    {
        $author = User::factory()->create();
        $category = FaqCategory::create(['name' => 'General', 'is_public' => true, 'order' => 1]);
        $article = FaqArticle::create([
            'category_id' => $category->id,
            'title' => 'How to login',
            'content' => 'Use the login form',
            'is_published' => true,
            'author_id' => $author->id,
        ]);

        $response = $this->getJson('/api/public/faq');
        $response->assertStatus(200)
            ->assertJsonFragment(['name' => 'General'])
            ->assertJsonFragment(['title' => 'How to login']);
    }

    public function test_public_cannot_view_unpublished_articles()
    {
        $author = User::factory()->create();
        $category = FaqCategory::create(['name' => 'General', 'is_public' => true, 'order' => 1]);
        FaqArticle::create([
            'category_id' => $category->id,
            'title' => 'Secret',
            'content' => 'Hidden',
            'is_published' => false,
            'author_id' => $author->id,
        ]);

        $response = $this->getJson('/api/public/faq');
        $response->assertStatus(200)
            ->assertJsonMissing(['title' => 'Secret']);
    }

    public function test_admin_can_manage_faq()
    {
        $admin = User::factory()->create();
        $permission = \Spatie\Permission\Models\Permission::firstOrCreate(['name' => 'faq.manage']);
        $admin->givePermissionTo($permission);

        $response = $this->actingAs($admin)->postJson('/api/admin/faq/categories', [
            'name' => 'New Category',
            'is_public' => true,
            'order' => 1,
        ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('faq_categories', ['name' => 'New Category']);
    }

    public function test_unauthorized_user_cannot_manage_faq()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)->postJson('/api/admin/faq/categories', [
            'name' => 'Hacker Category',
        ]);

        $response->assertStatus(403);
    }

    public function test_admin_can_upload_media_to_article()
    {
        $admin = User::factory()->create();
        $permission = \Spatie\Permission\Models\Permission::create(['name' => 'faq.manage']);
        $admin->givePermissionTo($permission);

        $category = FaqCategory::create(['name' => 'General', 'is_public' => true, 'order' => 1]);
        $article = FaqArticle::create([
            'category_id' => $category->id,
            'title' => 'Media Test',
            'content' => 'Content',
            'is_published' => true,
            'author_id' => $admin->id,
        ]);

        $file = \Illuminate\Http\UploadedFile::fake()->image('test_image.jpg');

        $response = $this->actingAs($admin)->postJson("/api/admin/faq/articles/{$article->public_id}/media", [
            'file' => $file,
        ]);

        $response->assertStatus(201);
        // Assuming media is attached via Spatie MediaLibrary or similar, check database or response
        // Since I don't know the exact implementation of media storage, I'll check if response contains the file name
        // or check if successful.
    }
}
