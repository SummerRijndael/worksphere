<?php

namespace Database\Seeders;

use App\Models\FaqArticle;
use App\Models\FaqCategory;
use App\Models\User;
use Illuminate\Database\Seeder;

class FaqAiAccessTestSeeder extends Seeder
{
    /**
     * Seed deterministic FAQ fixtures for AI retrieval testing.
     */
    public function run(): void
    {
        $author = User::query()->first();

        if (! $author) {
            $this->command?->error('FaqAiAccessTestSeeder: no users found. Seed users first.');

            return;
        }

        $publicCategory = $this->upsertCategory([
            'name' => 'AI Test Public Support',
            'slug' => 'ai-test-public-support',
            'description' => 'Public support knowledge used to validate Eden FAQ retrieval.',
            'order' => 910,
            'is_public' => true,
            'author_id' => $author->id,
        ]);

        $internalCategory = $this->upsertCategory([
            'name' => 'AI Test Internal Ops',
            'slug' => 'ai-test-internal-ops',
            'description' => 'Internal-only knowledge that Eden must not use for public answers.',
            'order' => 911,
            'is_public' => false,
            'author_id' => $author->id,
        ]);

        $fixtures = [
            [
                'category_id' => $publicCategory->id,
                'title' => 'AI Test: Password Reset Flow (Public)',
                'slug' => 'ai-test-public-password-reset',
                'is_published' => true,
                'is_internal' => false,
                'views' => 250,
                'helpful_count' => 34,
                'unhelpful_count' => 2,
                'author_id' => $author->id,
                'tags' => ['ai-test', 'public', 'password-reset'],
                'content' => <<<'HTML'
<h2>WS-PUBLIC-FAQ-RESET-9001</h2>
<p>If a customer cannot sign in, use this reset flow:</p>
<ol>
  <li>Go to <strong>/forgot-password</strong>.</li>
  <li>Submit the account email.</li>
  <li>Use the reset link sent to the inbox.</li>
  <li>If no email is received in 5 minutes, ask support to verify delivery logs.</li>
</ol>
<p>Escalate when reset links fail repeatedly after two attempts.</p>
HTML,
            ],
            [
                'category_id' => $publicCategory->id,
                'title' => 'AI Test: Billing Update Window (Public)',
                'slug' => 'ai-test-public-billing-window',
                'is_published' => true,
                'is_internal' => false,
                'views' => 190,
                'helpful_count' => 29,
                'unhelpful_count' => 1,
                'author_id' => $author->id,
                'tags' => ['ai-test', 'public', 'billing'],
                'content' => <<<'HTML'
<h2>WS-PUBLIC-FAQ-BILLING-1337</h2>
<p>Billing profile updates are processed instantly for card metadata and within <strong>15 minutes</strong> for tax fields.</p>
<ul>
  <li>Card brand / last4: immediate</li>
  <li>Billing address and VAT ID: up to 15 minutes</li>
  <li>Invoice regeneration: next billing cycle only</li>
</ul>
HTML,
            ],
            [
                'category_id' => $publicCategory->id,
                'title' => 'AI Test: Internal Flag in Public Category (Should Be Hidden)',
                'slug' => 'ai-test-hidden-internal-flagged-article',
                'is_published' => true,
                'is_internal' => true,
                'views' => 12,
                'helpful_count' => 0,
                'unhelpful_count' => 0,
                'author_id' => $author->id,
                'tags' => ['ai-test', 'internal', 'hidden'],
                'content' => <<<'HTML'
<h2>WS-INTERNAL-DO-NOT-EXPOSE-4242</h2>
<p>This article is intentionally internal and must not be included in Eden public FAQ context.</p>
HTML,
            ],
            [
                'category_id' => $internalCategory->id,
                'title' => 'AI Test: Internal Escalation Matrix (Private Category)',
                'slug' => 'ai-test-internal-escalation-matrix',
                'is_published' => true,
                'is_internal' => false,
                'views' => 5,
                'helpful_count' => 0,
                'unhelpful_count' => 0,
                'author_id' => $author->id,
                'tags' => ['ai-test', 'internal-category', 'ops'],
                'content' => <<<'HTML'
<h2>WS-INTERNAL-CATEGORY-9911</h2>
<p>Private routing runbook for internal agents only.</p>
HTML,
            ],
            [
                'category_id' => $publicCategory->id,
                'title' => 'AI Test: Draft Article (Unpublished)',
                'slug' => 'ai-test-public-unpublished-draft',
                'is_published' => false,
                'is_internal' => false,
                'views' => 0,
                'helpful_count' => 0,
                'unhelpful_count' => 0,
                'author_id' => $author->id,
                'tags' => ['ai-test', 'draft'],
                'content' => <<<'HTML'
<h2>WS-DRAFT-UNPUBLISHED-7007</h2>
<p>This draft must not be visible to Eden FAQ retrieval.</p>
HTML,
            ],
        ];

        foreach ($fixtures as $fixture) {
            $this->upsertArticle($fixture);
        }

        $this->command?->info('FaqAiAccessTestSeeder complete.');
        $this->command?->info('Public test tokens: WS-PUBLIC-FAQ-RESET-9001, WS-PUBLIC-FAQ-BILLING-1337');
        $this->command?->info('Hidden control tokens: WS-INTERNAL-DO-NOT-EXPOSE-4242, WS-INTERNAL-CATEGORY-9911, WS-DRAFT-UNPUBLISHED-7007');
    }

    protected function upsertCategory(array $attributes): FaqCategory
    {
        $category = FaqCategory::withTrashed()->where('slug', $attributes['slug'])->first();

        if ($category) {
            $category->fill($attributes);
            if ($category->trashed()) {
                $category->restore();
            }
            $category->save();

            return $category;
        }

        return FaqCategory::create($attributes);
    }

    protected function upsertArticle(array $attributes): FaqArticle
    {
        $article = FaqArticle::withTrashed()->where('slug', $attributes['slug'])->first();

        if ($article) {
            $article->fill($attributes);
            if ($article->trashed()) {
                $article->restore();
            }
            $article->save();

            return $article;
        }

        return FaqArticle::create($attributes);
    }
}
