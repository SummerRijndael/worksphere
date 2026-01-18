<?php

namespace Tests\Feature\Api;

use App\Models\User;
use App\Services\LinkUnfurlService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class LinkUnfurlTest extends TestCase
{
    use RefreshDatabase;

    public function test_api_unfurls_link()
    {
        $user = User::factory()->create();

        $this->mock(LinkUnfurlService::class, function ($mock) {
            $mock->shouldReceive('fetch')
                ->with('https://example.com')
                ->once()
                ->andReturn([
                    'title' => 'Example Domain',
                    'description' => 'Example description',
                    'url' => 'https://example.com',
                ]);
        });

        $response = $this->actingAs($user)
            ->postJson('/api/link/unfurl', ['url' => 'https://example.com']);

        $response->assertStatus(200)
            ->assertJson([
                'title' => 'Example Domain',
                'url' => 'https://example.com',
            ]);
    }

    public function test_api_handles_blocked_link()
    {
        $user = User::factory()->create();

        $this->mock(LinkUnfurlService::class, function ($mock) {
            $mock->shouldReceive('fetch')
                ->with('https://bad.com')
                ->once()
                ->andThrow(new \Exception('unsafe_content_blocked'));
        });

        $response = $this->actingAs($user)
            ->postJson('/api/link/unfurl', ['url' => 'https://bad.com']);

        $response->assertStatus(403)
            ->assertJson(['error' => 'unsafe_content_blocked']);
    }

    public function test_api_requires_valid_url()
    {
        $user = User::factory()->create();

        $response = $this->actingAs($user)
            ->postJson('/api/link/unfurl', ['url' => 'not-a-url']);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['url']);
    }
}
