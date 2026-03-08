<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Str;
use Tests\TestCase;

class MeetingCreationLimitTest extends TestCase
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
    public function user_cannot_create_more_than_the_configured_meeting_limit(): void
    {
        config(['worksphere.meetings.limits.max_meetings_per_user' => 2]);

        $user = User::factory()->create();

        Meeting::create([
            'public_id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'title' => 'Existing Meeting 1',
            'start_time' => now()->addHour(),
            'status' => 'scheduled',
            'settings' => ['lobby_enabled' => true],
            'app_id' => 'worksphere',
        ]);

        Meeting::create([
            'public_id' => (string) Str::ulid(),
            'user_id' => $user->id,
            'title' => 'Existing Meeting 2',
            'start_time' => now()->addHours(2),
            'status' => 'scheduled',
            'settings' => ['lobby_enabled' => true],
            'app_id' => 'worksphere',
        ]);

        $response = $this->actingAs($user)->postJson('/api/meetings', [
            'title' => 'Should Fail',
            'start_time' => now()->addDay()->toIso8601String(),
            'settings' => [
                'lobby_enabled' => true,
                'guest_access' => false,
            ],
        ]);

        $response->assertStatus(422);
        $response->assertJsonPath(
            'message',
            "You've reached the 2-meeting limit. Delete an existing meeting to create a new one."
        );
        $this->assertSame(2, Meeting::where('user_id', $user->id)->count());
    }
}
