<?php

namespace Tests\Feature;

use App\Models\SupportConversation;
use App\Models\SupportRoutingQueueEntry;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SupportRoutingEngineTest extends TestCase
{
    use RefreshDatabase;

    protected function setUp(): void
    {
        parent::setUp();

        foreach ([
            'support.chats.view',
            'support.chats.reply',
            'support.chats.assign',
            'support.chats.resolve',
            'tickets.manage',
        ] as $permission) {
            Permission::findOrCreate($permission, 'web');
        }

        config()->set('support_chat.routing.enabled', true);
    }

    public function test_complex_conversation_is_auto_assigned_when_eligible_agent_is_available(): void
    {
        $agent = User::factory()->create(['status' => 'active']);
        $agent->givePermissionTo('support.chats.reply');

        $response = $this->postJson('/api/support/chats', [
            'guest_name' => 'Queue Guest',
            'guest_email' => 'queue-guest@example.test',
            'initial_message' => 'We have critical outage and possible security breach in production.',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', SupportConversation::STATUS_ASSIGNED)
            ->assertJsonPath('data.assignment_state', SupportConversation::ASSIGNMENT_STATE_ASSIGNED)
            ->assertJsonPath('data.assignee.id', $agent->public_id);

        $conversationId = (string) $response->json('data.id');
        $conversation = SupportConversation::query()->where('public_id', $conversationId)->firstOrFail();

        $this->assertSame($agent->id, (int) $conversation->assigned_to);

        $this->assertDatabaseHas('support_routing_queue_entries', [
            'conversation_id' => $conversation->id,
            'state' => SupportRoutingQueueEntry::STATE_ASSIGNED,
            'assigned_to' => $agent->id,
        ]);
    }

    public function test_auto_routing_hard_caps_agent_capacity_at_five_even_when_configured_higher(): void
    {
        config()->set('support_chat.routing.default_agent_capacity', 9);
        config()->set('support_chat.workbench.max_panels', 5);
        config()->set('support_chat.routing.require_online_agent', false);
        config()->set('support_chat.routing.require_support_available', false);

        $agent = User::factory()->create(['status' => 'active']);
        $agent->givePermissionTo('support.chats.reply');

        for ($i = 1; $i <= 5; $i++) {
            SupportConversation::create([
                'guest_name' => "Busy Guest {$i}",
                'guest_email' => "busy-{$i}@example.test",
                'status' => SupportConversation::STATUS_ASSIGNED,
                'priority' => 'normal',
                'channel' => 'widget',
                'assigned_to' => $agent->id,
                'ai_enabled' => true,
            ]);
        }

        $response = $this->postJson('/api/support/chats', [
            'guest_name' => 'Queue Guest',
            'guest_email' => 'queue-limit@example.test',
            'initial_message' => 'Critical outage with human escalation needed.',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', SupportConversation::STATUS_WAITING_HUMAN)
            ->assertJsonPath('data.assignee', null);
    }

    public function test_conversation_stays_pending_when_no_eligible_agents_are_available(): void
    {
        $response = $this->postJson('/api/support/chats', [
            'guest_name' => 'Queue Guest',
            'guest_email' => 'queue-guest@example.test',
            'initial_message' => 'Possible breach and critical outage affecting customers.',
        ]);

        $response->assertStatus(201)
            ->assertJsonPath('data.status', SupportConversation::STATUS_WAITING_HUMAN)
            ->assertJsonPath('data.assignment_state', SupportConversation::ASSIGNMENT_STATE_UNASSIGNED);

        $conversationId = (string) $response->json('data.id');
        $conversation = SupportConversation::query()->where('public_id', $conversationId)->firstOrFail();

        $this->assertDatabaseHas('support_routing_queue_entries', [
            'conversation_id' => $conversation->id,
            'state' => SupportRoutingQueueEntry::STATE_PENDING,
            'attempts' => 1,
        ]);
    }

    public function test_manual_assignment_marks_routing_entry_as_assigned(): void
    {
        $openResponse = $this->postJson('/api/support/chats', [
            'guest_name' => 'Queue Guest',
            'guest_email' => 'queue-guest@example.test',
            'initial_message' => 'Critical outage with possible security concern.',
        ]);
        $openResponse->assertStatus(201);

        $conversationPublicId = (string) $openResponse->json('data.id');
        $conversation = SupportConversation::query()->where('public_id', $conversationPublicId)->firstOrFail();

        $manager = User::factory()->create(['status' => 'active']);
        $manager->givePermissionTo('support.chats.assign');

        $assignee = User::factory()->create(['status' => 'active']);
        $assignee->givePermissionTo('support.chats.reply');

        $this->actingAs($manager)
            ->postJson("/api/support/chats/{$conversationPublicId}/assign", [
                'agent_public_id' => $assignee->public_id,
            ])
            ->assertOk()
            ->assertJsonPath('data.assignee.id', $assignee->public_id)
            ->assertJsonPath('data.status', SupportConversation::STATUS_ASSIGNED);

        $this->assertDatabaseHas('support_routing_queue_entries', [
            'conversation_id' => $conversation->id,
            'state' => SupportRoutingQueueEntry::STATE_ASSIGNED,
            'assigned_to' => $assignee->id,
        ]);
    }
}
