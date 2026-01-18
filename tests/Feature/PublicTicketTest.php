<?php

namespace Tests\Feature;

use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Tests\TestCase;

class PublicTicketTest extends TestCase
{
    use RefreshDatabase;

    public function test_guest_can_submit_ticket()
    {
        $response = $this->postJson('/api/public/tickets', [
            'name' => 'John Guest',
            'email' => 'guest@example.com',
            'title' => 'Help me',
            'description' => 'I need help with login',
        ]);

        $response->assertStatus(201)
            ->assertJsonStructure(['message', 'ticket_number']);

        $this->assertDatabaseHas('tickets', [
            'guest_email' => 'guest@example.com',
            'title' => 'Help me',
            'reporter_id' => null,
        ]);
    }

    public function test_guest_ticket_associates_with_existing_user()
    {
        $user = User::factory()->create(['email' => 'existing@example.com']);

        $response = $this->postJson('/api/public/tickets', [
            'name' => 'Existing User',
            'email' => 'existing@example.com',
            'title' => 'My Account Issue',
            'description' => 'Cannot access dashboard',
        ]);

        $response->assertStatus(201);

        $this->assertDatabaseHas('tickets', [
            'title' => 'My Account Issue',
            'reporter_id' => $user->id,
            'guest_email' => null,
        ]);
    }

    public function test_guest_ticket_validation()
    {
        $response = $this->postJson('/api/public/tickets', [
            // Missing name and email
            'title' => 'Invalid Request',
        ]);

        $response->assertStatus(422)
            ->assertJsonValidationErrors(['name', 'email']);
    }
}
