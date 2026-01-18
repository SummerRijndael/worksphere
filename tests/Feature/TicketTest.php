<?php

namespace Tests\Feature;

use App\Models\Ticket;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class TicketTest extends TestCase
{
    use RefreshDatabase;

    protected User $admin;

    protected User $user;

    protected function setUp(): void
    {
        parent::setUp();

        // Create roles and permissions
        $adminRole = Role::create(['name' => 'admin', 'guard_name' => 'web']);

        // Create all ticket permissions
        $permissions = [
            'tickets.view', 'tickets.view_own', 'tickets.create', 'tickets.update',
            'tickets.update_own', 'tickets.delete', 'tickets.assign', 'tickets.close',
            'tickets.internal_notes',
        ];

        foreach ($permissions as $permission) {
            Permission::create(['name' => $permission, 'guard_name' => 'web']);
        }

        $adminRole->givePermissionTo($permissions);

        // Create admin user
        $this->admin = User::factory()->create();
        $this->admin->assignRole('admin');

        // Create regular user with basic permissions
        $this->user = User::factory()->create();
        $this->user->givePermissionTo(['tickets.view_own', 'tickets.create', 'tickets.update_own']);
    }

    public function test_admin_can_list_all_tickets(): void
    {
        Ticket::factory()->count(3)->create();

        $response = $this->actingAs($this->admin)
            ->getJson('/api/tickets');

        $response->assertStatus(200)
            ->assertJsonCount(3, 'data');
    }

    public function test_user_can_only_see_own_tickets(): void
    {
        // Create tickets for the user
        Ticket::factory()->count(2)->create(['reporter_id' => $this->user->id]);
        // Create tickets for other users
        Ticket::factory()->count(3)->create();

        $response = $this->actingAs($this->user)
            ->getJson('/api/tickets');

        $response->assertStatus(200)
            ->assertJsonCount(2, 'data');
    }

    public function test_admin_can_get_ticket_stats(): void
    {
        Ticket::factory()->count(2)->open()->create();
        Ticket::factory()->count(1)->inProgress()->create();
        Ticket::factory()->count(1)->resolved()->create();

        $response = $this->actingAs($this->admin)
            ->getJson('/api/tickets/stats');

        $response->assertStatus(200)
            ->assertJsonPath('total', 4)
            ->assertJsonPath('open', 2)
            ->assertJsonPath('in_progress', 1)
            ->assertJsonPath('resolved', 1);
    }

    public function test_user_can_create_ticket(): void
    {
        $response = $this->actingAs($this->user)
            ->postJson('/api/tickets', [
                'title' => 'Test Ticket',
                'description' => 'This is a test ticket',
                'priority' => 'medium',
                'type' => 'bug',
            ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.title', 'Test Ticket');

        $this->assertDatabaseHas('tickets', ['title' => 'Test Ticket']);
    }

    public function test_admin_can_view_any_ticket(): void
    {
        $ticket = Ticket::factory()->create();

        $response = $this->actingAs($this->admin)
            ->getJson("/api/tickets/{$ticket->public_id}");

        $response->assertStatus(200)
            ->assertJsonPath('data.id', $ticket->public_id);
    }

    public function test_user_can_view_own_ticket(): void
    {
        $ticket = Ticket::factory()->create(['reporter_id' => $this->user->id]);

        $response = $this->actingAs($this->user)
            ->getJson("/api/tickets/{$ticket->public_id}");

        $response->assertStatus(200);
    }

    public function test_user_cannot_view_others_ticket(): void
    {
        $ticket = Ticket::factory()->create();

        $response = $this->actingAs($this->user)
            ->getJson("/api/tickets/{$ticket->public_id}");

        $response->assertStatus(403);
    }

    public function test_admin_can_update_ticket(): void
    {
        $ticket = Ticket::factory()->create();

        $response = $this->actingAs($this->admin)
            ->putJson("/api/tickets/{$ticket->public_id}", [
                'title' => 'Updated Title',
                'reason' => 'Correcting the ticket title for clarity',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.title', 'Updated Title');
    }

    public function test_user_can_update_own_ticket(): void
    {
        $ticket = Ticket::factory()->create(['reporter_id' => $this->user->id]);

        $response = $this->actingAs($this->user)
            ->putJson("/api/tickets/{$ticket->public_id}", [
                'title' => 'My Updated Title',
                'reason' => 'Updated my own ticket with new information',
            ]);

        $response->assertStatus(200);
    }

    public function test_user_cannot_update_others_ticket(): void
    {
        $ticket = Ticket::factory()->create();

        $response = $this->actingAs($this->user)
            ->putJson("/api/tickets/{$ticket->public_id}", [
                'title' => 'Hacked Title',
                'reason' => 'Trying to hack ticket',
            ]);

        $response->assertStatus(403);
    }

    public function test_admin_can_delete_ticket(): void
    {
        $ticket = Ticket::factory()->create();

        $response = $this->actingAs($this->admin)
            ->deleteJson("/api/tickets/{$ticket->public_id}", [
                'reason' => 'Testing deletion',
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseMissing('tickets', ['id' => $ticket->id]);
    }

    public function test_user_cannot_delete_ticket(): void
    {
        $ticket = Ticket::factory()->create(['reporter_id' => $this->user->id]);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/tickets/{$ticket->public_id}");

        $response->assertStatus(403);
    }

    public function test_admin_can_assign_ticket(): void
    {
        $ticket = Ticket::factory()->create();
        $assignee = User::factory()->create();

        $response = $this->actingAs($this->admin)
            ->putJson("/api/tickets/{$ticket->public_id}/assign", [
                'assigned_to' => $assignee->public_id,
            ]);

        $response->assertStatus(200);
        $this->assertDatabaseHas('tickets', [
            'id' => $ticket->id,
            'assigned_to' => $assignee->id,
        ]);
    }

    public function test_admin_can_change_ticket_status(): void
    {
        $ticket = Ticket::factory()->open()->create();

        $response = $this->actingAs($this->admin)
            ->putJson("/api/tickets/{$ticket->public_id}/status", [
                'status' => 'resolved',
            ]);

        $response->assertStatus(200)
            ->assertJsonPath('data.status.value', 'resolved');
    }

    public function test_user_can_add_comment_to_own_ticket(): void
    {
        $ticket = Ticket::factory()->create(['reporter_id' => $this->user->id]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/tickets/{$ticket->public_id}/comments", [
                'content' => 'This is my comment',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('ticket_comments', [
            'ticket_id' => $ticket->id,
            'content' => 'This is my comment',
        ]);
    }

    public function test_admin_can_add_internal_note(): void
    {
        $ticket = Ticket::factory()->create();

        $response = $this->actingAs($this->admin)
            ->postJson("/api/tickets/{$ticket->public_id}/internal-notes", [
                'content' => 'Internal staff note',
            ]);

        $response->assertStatus(201);
        $this->assertDatabaseHas('ticket_internal_notes', [
            'ticket_id' => $ticket->id,
            'content' => 'Internal staff note',
        ]);
    }

    public function test_user_cannot_add_internal_note(): void
    {
        $ticket = Ticket::factory()->create(['reporter_id' => $this->user->id]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/tickets/{$ticket->public_id}/internal-notes", [
                'content' => 'Should not work',
            ]);

        $response->assertStatus(403);
    }

    public function test_user_can_follow_ticket(): void
    {
        $ticket = Ticket::factory()->create(['reporter_id' => $this->user->id]);

        $response = $this->actingAs($this->user)
            ->postJson("/api/tickets/{$ticket->public_id}/follow");

        $response->assertStatus(200);
        $this->assertTrue($ticket->fresh()->isFollowedBy($this->user));
    }

    public function test_user_can_unfollow_ticket(): void
    {
        $ticket = Ticket::factory()->create(['reporter_id' => $this->user->id]);
        $ticket->followers()->attach($this->user->id);

        $response = $this->actingAs($this->user)
            ->deleteJson("/api/tickets/{$ticket->public_id}/follow");

        $response->assertStatus(200);
        $this->assertFalse($ticket->fresh()->isFollowedBy($this->user));
    }
}
