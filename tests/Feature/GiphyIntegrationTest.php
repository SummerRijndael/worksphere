<?php

namespace Tests\Feature;

use App\Models\User;
use App\Services\Chat\GiphyService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Mockery;
use Tests\TestCase;

class GiphyIntegrationTest extends TestCase
{
    use RefreshDatabase;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();
        $this->user = User::factory()->create();
    }

    /**
     * Test search endpoint calls service and returns data.
     */
    public function test_api_can_search_giphy(): void
    {
        // Mock the service to avoid actual API calls
        $mock = Mockery::mock(GiphyService::class);
        $mock->shouldReceive('search')
            ->once()
            ->with('cat', 20, 0)
            ->andReturn([
                'data' => [
                    ['id' => '123', 'title' => 'Cat GIF', 'images' => ['original' => ['url' => 'http://example.com/cat.gif']]],
                ],
                'pagination' => ['total_count' => 1],
            ]);

        $this->app->instance(GiphyService::class, $mock);

        $response = $this->actingAs($this->user)
            ->getJson('/api/chat/giphy/search?q=cat');

        $response->assertOk()
            ->assertJsonPath('data.0.id', '123');
    }

    /**
     * Test trending endpoint calls service.
     */
    public function test_api_can_get_trending_giphy(): void
    {
        $mock = Mockery::mock(GiphyService::class);
        $mock->shouldReceive('trending')
            ->once()
            ->with(20, 0)
            ->andReturn([
                'data' => [
                    ['id' => '456', 'title' => 'Trend GIF'],
                ],
            ]);

        $this->app->instance(GiphyService::class, $mock);

        $response = $this->actingAs($this->user)
            ->getJson('/api/chat/giphy/trending');

        $response->assertOk()
            ->assertJsonPath('data.0.id', '456');
    }

    /**
     * Test ChatMessage can successfully store metadata.
     */
    public function test_message_can_store_metadata(): void
    {
        $chat = \App\Models\Chat\Chat::create([
            'type' => 'dm',
            'created_by' => $this->user->id,
        ]);

        $message = \App\Models\Chat\ChatMessage::create([
            'chat_id' => $chat->id,
            'user_id' => $this->user->id,
            'content' => '',
            'metadata' => [
                'giphy' => [
                    'id' => 'xyz',
                    'url' => 'http://giphy.com/xyz.gif',
                ],
            ],
        ]);

        $this->assertDatabaseHas('chat_messages', [
            'id' => $message->id,
        ]);

        $message->refresh();
        $message->refresh();
        $this->assertEquals('xyz', $message->metadata['giphy']['id']);
    }

    /**
     * Test sending a message via API with only metadata (no content).
     */
    public function test_can_send_message_with_only_metadata(): void
    {
        $chat = \App\Models\Chat\Chat::create([
            'type' => 'dm',
            'created_by' => $this->user->id,
            'public_id' => (string) \Illuminate\Support\Str::ulid(),
        ]);

        $chat->participants()->attach($this->user->id, [
            'role' => 'owner',
            'public_id' => (string) \Illuminate\Support\Str::uuid(),
        ]);

        $this->assertDatabaseHas('chats', ['id' => $chat->id]);

        $metadata = [
            'giphy' => [
                'id' => 'abc',
                'url' => 'http://example.com/giphy.gif',
                'title' => 'Funny Cat',
                'width' => 100,
                'height' => 100,
            ],
        ];

        $response = $this->actingAs($this->user)
            ->postJson("/api/chat/{$chat->public_id}/send", [
                'content' => '',
                'metadata' => $metadata,
            ]);

        if ($response->status() !== 200) {
            dump($response->getContent());
        }

        $response->assertOk();

        $this->assertDatabaseHas('chat_messages', [
            'chat_id' => $chat->id,
            'user_id' => $this->user->id,
            'content' => '',
        ]);

        $message = \App\Models\Chat\ChatMessage::where('chat_id', $chat->id)->latest()->first();
        $this->assertNotNull($message->metadata);
        $this->assertEquals('abc', $message->metadata['giphy']['id']);
    }
}
