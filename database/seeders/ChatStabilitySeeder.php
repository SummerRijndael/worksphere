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
            $this->command->info('No existing DM or Group chats found. Please create some chats first.');

            return;
        }

        $totalMessages = 0;

        foreach ($chats as $chat) {
            $participants = $chat->participants;

            if ($participants->isEmpty()) {
                continue;
            }

            // Generate 50-100 messages purely for stability testing (scroll, wrap, replies)
            $count = rand(50, 100);
            $messageIds = [];

            $this->command->info("Seeding {$chat->type} chat {$chat->id} with {$count} messages...");

            for ($i = 0; $i < $count; $i++) {
                $sender = $participants->random();

                // Content Generation
                $isLongMessage = ($i % 10 === 0); // Every 10th message is long

                if ($isLongMessage) {
                    // Long message for wrapping test (no spaces to force break-all or spaces for wrapping)
                    // We mix both: a block of text and a long unbroken string
                    $content = $faker->paragraph(10)."\n\n".
                               'LONG_STRING_TEST_'.str_repeat('AaBbCcDdEeFfGgHh', 20).
                               "\n\n".$faker->paragraph(5);
                } else {
                    $content = $faker->realText(rand(20, 200));
                }

                // Reply Logic
                $replyToId = null;
                if (! empty($messageIds) && mt_rand(1, 100) <= 20) { // 20% chance to reply
                    $replyToId = $messageIds[array_rand($messageIds)];
                }

                // Create Message
                $message = ChatMessage::create([
                    'chat_id' => $chat->id,
                    'user_id' => $sender->id,
                    'content' => $content,
                    'reply_to_message_id' => $replyToId,
                    'created_at' => now()->subMinutes($count - $i), // Reverse chronological creation for timeline sane-ness
                    'updated_at' => now()->subMinutes($count - $i),
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

        $this->command->info("Chat Stability Seeder Complete! Seeded {$totalMessages} messages across {$chats->count()} chats.");
    }
}
