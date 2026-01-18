<?php

namespace Database\Seeders;

use App\Models\FaqArticle;
use App\Models\FaqComment;
use App\Models\User;
use Faker\Factory as Faker;
use Illuminate\Database\Seeder;

class FaqCommentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $faker = Faker::create();

        $articleIds = FaqArticle::where('is_published', true)->pluck('id')->toArray();
        $userIds = User::pluck('id')->toArray();

        if (empty($articleIds)) {
            $this->command->error('No published FAQ articles found. Please seed articles first.');

            return;
        }

        $this->command->info('Creating test comments for '.count($articleIds).' articles...');

        foreach ($articleIds as $articleId) {
            // Generate 15-25 comments per article to ensure pagination (limit 10) is triggered
            $count = rand(15, 25);

            for ($i = 0; $i < $count; $i++) {
                // 50% chance of being a registered user
                $isUser = $faker->boolean(50) && ! empty($userIds);
                $userId = $isUser ? $faker->randomElement($userIds) : null;

                // Name logic
                if ($isUser) {
                    $user = User::find($userId);
                    $name = $user ? $user->name : 'Unknown User';
                } else {
                    $name = 'Guest '.$faker->firstName;
                }

                // Content Type logic
                $type = $faker->randomElement(['short', 'mid', 'long', 'xss', 'link', 'youtube']);

                $content = match ($type) {
                    'short' => $faker->sentence(rand(3, 10)),
                    'mid' => $faker->paragraph(rand(2, 5)),
                    'long' => $faker->paragraphs(rand(10, 20), true)."\n\n".$faker->paragraphs(5, true), // > 1000 chars
                    'xss' => '<script>alert("XSS")</script> <img src=x onerror=alert(1)> Check this out!',
                    'link' => 'Check out this awesome resource: https://example.com/resource and also http://google.com',
                    'youtube' => 'Here is a video tutorial: https://www.youtube.com/watch?v=dQw4w9WgXcQ',
                };

                FaqComment::create([
                    'faq_article_id' => $articleId,
                    'user_id' => $userId,
                    'name' => $name,
                    'content' => $content,
                    'is_approved' => true,
                    'ip_address' => $faker->ipv4,
                    'created_at' => $faker->dateTimeBetween('-3 months', 'now'),
                    'updated_at' => now(),
                ]);
            }
        }

        $this->command->info('Comments seeded successfully!');
    }
}
