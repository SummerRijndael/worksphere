<?php

namespace Tests\Feature;

use App\Models\Chat\Chat;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Cache;
use Illuminate\Support\Facades\Event;
use Illuminate\Support\Str;
use Tests\TestCase;
use App\Events\Chat\CallEnded;
use App\Events\Chat\CallSignal;

class VideoCallControllerTest extends TestCase
{
    use RefreshDatabase;

    protected $user;
    protected $chat;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable firewall middleware that blocks test requests with "Access Denied"
        $this->withoutMiddleware([
            \Akaunting\Firewall\Middleware\Ip::class,
            \Akaunting\Firewall\Middleware\Agent::class,
            \Akaunting\Firewall\Middleware\Bot::class,
            \Akaunting\Firewall\Middleware\Lfi::class,
            \Akaunting\Firewall\Middleware\Php::class,
            \Akaunting\Firewall\Middleware\Referrer::class,
            \Akaunting\Firewall\Middleware\Rfi::class,
            \Akaunting\Firewall\Middleware\Sqli::class,
            \Akaunting\Firewall\Middleware\Xss::class,
            \App\Http\Middleware\CheckUserStatus::class,
            \App\Http\Middleware\CheckImpersonation::class,
        ]);

        // Setup initial user and chat
        $this->user = User::factory()->create();
        $this->chat = Chat::create([
            'public_id' => (string) Str::ulid(),
            'type' => 'dm',
        ]);
        $this->chat->participants()->attach($this->user->id, [
            'public_id' => (string) Str::ulid(),
            'left_at' => null,
        ]);
    }

    /** @test */
    public function test_initiate_creates_call_and_cache_keys()
    {
        Event::fake();
        $this->actingAs($this->user);

        $response = $this->postJson("/api/chat/{$this->chat->public_id}/call/initiate", [
            'call_type' => 'video',
        ]);

        $response->assertOk();
        $callId = $response->json('call_id');

        // Verify ULID format (26 chars, uppercase alphanumeric)
        $this->assertMatchesRegularExpression('/^[0-9A-Z]{26}$/', $callId);

        // Verify active call pointer is cached
        $this->assertEquals($callId, Cache::get("chat:active_call:{$this->chat->public_id}"));

        // Verify participant added to cache
        $participants = Cache::get("call:participants:{$this->chat->public_id}:{$callId}", []);
        $this->assertNotEmpty($participants);
        $this->assertEquals($this->user->public_id, $participants[0]['public_id']);
    }

    /** @test */
    public function test_join_validates_active_call_id()
    {
        Event::fake();
        $this->actingAs($this->user);

        // Case 1: Random ULID that doesn't match any active call → 404
        $randomId = (string) Str::ulid();
        $response = $this->postJson("/api/chat/{$this->chat->public_id}/call/join", [
            'call_id' => $randomId,
        ]);
        $response->assertNotFound();

        // Case 2: Valid active call → 200
        $validCallId = (string) Str::ulid();
        Cache::put("chat:active_call:{$this->chat->public_id}", $validCallId, 60);

        $response = $this->postJson("/api/chat/{$this->chat->public_id}/call/join", [
            'call_id' => $validCallId,
        ]);
        $response->assertOk();
    }

    /** @test */
    public function test_join_returns_mesh_mode_for_two_participants_in_dm()
    {
        Event::fake();

        $user2 = User::factory()->create();
        $this->chat->participants()->attach($user2->id, [
            'public_id' => (string) Str::ulid(),
            'left_at' => null,
        ]);

        $callId = (string) Str::ulid();
        Cache::put("chat:active_call:{$this->chat->public_id}", $callId, 60);

        // Simulate P1 already in call cache
        $p1 = ['public_id' => $this->user->public_id, 'name' => $this->user->name, 'joined_at' => now()->timestamp];
        Cache::put("call:participants:{$this->chat->public_id}:{$callId}", [$p1], 60);

        // P2 joins → count becomes 2 → should get MESH mode in DM
        $this->actingAs($user2);
        $res = $this->postJson("/api/chat/{$this->chat->public_id}/call/join", ['call_id' => $callId]);
        $res->assertOk();
        $res->assertJsonFragment(['mode' => 'mesh']);
    }

    /** @test */
    public function test_join_returns_sfu_mode_for_three_plus_participants()
    {
        Event::fake();

        // P1 and P2 already in call
        $user2 = User::factory()->create();
        $user3 = User::factory()->create();

        $this->chat->participants()->attach($user2->id, [
            'public_id' => (string) Str::ulid(),
            'left_at' => null,
        ]);
        $this->chat->participants()->attach($user3->id, [
            'public_id' => (string) Str::ulid(),
            'left_at' => null,
        ]);
        
        $callId = (string) Str::ulid();
        Cache::put("chat:active_call:{$this->chat->public_id}", $callId, 60);

        $participants = [
            ['public_id' => $this->user->public_id, 'name' => 'P1', 'joined_at' => now()->timestamp],
            ['public_id' => $user2->public_id, 'name' => 'P2', 'joined_at' => now()->timestamp],
        ];
        Cache::put("call:participants:{$this->chat->public_id}:{$callId}", $participants, 60);

        // P3 joins → count becomes 3 → should get SFU mode
        $this->actingAs($user3);
        $res = $this->postJson("/api/chat/{$this->chat->public_id}/call/join", ['call_id' => $callId]);
        $res->assertOk();
        $res->assertJsonFragment(['mode' => 'sfu']);
    }

    /** @test */
    public function test_end_rejects_non_participant()
    {
        Event::fake();

        $callId = (string) Str::ulid();
        Cache::put("chat:active_call:{$this->chat->public_id}", $callId, 60);

        // Case 1: User who is NOT in the chat at all → 404 from findChatOrFail
        $outsider = User::factory()->create();
        $this->actingAs($outsider);
        $this->postJson("/api/chat/{$this->chat->public_id}/call/end", [
            'call_id' => $callId,
        ])->assertNotFound();

        // Case 2: User who IS in the chat but NOT in the call cache → 403
        $this->actingAs($this->user); // chat participant, but not in call cache
        $response = $this->postJson("/api/chat/{$this->chat->public_id}/call/end", [
            'call_id' => $callId,
        ]);
        $response->assertForbidden();
    }

    /** @test */
    public function test_end_does_not_broadcast_call_ended_while_others_remain()
    {
        Event::fake([CallEnded::class, \App\Events\Chat\CallParticipantLeft::class]);

        $callId = (string) Str::ulid();

        // Setup: P1 and P2 in call
        $this->actingAs($this->user);
        $participants = [
            ['public_id' => $this->user->public_id, 'name' => 'P1', 'joined_at' => now()->timestamp],
            ['public_id' => 'other-user-id', 'name' => 'P2', 'joined_at' => now()->timestamp],
        ];
        Cache::put("call:participants:{$this->chat->public_id}:{$callId}", $participants, 60);
        Cache::put("chat:active_call:{$this->chat->public_id}", $callId, 60);

        // P1 leaves → P2 still in call → should NOT broadcast CallEnded
        $this->postJson("/api/chat/{$this->chat->public_id}/call/end", ['call_id' => $callId]);
        Event::assertNotDispatched(CallEnded::class);
    }

    /** @test */
    public function test_end_broadcasts_call_ended_when_last_person_leaves()
    {
        Event::fake([CallEnded::class, \App\Events\Chat\CallParticipantLeft::class]);

        $callId = (string) Str::ulid();

        // Setup: only P1 in call (last person)
        $this->actingAs($this->user);
        $participants = [
            ['public_id' => $this->user->public_id, 'name' => 'P1', 'joined_at' => now()->timestamp],
        ];
        Cache::put("call:participants:{$this->chat->public_id}:{$callId}", $participants, 60);
        Cache::put("chat:active_call:{$this->chat->public_id}", $callId, 60);
        Cache::put("call:meta:{$this->chat->public_id}:{$callId}", ['started_at' => now()->timestamp], 60);

        // P1 leaves → last person → SHOULD broadcast CallEnded
        $this->postJson("/api/chat/{$this->chat->public_id}/call/end", ['call_id' => $callId]);
        Event::assertDispatched(CallEnded::class);
    }

    /** @test */
    public function test_signal_rejects_oversized_sdp()
    {
        Event::fake();
        $this->actingAs($this->user);
        $callId = (string) Str::ulid();

        // SDP exceeds the 20000 char limit → 422
        $this->postJson("/api/chat/{$this->chat->public_id}/call/signal", [
            'call_id' => $callId,
            'signal_type' => 'offer',
            'signal_data' => ['sdp' => str_repeat('a', 20001)],
        ])->assertStatus(422);
    }

    /** @test */
    public function test_signal_rejects_invalid_target_participant()
    {
        Event::fake();
        $this->actingAs($this->user);
        $callId = (string) Str::ulid();

        // Add self to call cache so we pass the auth check
        Cache::put("call:participants:{$this->chat->public_id}:{$callId}", [
            ['public_id' => $this->user->public_id],
        ], 60);

        // Signal to a user who is NOT in the call → 422
        $this->postJson("/api/chat/{$this->chat->public_id}/call/signal", [
            'call_id' => $callId,
            'signal_type' => 'offer',
            'signal_data' => ['type' => 'offer', 'sdp' => 'v=0...'],
            'target_public_id' => 'non-existent-user',
        ])->assertStatus(422);
    }
}
