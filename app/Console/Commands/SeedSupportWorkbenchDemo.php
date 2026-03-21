<?php

namespace App\Console\Commands;

use App\Models\SupportConversation;
use App\Models\SupportMessage;
use App\Models\User;
use Illuminate\Console\Command;
use Illuminate\Support\Facades\DB;

class SeedSupportWorkbenchDemo extends Command
{
    protected $signature = 'support:seed-workbench-demo
        {--agent=3 : Agent user id or public_id that receives seeded chats}
        {--count=7 : Number of active demo conversations to create}
        {--messages=6 : Number of messages per conversation}
        {--reset : Delete prior demo chats for this agent before seeding}';

    protected $description = 'Seed demo support conversations/messages for the support workbench UI.';

    public function handle(): int
    {
        $agentOption = (string) $this->option('agent');
        $count = max(1, (int) $this->option('count'));
        $messagesPerConversation = max(2, (int) $this->option('messages'));

        $agent = $this->resolveAgent($agentOption);
        if (! $agent) {
            $this->error("Agent [{$agentOption}] was not found.");

            return self::FAILURE;
        }

        if ($this->option('reset')) {
            $deleted = SupportConversation::query()
                ->where('assigned_to', $agent->id)
                ->where('metadata->seeded_by', 'support_workbench_demo')
                ->delete();

            $this->info("Removed {$deleted} existing demo conversations for {$agent->name}.");
        }

        $priorities = ['low', 'normal', 'high', 'urgent'];
        $topics = [
            'billing adjustment',
            'API key rotation',
            'login verification',
            'workspace permissions',
            'report export issue',
            'chat notification delay',
            'file upload warning',
            'subscription invoice',
        ];

        $created = [];

        for ($i = 1; $i <= $count; $i++) {
            DB::transaction(function () use (
                $i,
                $count,
                $agent,
                $messagesPerConversation,
                $priorities,
                $topics,
                &$created
            ): void {
                $guestName = "Demo Customer {$i}";
                $guestEmail = "demo-customer-{$i}@example.test";
                $topic = $topics[($i - 1) % count($topics)];
                $openedAt = now()->subMinutes(($count - $i + 1) * 7);

                $conversation = SupportConversation::create([
                    'requester_user_id' => null,
                    'guest_name' => $guestName,
                    'guest_email' => $guestEmail,
                    'guest_token' => \Illuminate\Support\Str::random(64),
                    'status' => SupportConversation::STATUS_ASSIGNED,
                    'priority' => $priorities[($i - 1) % count($priorities)],
                    'channel' => 'widget',
                    'subject' => "Workbench demo: {$topic}",
                    'source_url' => config('app.url'),
                    'assigned_to' => $agent->id,
                    'ai_enabled' => false,
                    'ai_handoff_required' => false,
                    'metadata' => [
                        'seeded_by' => 'support_workbench_demo',
                        'demo_index' => $i,
                        'demo_topic' => $topic,
                    ],
                    'last_message_at' => $openedAt,
                ]);

                $firstAgentReplyAt = null;
                $lastMessageAt = $openedAt;

                for ($m = 1; $m <= $messagesPerConversation; $m++) {
                    $isCustomer = $m % 2 === 1;
                    $senderType = $isCustomer ? SupportMessage::SENDER_CUSTOMER : SupportMessage::SENDER_AGENT;
                    $senderUserId = $isCustomer ? null : $agent->id;

                    $body = $isCustomer
                        ? "Hi support, I need help with {$topic}. Step {$m}."
                        : "Thanks for the details. Here's update {$m} from {$agent->name}.";

                    $message = SupportMessage::create([
                        'conversation_id' => $conversation->id,
                        'sender_type' => $senderType,
                        'sender_user_id' => $senderUserId,
                        'body' => $body,
                        'is_private_note' => false,
                        'metadata' => [
                            'seeded_by' => 'support_workbench_demo',
                            'demo_index' => $i,
                            'message_step' => $m,
                        ],
                    ]);

                    $messageAt = $openedAt->copy()->addMinutes($m);
                    DB::table('support_messages')
                        ->where('id', $message->id)
                        ->update([
                            'created_at' => $messageAt,
                            'updated_at' => $messageAt,
                        ]);

                    if (! $isCustomer && $firstAgentReplyAt === null) {
                        $firstAgentReplyAt = $messageAt;
                    }

                    $lastMessageAt = $messageAt;
                }

                DB::table('support_conversations')
                    ->where('id', $conversation->id)
                    ->update([
                        'first_response_at' => $firstAgentReplyAt,
                        'last_message_at' => $lastMessageAt,
                        'created_at' => $openedAt,
                        'updated_at' => $lastMessageAt,
                    ]);

                $created[] = $conversation->public_id;
            });
        }

        $this->info("Seeded ".count($created)." support conversations for {$agent->name}.");
        $this->line('Conversation IDs:');
        foreach ($created as $id) {
            $this->line("- {$id}");
        }

        return self::SUCCESS;
    }

    protected function resolveAgent(string $value): ?User
    {
        $trimmed = trim($value);
        if ($trimmed === '') {
            return null;
        }

        if (ctype_digit($trimmed)) {
            return User::query()->find((int) $trimmed);
        }

        return User::query()->where('public_id', $trimmed)->first();
    }
}

