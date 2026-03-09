<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\MeetingParticipant;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MeetingLobbyAccessTest extends TestCase
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
    public function guest_waiting_participant_can_fetch_its_own_participant_record_from_meeting_show(): void
    {
        [$meeting] = $this->createMeetingWithHost();

        $joinResponse = $this->postJson("/api/meetings/{$meeting->public_id}/join", [
            'name' => 'Guest User',
            'email' => 'guest@example.test',
            'password' => 'PassWord123',
        ])->assertOk();

        $guestParticipantId = $joinResponse->json('data.participant.public_id');
        $this->assertNotEmpty($guestParticipantId);

        $showResponse = $this->getJson("/api/meetings/{$meeting->public_id}");
        $showResponse->assertOk();

        $showResponse->assertJsonCount(1, 'participants');
        $showResponse->assertJsonPath('participants.0.public_id', $guestParticipantId);
        $showResponse->assertJsonPath('participants.0.status', 'waiting');
    }

    /** @test */
    public function authenticated_waiting_participant_can_fetch_its_own_participant_record_from_meeting_show(): void
    {
        [$meeting] = $this->createMeetingWithHost();
        $attendee = User::factory()->create();

        $this->actingAs($attendee);

        $joinResponse = $this->postJson("/api/meetings/{$meeting->public_id}/join", [
            'password' => 'PassWord123',
        ])->assertOk();

        $attendeeParticipantId = $joinResponse->json('data.participant.public_id');
        $this->assertNotEmpty($attendeeParticipantId);

        $showResponse = $this->getJson("/api/meetings/{$meeting->public_id}");
        $showResponse->assertOk();

        $showResponse->assertJsonCount(1, 'participants');
        $showResponse->assertJsonPath('participants.0.public_id', $attendeeParticipantId);
        $showResponse->assertJsonPath('participants.0.status', 'waiting');
    }

    /**
     * @return array{Meeting, User}
     */
    private function createMeetingWithHost(): array
    {
        $host = User::factory()->create();

        $meeting = Meeting::create([
            'public_id' => (string) Str::ulid(),
            'user_id' => $host->id,
            'title' => 'Waiting Room Test',
            'start_time' => now(),
            'status' => 'active',
            'settings' => [
                'guest_access' => true,
                'lobby_enabled' => true,
            ],
            'password' => 'PassWord123',
            'app_id' => 'worksphere',
        ]);

        MeetingParticipant::create([
            'meeting_id' => $meeting->id,
            'user_id' => $host->id,
            'public_id' => (string) Str::ulid(),
            'role' => 'host',
            'status' => 'admitted',
        ]);

        return [$meeting, $host];
    }
}
