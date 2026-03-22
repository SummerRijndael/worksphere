<?php

namespace Tests\Feature;

use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\SupportSkill;
use App\Models\User;
use App\Services\Chat\PresenceService;
use App\Services\Support\SupportRoutingService;
use App\Jobs\Support\SupportAssignmentTimeoutJob;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Spatie\Permission\Models\Role;
use Tests\TestCase;

class SupportAdvancedFeaturesTest extends TestCase
{
    use RefreshDatabase;

    protected User $agent;
    protected Role $agentRole;

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

        $this->agentRole = Role::findOrCreate('administrator', 'web');
        $this->agentRole->syncPermissions([
            'support.chats.view',
            'support.chats.reply',
            'support.chats.assign',
            'support.chats.resolve',
            'tickets.manage',
        ]);

        $this->agent = User::factory()->create(['status' => 'active']);
        $this->agent->assignRole($this->agentRole);
    }

    public function test_agent_can_toggle_support_availability(): void
    {
        $this->actingAs($this->agent)
            ->postJson('/api/support/chats/availability', [
                'available' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.support_available', true);

        $this->assertTrue(app(PresenceService::class)->isSupportAvailable($this->agent->id));

        $this->actingAs($this->agent)
            ->postJson('/api/support/chats/availability', [
                'available' => false,
            ])
            ->assertOk()
            ->assertJsonPath('data.support_available', false);

        $this->assertFalse(app(PresenceService::class)->isSupportAvailable($this->agent->id));
    }

    public function test_conversation_assignment_pending_acceptance_flow(): void
    {
        Queue::fake([SupportAssignmentTimeoutJob::class]);

        $conversation = SupportConversation::create([
            'guest_name' => 'Test Guest',
            'guest_email' => 'guest@example.test',
            'status' => SupportConversation::STATUS_WAITING_HUMAN,
            'priority' => 'normal',
            'channel' => 'widget',
        ]);

        // Enable availability for agent
        app(PresenceService::class)->setSupportAvailability($this->agent, true);

        $secondaryAgent = User::factory()->create(['status' => 'active']);
        $secondaryAgent->assignRole($this->agentRole);

        // Simulate routing to another agent
        $this->actingAs($this->agent)
            ->postJson("/api/support/chats/{$conversation->public_id}/assign", [
                'agent_public_id' => $secondaryAgent->public_id,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', SupportConversation::STATUS_PENDING_ACCEPTANCE)
            ->assertJsonPath('data.assignment_state', SupportConversation::ASSIGNMENT_STATE_PENDING);

        Queue::assertPushed(SupportAssignmentTimeoutJob::class);

        // Accept assignment
        $this->actingAs($secondaryAgent)
            ->postJson("/api/support/chats/{$conversation->public_id}/accept")
            ->assertOk()
            ->assertJsonPath('data.status', SupportConversation::STATUS_ASSIGNED);

        $this->assertDatabaseHas('support_conversations', [
            'id' => $conversation->id,
            'assigned_to' => $secondaryAgent->id,
            'status' => SupportConversation::STATUS_ASSIGNED,
        ]);
    }

    public function test_agent_can_reject_assignment(): void
    {
        $conversation = SupportConversation::create([
            'guest_name' => 'Test Guest',
            'guest_email' => 'guest@example.test',
            'status' => SupportConversation::STATUS_PENDING_ACCEPTANCE,
            'assigned_to' => $this->agent->id,
            'assignment_state' => SupportConversation::ASSIGNMENT_STATE_PENDING,
        ]);

        $this->actingAs($this->agent)
            ->postJson("/api/support/chats/{$conversation->public_id}/reject", [
                'reason' => 'Too busy right now',
            ])
            ->assertOk();

        $this->assertDatabaseHas('support_conversations', [
            'id' => $conversation->id,
            'assigned_to' => null,
            'status' => SupportConversation::STATUS_WAITING_HUMAN,
            'assignment_state' => SupportConversation::ASSIGNMENT_STATE_UNASSIGNED,
        ]);
    }

    public function test_agent_can_transfer_conversation_to_another_agent(): void
    {
        $secondaryAgent = User::factory()->create(['status' => 'active']);
        $secondaryAgent->assignRole($this->agentRole);

        $conversation = SupportConversation::create([
            'guest_name' => 'Test Guest',
            'guest_email' => 'guest@example.test',
            'status' => SupportConversation::STATUS_ASSIGNED,
            'assigned_to' => $this->agent->id,
            'assignment_state' => SupportConversation::ASSIGNMENT_STATE_ASSIGNED,
        ]);

        $this->actingAs($this->agent)
            ->postJson("/api/support/chats/{$conversation->public_id}/transfer", [
                'assigned_to' => $secondaryAgent->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', SupportConversation::STATUS_PENDING_ACCEPTANCE)
            ->assertJsonPath('data.assignee.id', $secondaryAgent->public_id);
    }

    public function test_agent_can_transfer_conversation_to_skill_queue(): void
    {
        $skill = SupportSkill::create([
            'name' => 'Technical Support',
            'slug' => 'technical-support',
            'is_active' => true,
        ]);

        $conversation = SupportConversation::create([
            'guest_name' => 'Test Guest',
            'guest_email' => 'guest@example.test',
            'status' => SupportConversation::STATUS_ASSIGNED,
            'assigned_to' => $this->agent->id,
            'assignment_state' => SupportConversation::ASSIGNMENT_STATE_ASSIGNED,
        ]);

        $this->actingAs($this->agent)
            ->postJson("/api/support/chats/{$conversation->public_id}/transfer", [
                'support_skill_id' => $skill->id,
            ])
            ->assertOk()
            ->assertJsonPath('data.support_skill.id', $skill->public_id)
            ->assertJsonPath('data.status', SupportConversation::STATUS_WAITING_HUMAN)
            ->assertJsonPath('data.assignee', null);
    }

    public function test_after_call_work_wrap_up_flow(): void
    {
        $conversation = SupportConversation::create([
            'guest_name' => 'Test Guest',
            'guest_email' => 'guest@example.test',
            'status' => SupportConversation::STATUS_ASSIGNED,
            'assigned_to' => $this->agent->id,
            'assignment_state' => SupportConversation::ASSIGNMENT_STATE_ASSIGNED,
        ]);

        // Resolving should transition to wrap_up
        $this->actingAs($this->agent)
            ->postJson("/api/support/chats/{$conversation->public_id}/resolve")
            ->assertOk()
            ->assertJsonPath('data.status', SupportConversation::STATUS_WRAP_UP);

        $this->assertDatabaseHas('support_conversations', [
            'id' => $conversation->id,
            'status' => SupportConversation::STATUS_WRAP_UP,
            'closed_at' => null,
        ]);

        // Complete wrap-up
        $this->actingAs($this->agent)
            ->postJson("/api/support/chats/{$conversation->public_id}/wrap-up/complete")
            ->assertOk()
            ->assertJsonPath('data.status', SupportConversation::STATUS_CLOSED);

        $this->assertDatabaseHas('support_conversations', [
            'id' => $conversation->id,
            'status' => SupportConversation::STATUS_CLOSED,
        ]);
        $this->assertNotNull($conversation->fresh()->closed_at);
    }

    public function test_closing_conversation_triggers_wrap_up_when_assigned(): void
    {
        $conversation = SupportConversation::create([
            'guest_name' => 'Test Guest',
            'guest_email' => 'guest@example.test',
            'status' => SupportConversation::STATUS_ASSIGNED,
            'assigned_to' => $this->agent->id,
            'assignment_state' => SupportConversation::ASSIGNMENT_STATE_ASSIGNED,
        ]);

        $this->actingAs($this->agent)
            ->postJson("/api/support/chats/agent/{$conversation->public_id}/end")
            ->assertOk()
            ->assertJsonPath('data.status', SupportConversation::STATUS_WRAP_UP);
    }
}
