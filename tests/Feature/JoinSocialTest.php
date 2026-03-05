<?php

namespace Tests\Feature;

use App\Models\Meeting;
use App\Models\User;
use Tests\TestCase;

class JoinSocialTest extends TestCase
{
    public function test_social_user_can_join_meeting()
    {
        $user = User::where('email', 'ev.ryann.olaso@gmail.com')->first();
        if (! $user) {
            $this->markTestSkipped('Social user not found.');
        }

        $meeting = Meeting::latest()->first();

        $response = $this->actingAs($user)
            ->postJson("/api/meetings/{$meeting->public_id}/join", [
                'name' => $user->name,
                'email' => $user->email,
            ]);

        $response->assertStatus(200);
        $this->assertNotNull($response->json('data.participant'));
    }
}
