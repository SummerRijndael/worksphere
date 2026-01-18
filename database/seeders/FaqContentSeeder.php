<?php

namespace Database\Seeders;

use App\Models\FaqArticle;
use App\Models\FaqCategory;
use App\Models\User;
use Illuminate\Database\Seeder;
use Illuminate\Support\Str;

class FaqContentSeeder extends Seeder
{
    /**
     * Run the database seeds.
     */
    public function run(): void
    {
        $user = User::first();

        if (! $user) {
            $this->command->error('No users found. Please seed users first.');

            return;
        }

        $categories = ['Billing', 'Accounts', 'How to'];

        foreach ($categories as $index => $catName) {
            $category = FaqCategory::firstOrCreate(
                ['name' => $catName],
                [
                    'slug' => Str::slug($catName),
                    'description' => "All about $catName",
                    'order' => $index + 1,
                    'is_public' => true,
                    'author_id' => $user->id,
                ]
            );

            $this->command->info("Created/Found Category: $catName");

            // Create 3 articles for each category
            for ($i = 1; $i <= 3; $i++) {
                $title = "$catName Article $i";
                FaqArticle::create([
                    'category_id' => $category->id,
                    'title' => $title,
                    'slug' => Str::slug($title).'-'.Str::random(5),
                    'content' => "<p>This is a sample article for <strong>$catName</strong>. It explains how to handle various tasks related to $catName.</p><ul><li>Step 1: Do this</li><li>Step 2: Do that</li></ul>",
                    'is_published' => true,
                    'views' => rand(10, 500),
                    'helpful_count' => rand(0, 50),
                    'unhelpful_count' => rand(0, 10),
                    'author_id' => $user->id,
                    'tags' => ['sample', strtolower($catName)],
                ]);
            }
        }

        $this->command->info('FAQ Content seeded successfully!');
    }
}
