<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\User;
use App\Services\Chat\PresenceService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Mockery\MockInterface;
use Tests\TestCase;

class MeetingPruneCommandTest extends TestCase
{
    use RefreshDatabase;

    /** @test */
    public function prune_command_does_not_mark_meeting_as_ended_when_room_is_empty(): void
    {
        $host = User::factory()->create();

        $meeting = Meeting::create([
            'public_id' => (string) Str::ulid(),
            'user_id' => $host->id,
            'title' => 'Prune Command Test',
            'start_time' => now()->subHour(),
            'status' => 'active',
            'actual_start_time' => now()->subMinutes(10),
            'settings' => [
                'guest_access' => true,
                'lobby_enabled' => true,
            ],
            'app_id' => 'worksphere',
        ]);

        $this->mock(PresenceService::class, function (MockInterface $mock) use ($meeting) {
            $mock->shouldReceive('getActiveMeetingParticipantIds')
                ->once()
                ->with($meeting->public_id)
                ->andReturn([]);
        });

        $this->artisan('meetings:prune')
            ->assertExitCode(0);

        $meeting->refresh();

        $this->assertSame('active', $meeting->status);
        $this->assertNull($meeting->actual_end_time);
    }
}
