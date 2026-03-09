<?php

namespace Tests\Feature;

use App\Services\Chat\PresenceService;
use Mockery\MockInterface;
use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MeetingHostPresenceGateTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

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
    }

    /** @test */
    public function participant_join_enters_waiting_room_when_host_or_co_host_not_in_room_and_gate_is_enabled(): void
    {
        [$meeting] = $this->createMeetingWithHost([
            'require_host_or_cohost_present' => true,
        ]);

        $this->mock(PresenceService::class, function (MockInterface $mock) {
            $mock->shouldReceive('getActiveMeetingParticipantIds')
                ->once()
                ->andReturn([]);
        });

        $response = $this->postJson("/api/meetings/{$meeting->public_id}/join", [
            'name' => 'Guest User',
            'email' => 'guest@example.test',
            'password' => 'PassWord123',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.participant.status', 'waiting');
        $response->assertJsonPath('data.participant.metadata.waiting_reason', 'awaiting_moderator');
    }

    /** @test */
    public function participant_join_is_allowed_when_host_is_active_and_gate_is_enabled(): void
    {
        [$meeting, $hostParticipant] = $this->createMeetingWithHost([
            'require_host_or_cohost_present' => true,
        ]);

        $this->mock(PresenceService::class, function (MockInterface $mock) use ($hostParticipant) {
            $mock->shouldReceive('getActiveMeetingParticipantIds')
                ->once()
                ->andReturn([$hostParticipant->public_id]);
        });

        $response = $this->postJson("/api/meetings/{$meeting->public_id}/join", [
            'name' => 'Guest User',
            'email' => 'guest@example.test',
            'password' => 'PassWord123',
        ]);

        $response->assertOk();
        $response->assertJsonPath('data.participant.status', 'waiting');
        $this->assertNull(data_get($response->json(), 'data.participant.metadata.waiting_reason'));
    }

    /**
     * @param  array<string, mixed>  $extraSettings
     * @return array{Meeting, MeetingParticipant}
     */
    private function createMeetingWithHost(array $extraSettings = []): array
    {
        $host = User::factory()->create();

        $meeting = Meeting::create([
            'public_id' => (string) Str::ulid(),
            'user_id' => $host->id,
            'title' => 'Presence Gate Test',
            'start_time' => now(),
            'status' => 'active',
            'settings' => array_merge([
                'guest_access' => true,
                'lobby_enabled' => true,
            ], $extraSettings),
            'password' => 'PassWord123',
            'app_id' => 'worksphere',
        ]);

        $hostParticipant = MeetingParticipant::create([
            'meeting_id' => $meeting->id,
            'user_id' => $host->id,
            'public_id' => (string) Str::ulid(),
            'role' => 'host',
            'status' => 'admitted',
        ]);

        return [$meeting, $hostParticipant];
    }
}
