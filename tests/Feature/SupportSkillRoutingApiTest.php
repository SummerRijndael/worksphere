<?php

namespace Tests\Feature;

use App\Models\SupportConversation;
use App\Models\SupportRoutingQueueEntry;
use App\Models\SupportSkill;
use App\Models\SupportSkillMembership;
use App\Models\User;
use App\Services\Chat\PresenceService;
use App\Services\Support\SupportRoutingService;
use Illuminate\Foundation\Testing\RefreshDatabase;
use Illuminate\Support\Facades\Queue;
use Spatie\Permission\Models\Permission;
use Tests\TestCase;

class SupportSkillRoutingApiTest extends TestCase
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
    }

    public function test_support_skill_crud_endpoints_work_for_routing_manager(): void
    {
        $manager = User::factory()->create(['status' => 'active']);
        $manager->givePermissionTo('support.chats.assign');

        $createResponse = $this->actingAs($manager)->postJson('/api/support/chats/skills', [
            'name' => 'Billing Tier 2',
            'slug' => 'billing-tier-2',
            'department' => 'Support',
            'priority' => 10,
            'is_active' => true,
        ]);

        $createResponse->assertStatus(201)
            ->assertJsonPath('data.name', 'Billing Tier 2')
            ->assertJsonPath('data.slug', 'billing-tier-2')
            ->assertJsonPath('data.is_active', true);

        $skillId = (string) $createResponse->json('data.id');

        $this->actingAs($manager)->putJson("/api/support/chats/skills/{$skillId}", [
            'description' => 'Handles billing escalations.',
            'priority' => 5,
        ])->assertOk()
            ->assertJsonPath('data.description', 'Handles billing escalations.')
            ->assertJsonPath('data.priority', 5);

        $this->actingAs($manager)->getJson('/api/support/chats/skills?include_members=1')
            ->assertOk()
            ->assertJsonPath('meta.total', 1)
            ->assertJsonPath('data.0.id', $skillId)
            ->assertJsonPath('data.0.members_count', 0);
    }

    public function test_skills_adapter_limits_inbox_access_to_skill_memberships(): void
    {
        Queue::fake([\App\Jobs\Support\SupportAssignmentTimeoutJob::class]);

        config()->set('support_chat.access_adapter', 'skills');
        config()->set('support_chat.skills.enabled', true);
        config()->set('support_chat.skills.allow_legacy_fallback', false);
        config()->set('support_chat.skills.allow_unrouted_conversation_fallback', false);

        $manager = User::factory()->create(['status' => 'active']);
        $manager->givePermissionTo('tickets.manage');

        $agentInSkill = User::factory()->create(['status' => 'active']);
        $agentOutOfSkill = User::factory()->create(['status' => 'active']);

        $skillResponse = $this->actingAs($manager)->postJson('/api/support/chats/skills', [
            'name' => 'Technical Support',
            'slug' => 'technical-support',
            'department' => 'IT',
            'is_active' => true,
        ])->assertStatus(201);

        $skillId = (string) $skillResponse->json('data.id');

        $this->actingAs($manager)
            ->postJson("/api/support/chats/skills/{$skillId}/members", [
                'agent_public_id' => $agentInSkill->public_id,
                'membership_role' => 'agent',
                'is_primary' => true,
                'is_active' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.user.id', $agentInSkill->public_id)
            ->assertJsonPath('data.membership_role', 'agent');

        $requester = User::factory()->create(['status' => 'active']);
        $conversation = SupportConversation::create([
            'requester_user_id' => $requester->id,
            'status' => SupportConversation::STATUS_WAITING_HUMAN,
            'priority' => 'normal',
            'channel' => 'widget',
            'ai_enabled' => true,
        ]);

        $this->actingAs($manager)
            ->postJson("/api/support/chats/{$conversation->public_id}/routing/skill", [
                'support_skill_id' => $skillId,
            ])
            ->assertOk()
            ->assertJsonPath('data.id', $conversation->public_id)
            ->assertJsonPath('data.routing_scope', 'skill')
            ->assertJsonPath('data.support_skill.id', $skillId);

        $this->actingAs($manager)
            ->postJson("/api/support/chats/{$conversation->public_id}/assign", [
                'agent_public_id' => $agentInSkill->public_id,
            ])
            ->assertOk();

        $this->assertDatabaseHas('support_conversations', [
            'id' => $conversation->id,
            'assigned_to' => $agentInSkill->id,
        ]);

        $this->actingAs($agentInSkill)
            ->getJson("/api/support/chats/agent/{$conversation->public_id}")
            ->assertOk()
            ->assertJsonPath('data.id', $conversation->public_id);

        $this->actingAs($agentOutOfSkill)
            ->getJson("/api/support/chats/agent/{$conversation->public_id}")
            ->assertStatus(403);
    }

    public function test_skills_adapter_requires_skill_membership_for_assignment_even_for_admins(): void
    {
        Queue::fake([\App\Jobs\Support\SupportAssignmentTimeoutJob::class]);

        config()->set('support_chat.access_adapter', 'skills');
        config()->set('support_chat.skills.enabled', true);
        config()->set('support_chat.skills.allow_legacy_fallback', false);
        config()->set('support_chat.skills.allow_unrouted_conversation_fallback', false);

        $manager = User::factory()->create(['status' => 'active']);
        $manager->givePermissionTo('tickets.manage');

        $adminWithoutSkill = User::factory()->create(['status' => 'active']);
        $adminWithoutSkill->givePermissionTo('tickets.manage');

        $agentInSkill = User::factory()->create(['status' => 'active']);

        $skillResponse = $this->actingAs($manager)->postJson('/api/support/chats/skills', [
            'name' => 'Billing Support',
            'slug' => 'billing-support',
            'department' => 'Support',
            'is_active' => true,
        ])->assertStatus(201);

        $skillId = (string) $skillResponse->json('data.id');

        $this->actingAs($manager)
            ->postJson("/api/support/chats/skills/{$skillId}/members", [
                'agent_public_id' => $agentInSkill->public_id,
                'membership_role' => 'agent',
                'is_primary' => true,
                'is_active' => true,
            ])
            ->assertOk();

        $requester = User::factory()->create(['status' => 'active']);
        $conversation = SupportConversation::create([
            'requester_user_id' => $requester->id,
            'status' => SupportConversation::STATUS_WAITING_HUMAN,
            'priority' => 'normal',
            'channel' => 'widget',
            'ai_enabled' => true,
        ]);

        $this->actingAs($manager)
            ->postJson("/api/support/chats/{$conversation->public_id}/routing/skill", [
                'support_skill_id' => $skillId,
            ])
            ->assertOk();

        $this->actingAs($manager)
            ->postJson("/api/support/chats/{$conversation->public_id}/assign", [
                'agent_public_id' => $adminWithoutSkill->public_id,
            ])
            ->assertStatus(422);

        $this->actingAs($manager)
            ->postJson("/api/support/chats/{$conversation->public_id}/assign", [
                'agent_public_id' => $agentInSkill->public_id,
            ])
            ->assertOk();

        $this->assertDatabaseHas('support_conversations', [
            'id' => $conversation->id,
            'assigned_to' => $agentInSkill->id,
        ]);
    }

    public function test_strict_skill_routing_assigns_default_skill_to_handoff_conversations(): void
    {
        Queue::fake([\App\Jobs\Support\SupportAssignmentTimeoutJob::class]);

        config()->set('queue.default', 'sync');
        config()->set('support_chat.access_adapter', 'skills');
        config()->set('support_chat.skills.enabled', true);
        config()->set('support_chat.skills.allow_legacy_fallback', false);
        config()->set('support_chat.skills.allow_unrouted_conversation_fallback', false);
        config()->set('support_chat.skills.default_skill_slug', 'general-support');

        $defaultSkill = SupportSkill::create([
            'name' => 'General Support',
            'slug' => 'general-support',
            'is_active' => true,
            'priority' => 10,
        ]);

        $agent = User::factory()->create(['status' => 'active']);
        SupportSkillMembership::create([
            'support_skill_id' => $defaultSkill->id,
            'user_id' => $agent->id,
            'membership_role' => 'agent',
            'is_primary' => true,
            'is_active' => true,
            'capacity' => 2,
        ]);

        app(PresenceService::class)->heartbeat($agent);
        app(PresenceService::class)->setSupportStatus($agent, 'available', true);

        $response = $this->postJson('/api/support/chats', [
            'guest_name' => 'Queue Tester',
            'guest_email' => 'queue@example.test',
            'initial_message' => 'Please talk to human about my billing issue.',
            'ai_enabled' => true,
        ])->assertCreated();

        $conversationId = $response->json('data.id');
        $conversation = SupportConversation::query()
            ->where('public_id', $conversationId)
            ->firstOrFail();

        $this->assertSame($defaultSkill->id, $conversation->support_skill_id);
        $this->assertSame($agent->id, $conversation->assigned_to);
        $this->assertSame(SupportConversation::STATUS_PENDING_ACCEPTANCE, $conversation->status);
    }

    public function test_reenqueue_resets_failed_queue_entry_attempts(): void
    {
        config()->set('support_chat.access_adapter', 'skills');
        config()->set('support_chat.skills.enabled', true);
        config()->set('support_chat.skills.allow_legacy_fallback', false);
        config()->set('support_chat.skills.allow_unrouted_conversation_fallback', false);
        config()->set('support_chat.skills.default_skill_slug', 'general-support');

        $defaultSkill = SupportSkill::create([
            'name' => 'General Support',
            'slug' => 'general-support',
            'is_active' => true,
            'priority' => 10,
        ]);

        $conversation = SupportConversation::create([
            'guest_name' => 'Retry Guest',
            'guest_email' => 'retry@example.test',
            'status' => SupportConversation::STATUS_WAITING_HUMAN,
            'priority' => 'normal',
            'channel' => 'widget',
            'ai_enabled' => true,
        ]);

        $failedEntry = SupportRoutingQueueEntry::create([
            'conversation_id' => $conversation->id,
            'support_skill_id' => null,
            'state' => SupportRoutingQueueEntry::STATE_FAILED,
            'enqueue_reason' => 'customer_message',
            'priority' => 100,
            'attempts' => 20,
            'max_attempts' => 20,
            'last_error' => 'No eligible support agent is currently available.',
        ]);

        $entry = app(SupportRoutingService::class)->enqueueConversation($conversation, 'manual_requeue', true);

        $this->assertNotNull($entry);
        $this->assertSame($failedEntry->id, $entry->id);
        $this->assertSame($defaultSkill->id, $entry->support_skill_id);
        $this->assertSame(0, $entry->attempts);
        $this->assertSame(SupportRoutingQueueEntry::STATE_PENDING, $entry->state);
        $this->assertNull($entry->last_error);
    }
}
