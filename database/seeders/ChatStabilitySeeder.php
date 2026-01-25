<?php

namespace Database\Seeders;

use App\Models\Chat\Chat;
use App\Models\Chat\ChatMessage;
use Faker\Factory as Faker;
use Illuminate\Database\Seeder;

class ChatStabilitySeeder extends Seeder
{
    public function run(): void
    {
        $faker = Faker::create();

        // 1. Fetch existing DM and Group chats
        $chats = Chat::whereIn('type', ['dm', 'group'])
            ->with('participants')
            ->get();

        if ($chats->isEmpty()) {
            if ($this->command) {
                $this->command->info('No existing DM or Group chats found. Please create some chats first.');
            }

            return;
        }

        $totalMessages = 0;

        foreach ($chats as $chat) {
            $participants = $chat->participants;

            if ($participants->isEmpty()) {
                continue;
            }

            // Generate 100-200 messages over 12 months for stability testing
            $count = rand(100, 200);
            $messageIds = [];

            if ($this->command) {
                $this->command->info("Seeding {$chat->type} chat {$chat->id} with {$count} messages over 12 months...");
            }

            // Distribute messages over last 12 months
            $startTime = now()->subMonths(12);
            $currentTime = clone $startTime;
            $intervalMinutes = (12 * 30 * 24 * 60) / $count;

            for ($i = 0; $i < $count; $i++) {
                $sender = $participants->random();
                $currentTime->addMinutes(rand(1, $intervalMinutes * 2));
                
                if ($currentTime->isFuture()) {
                    $currentTime = now()->subMinutes($count - $i);
                }

                // Content Generation
                $dice = rand(1, 100);
                
                if ($dice > 90) { // 10% Long messages
                    $content = $faker->paragraph(10)."\n\n".
                               'LONG_STRING_TEST_'.str_repeat('AaBbCcDdEeFfGgHh', 20).
                               "\n\n".$faker->paragraph(5);
                } elseif ($dice > 70) { // 20% Short reactions/snippets
                    $content = $faker->randomElement(['LGTM!', 'Thanks!', 'Looking into it.', 'Done.', 'Can we hop on a call?', '🚀', '💯', 'Acknowledged.']);
                } else { // 70% Normal messages
                    $content = $faker->realText(rand(20, 200));
                }

                // Reply Logic
                $replyToId = null;
                if (! empty($messageIds) && mt_rand(1, 100) <= 25) { // 25% chance to reply
                    $replyToId = $messageIds[array_rand($messageIds)];
                }

                // Create Message
                $message = ChatMessage::create([
                    'chat_id' => $chat->id,
                    'user_id' => $sender->id,
                    'content' => $content,
                    'reply_to_message_id' => $replyToId,
                    'created_at' => $currentTime,
                    'updated_at' => $currentTime,
                ]);

                $messageIds[] = $message->id;
                $totalMessages++;
            }

            // Update last message pointer for the chat
            $lastMessage = ChatMessage::where('chat_id', $chat->id)->latest('id')->first();
            if ($lastMessage) {
                $chat->updated_at = $lastMessage->created_at;
                $chat->save();
            }
        }

        if ($this->command) {
            $this->command->info("Chat Stability Seeder Complete! Seeded {$totalMessages} messages across {$chats->count()} chats.");
        }
    }
}
