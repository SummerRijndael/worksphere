<?php

namespace Tests\Feature;

use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\SupportSkill;
use App\Models\SupportSkillMembership;
use App\Models\SupportSurveyInvite;
use App\Models\SupportSurveyResponse;
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
    protected SupportSkill $skill;

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

        $this->skill = SupportSkill::create([
            'name' => 'Core Support',
            'slug' => 'core-support',
            'is_active' => true,
        ]);

        $this->agent = $this->createSkilledAgent();
    }

    protected function createSkilledAgent(): User
    {
        $agent = User::factory()->create(['status' => 'active']);
        $agent->assignRole($this->agentRole);

        SupportSkillMembership::create([
            'support_skill_id' => $this->skill->id,
            'user_id' => $agent->id,
            'membership_role' => 'agent',
            'is_primary' => true,
            'is_active' => true,
            'capacity' => 3,
        ]);

        return $agent;
    }

    public function test_agent_can_toggle_support_availability(): void
    {
        $this->actingAs($this->agent)
            ->postJson('/api/support/chats/availability', [
                'status' => 'available',
            ])
            ->assertOk()
            ->assertJsonPath('data.support_available', true)
            ->assertJsonPath('data.status', 'available');

        $this->assertTrue(app(PresenceService::class)->isSupportAvailable($this->agent->id));
        $this->assertNotNull($this->agent->fresh()->support_status_at);

        $this->actingAs($this->agent)
            ->postJson('/api/support/chats/availability', [
                'status' => 'bio',
            ])
            ->assertOk()
            ->assertJsonPath('data.support_available', false)
            ->assertJsonPath('data.status', 'bio')
            ->assertJsonStructure([
                'data' => ['status', 'support_available', 'support_status_at'],
            ]);

        $this->assertFalse(app(PresenceService::class)->isSupportAvailable($this->agent->id));
        $this->assertSame('bio', $this->agent->fresh()->support_status);
    }

    public function test_logout_marks_agent_offline_for_support_presence(): void
    {
        $presence = app(PresenceService::class);

        $presence->heartbeat($this->agent);
        $presence->setSupportStatus($this->agent, 'lunch', false);

        $this->assertTrue($presence->isUserActive($this->agent->id));

        $this->actingAs($this->agent)
            ->postJson('/api/logout')
            ->assertOk()
            ->assertJsonPath('data.message', 'Logged out successfully.');

        $this->assertFalse($presence->isUserActive($this->agent->id));
        $this->assertFalse($presence->isSupportAvailable($this->agent->id));
    }

    public function test_public_availability_includes_agent_support_status_and_duration(): void
    {
        app(PresenceService::class)->heartbeat($this->agent);
        app(PresenceService::class)->setSupportStatus($this->agent, 'lunch', false);

        $this->getJson('/api/support/chats/availability')
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.available_agents', 0)
            ->assertJsonPath('data.agents.0.public_id', $this->agent->public_id)
            ->assertJsonPath('data.agents.0.support_status', 'lunch')
            ->assertJsonPath('data.agents.0.support_available', false)
            ->assertJsonStructure([
                'data' => [
                    'available',
                    'available_agents',
                    'agents' => [
                        ['public_id', 'name', 'support_status', 'support_status_at', 'support_available', 'active_chats', 'agent_capacity', 'working_since_at', 'longest_active_chat_seconds', 'completed_today', 'average_handle_time_seconds'],
                    ],
                ],
            ]);
    }

    public function test_public_availability_includes_completed_today_and_aht_per_agent(): void
    {
        app(PresenceService::class)->heartbeat($this->agent);
        app(PresenceService::class)->setSupportStatus($this->agent, 'available', true);

        SupportConversation::create([
            'guest_name' => 'Handled Guest',
            'guest_email' => 'handled@example.test',
            'status' => SupportConversation::STATUS_CLOSED,
            'priority' => 'normal',
            'channel' => 'widget',
            'assigned_to' => $this->agent->id,
            'assigned_at' => now()->subMinutes(15),
            'ended_at' => now(),
            'closed_at' => now(),
        ]);

        $this->getJson('/api/support/chats/availability')
            ->assertOk()
            ->assertJsonPath('data.available', true)
            ->assertJsonPath('data.available_agents', 1)
            ->assertJsonPath('data.agents.0.public_id', $this->agent->public_id)
            ->assertJsonPath('data.agents.0.completed_today', 1)
            ->assertJsonPath('data.agents.0.average_handle_time_seconds', 900);
    }

    public function test_public_availability_counts_assigned_conversations_as_active_chats(): void
    {
        app(PresenceService::class)->heartbeat($this->agent);
        app(PresenceService::class)->setSupportStatus($this->agent, 'available', true);

        SupportConversation::create([
            'guest_name' => 'Active Guest',
            'guest_email' => 'active@example.test',
            'status' => SupportConversation::STATUS_ASSIGNED,
            'priority' => 'normal',
            'channel' => 'widget',
            'assigned_to' => $this->agent->id,
        ]);

        $this->getJson('/api/support/chats/availability')
            ->assertOk()
            ->assertJsonPath('data.agents.0.public_id', $this->agent->public_id)
            ->assertJsonPath('data.agents.0.active_chats', 1)
            ->assertJsonPath('data.agents.0.agent_capacity', 3);
    }

    public function test_public_availability_includes_longest_active_chat_duration(): void
    {
        app(PresenceService::class)->heartbeat($this->agent);
        app(PresenceService::class)->setSupportStatus($this->agent, 'available', true);

        $longChat = SupportConversation::create([
            'guest_name' => 'Long Chat Guest',
            'guest_email' => 'longchat@example.test',
            'status' => SupportConversation::STATUS_ASSIGNED,
            'priority' => 'normal',
            'channel' => 'widget',
            'assigned_to' => $this->agent->id,
            'first_response_at' => now()->subMinutes(18),
        ]);
        $longChat->forceFill([
            'created_at' => now()->subMinutes(20),
            'updated_at' => now()->subMinutes(1),
        ])->save();

        $shortChat = SupportConversation::create([
            'guest_name' => 'Short Chat Guest',
            'guest_email' => 'shortchat@example.test',
            'status' => SupportConversation::STATUS_ASSIGNED,
            'priority' => 'normal',
            'channel' => 'widget',
            'assigned_to' => $this->agent->id,
            'first_response_at' => now()->subMinutes(6),
        ]);
        $shortChat->forceFill([
            'created_at' => now()->subMinutes(8),
            'updated_at' => now()->subMinutes(1),
        ])->save();

        $response = $this->getJson('/api/support/chats/availability')
            ->assertOk()
            ->assertJsonPath('data.agents.0.public_id', $this->agent->public_id)
            ->assertJsonPath('data.agents.0.active_chats', 2);

        $this->assertNotNull($response->json('data.agents.0.working_since_at'));
        $longestSeconds = (int) $response->json('data.agents.0.longest_active_chat_seconds');
        $this->assertGreaterThanOrEqual(1080, $longestSeconds);
        $this->assertLessThanOrEqual(1082, $longestSeconds);
    }

    public function test_public_availability_includes_transfers_and_survey_metrics_per_agent(): void
    {
        app(PresenceService::class)->heartbeat($this->agent);
        app(PresenceService::class)->setSupportStatus($this->agent, 'available', true);

        $transferConversation = SupportConversation::create([
            'guest_name' => 'Transfer Guest',
            'guest_email' => 'transfer@example.test',
            'status' => SupportConversation::STATUS_ASSIGNED,
            'priority' => 'normal',
            'channel' => 'widget',
            'assigned_to' => $this->agent->id,
        ]);

        SupportMessage::create([
            'conversation_id' => $transferConversation->id,
            'sender_type' => SupportMessage::SENDER_SYSTEM,
            'sender_user_id' => $this->agent->id,
            'body' => 'Transferred conversation.',
            'metadata' => ['type' => 'transfer'],
        ]);

        $surveyConversation = SupportConversation::create([
            'guest_name' => 'Survey Guest',
            'guest_email' => 'survey@example.test',
            'status' => SupportConversation::STATUS_CLOSED,
            'priority' => 'normal',
            'channel' => 'widget',
            'assigned_to' => $this->agent->id,
            'closed_at' => now(),
        ]);

        $invite = SupportSurveyInvite::create([
            'conversation_id' => $surveyConversation->id,
            'requester_user_id' => null,
            'issued_by_user_id' => $this->agent->id,
            'survey_type' => SupportSurveyInvite::TYPE_CSAT,
            'status' => SupportSurveyInvite::STATUS_RESPONDED,
            'token_hash' => hash('sha256', 'availability-csat'),
            'issued_at' => now()->subHour(),
            'responded_at' => now()->subMinutes(10),
        ]);

        SupportSurveyResponse::create([
            'invite_id' => $invite->id,
            'conversation_id' => $surveyConversation->id,
            'requester_user_id' => null,
            'rated_agent_user_id' => $this->agent->id,
            'survey_type' => 'csat',
            'score' => 5,
            'label' => 'Very satisfied',
            'comment' => null,
            'channel' => 'widget',
            'submitted_from_ip' => null,
            'submitted_user_agent' => null,
            'metadata' => null,
        ]);

        $this->getJson('/api/support/chats/availability')
            ->assertOk()
            ->assertJsonPath('data.agents.0.transfers_today', 1)
            ->assertJsonPath('data.agents.0.survey_responses_today', 1)
            ->assertJsonPath('data.agents.0.survey_csat_average_today', 5);
    }

    public function test_metrics_includes_today_trend_by_hour(): void
    {
        $this->actingAs($this->agent);

        $first = SupportConversation::create([
            'guest_name' => 'Trend One',
            'guest_email' => 'trend1@example.test',
            'status' => SupportConversation::STATUS_OPEN,
            'priority' => 'normal',
            'channel' => 'widget',
        ]);
        $first->forceFill([
            'created_at' => now()->startOfDay()->addHours(9),
            'updated_at' => now()->startOfDay()->addHours(9),
        ])->save();

        $second = SupportConversation::create([
            'guest_name' => 'Trend Two',
            'guest_email' => 'trend2@example.test',
            'status' => SupportConversation::STATUS_CLOSED,
            'priority' => 'normal',
            'channel' => 'widget',
            'closed_at' => now()->startOfDay()->addHours(11),
        ]);
        $second->forceFill([
            'created_at' => now()->startOfDay()->addHours(11),
            'updated_at' => now()->startOfDay()->addHours(11),
            'closed_at' => now()->startOfDay()->addHours(11),
        ])->save();

        $response = $this->getJson('/api/support/chats/metrics')
            ->assertOk()
            ->assertJsonPath('data.today_trend.labels.9', '09:00')
            ->assertJsonPath('data.today_trend.labels.11', '11:00')
            ->assertJsonPath('data.today_trend.incoming_chats.9', 1)
            ->assertJsonPath('data.today_trend.incoming_chats.11', 1)
            ->assertJsonPath('data.today_trend.resolved_chats.11', 1)
            ->assertJsonPath('data.today_trend.peak_hour_count', 1);

        $this->assertCount(24, $response->json('data.today_trend.labels'));
    }

    public function test_offline_agents_are_excluded_from_public_availability_list(): void
    {
        app(PresenceService::class)->setSupportStatus($this->agent, 'available', true);
        app(PresenceService::class)->markOffline($this->agent);

        $this->getJson('/api/support/chats/availability')
            ->assertOk()
            ->assertJsonPath('data.available', false)
            ->assertJsonPath('data.available_agents', 0)
            ->assertJsonPath('data.agents', []);
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
            'support_skill_id' => $this->skill->id,
        ]);

        // Enable availability for agent
        app(PresenceService::class)->setSupportAvailability($this->agent, true);

        $secondaryAgent = $this->createSkilledAgent();

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
        $secondaryAgent = $this->createSkilledAgent();

        $conversation = SupportConversation::create([
            'guest_name' => 'Test Guest',
            'guest_email' => 'guest@example.test',
            'status' => SupportConversation::STATUS_ASSIGNED,
            'assigned_to' => $this->agent->id,
            'assignment_state' => SupportConversation::ASSIGNMENT_STATE_ASSIGNED,
            'support_skill_id' => $this->skill->id,
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

        // Moving to close-out should transition to wrap_up with a resolved outcome preselected.
        $this->actingAs($this->agent)
            ->postJson("/api/support/chats/{$conversation->public_id}/resolve")
            ->assertOk()
            ->assertJsonPath('data.status', SupportConversation::STATUS_WRAP_UP)
            ->assertJsonPath('data.resolution_marker', SupportConversation::RESOLUTION_MARKER_RESOLVED);

        $this->assertDatabaseHas('support_conversations', [
            'id' => $conversation->id,
            'status' => SupportConversation::STATUS_WRAP_UP,
            'closed_at' => null,
        ]);

        // Complete close-out
        $this->actingAs($this->agent)
            ->postJson("/api/support/chats/{$conversation->public_id}/wrap-up/complete", [
                'resolved' => true,
            ])
            ->assertOk()
            ->assertJsonPath('data.status', SupportConversation::STATUS_CLOSED)
            ->assertJsonPath('data.resolution_marker', SupportConversation::RESOLUTION_MARKER_RESOLVED);

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
