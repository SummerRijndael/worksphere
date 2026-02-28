<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\User;
use App\Models\MeetingParticipant;
use App\Services\MeetingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class MeetingIDTypeMismatchTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        // Disable firewall middleware
        $this->withoutMiddleware([
            \Akaunting\Firewall\Middleware\Ip::class,
            \App\Http\Middleware\CheckUserStatus::class,
        ]);
    }

    /** @test */
    public function test_host_is_admitted_even_with_id_type_mismatch()
    {
        $user = User::factory()->create(['id' => 12345]);

        // Create meeting directly to ensure we can control the data
        $meeting = Meeting::create([
            'public_id' => 'test-meeting-ulid',
            'user_id' => 12345,
            'title' => 'Test Meeting',
            'start_time' => now(),
            'status' => 'scheduled',
            'settings' => ['lobby_enabled' => true],
        ]);

        // Mock a user object where 'id' might be returned as a string "12345"
        // Force the ID to be a string
        $user = \Mockery::mock($user)->makePartial();
        $user->shouldReceive('getAttribute')->with('id')->andReturn("12345");
        $user->shouldReceive('offsetGet')->with('id')->andReturn("12345");

        $service = app(MeetingService::class);

        // Attempt to join as host
        $result = $service->joinMeeting($meeting, $user, null, null, null, null);

        $participant = $result['participant'];

        $this->assertEquals('admitted', $participant->status, "Host should be admitted even if ID types mismatch");
        $this->assertEquals('host', $participant->role);
    }

    /** @test */
    public function test_host_broadcasting_auth_works_with_id_type_mismatch()
    {
        $user = User::factory()->create(['id' => 54321]);
        $meeting = Meeting::create([
            'public_id' => 'test-meeting-auth',
            'user_id' => 54321,
            'title' => 'Test Auth',
            'start_time' => now(),
            'status' => 'scheduled',
        ]);

        $user = \Mockery::mock($user)->makePartial();
        $user->shouldReceive('getAttribute')->with('id')->andReturn("54321");
        $user->shouldReceive('offsetGet')->with('id')->andReturn("54321");

        $service = app(MeetingService::class);

        // We need a participant record for the host
        $participant = MeetingParticipant::create([
            'meeting_id' => $meeting->id,
            'user_id' => 54321,
            'role' => 'host',
            'status' => 'admitted',
            'public_id' => 'host-pid'
        ]);

        // Mock pusher config for auth
        config(['broadcasting.default' => 'pusher']);
        config(['broadcasting.connections.pusher' => [
            'key' => 'test',
            'secret' => 'test',
            'app_id' => 'test',
            'options' => [],
        ]]);

        // Pusher socket ID must be in format 'digit.digit'
        $response = $service->authenticateBroadcasting($meeting, $user, 'presence-meeting.test-meeting-auth', '123.456', 'host-pid');

        $this->assertEquals(200, $response->getStatusCode(), "Host should be able to authenticate even if ID types mismatch");
    }
}
