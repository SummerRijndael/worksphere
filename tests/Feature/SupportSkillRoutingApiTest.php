<?php

namespace Tests\Feature;

use App\Models\SupportConversation;
use App\Models\User;
use Illuminate\Foundation\Testing\RefreshDatabase;
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
            ->assertOk()
            ->assertJsonPath('data.assignee.id', $agentInSkill->public_id);

        $this->actingAs($agentInSkill)
            ->getJson("/api/support/chats/agent/{$conversation->public_id}")
            ->assertOk()
            ->assertJsonPath('data.id', $conversation->public_id);

        $this->actingAs($agentOutOfSkill)
            ->getJson("/api/support/chats/agent/{$conversation->public_id}")
            ->assertStatus(403);
    }
}
