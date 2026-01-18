<?php

namespace App\Services;

use App\Contracts\FaqServiceInterface;
use App\Models\FaqArticle;
use App\Models\FaqCategory;
use Illuminate\Pagination\LengthAwarePaginator;
use Illuminate\Support\Str;

class FaqService implements FaqServiceInterface
{
    public function getAllCategories(bool $publicOnly = false, ?int $perPage = null, ?string $search = null, string $sortBy = 'order', string $sortDir = 'asc', ?string $dateFrom = null, ?string $dateTo = null, ?string $status = null): \Illuminate\Contracts\Pagination\LengthAwarePaginator|\Illuminate\Database\Eloquent\Collection
    {
        // Use Scout for full-text search if search term provided
        if ($search && strlen($search) >= 2) {
            $scoutResults = FaqCategory::search($search)->get();
            $ids = $scoutResults->pluck('id')->toArray();

            if (empty($ids)) {
                // No results from Scout, return empty paginator
                return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage ?: 20);
            }

            $query = FaqCategory::with('author')
                ->withCount('articles')
                ->withSum('articles as total_views', 'views')
                ->withSum('articles as total_helpful', 'helpful_count')
                ->withSum('articles as total_unhelpful', 'unhelpful_count')
                ->whereIn('id', $ids);
        } else {
            $query = FaqCategory::with('author')
                ->withCount('articles')
                ->withSum('articles as total_views', 'views')
                ->withSum('articles as total_helpful', 'helpful_count')
                ->withSum('articles as total_unhelpful', 'unhelpful_count');
        }

        // Handle sorting - including author name via join
        $allowedSorts = ['name', 'slug', 'order', 'is_public', 'articles_count', 'total_views', 'total_helpful', 'created_at'];
        if ($sortBy === 'author') {
            $query->leftJoin('users', 'faq_categories.author_id', '=', 'users.id')
                ->orderBy('users.name', $sortDir)
                ->select('faq_categories.*');
        } elseif (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortDir);
        } else {
            $query->orderBy('order', 'asc');
        }

        if ($publicOnly) {
            $query->where('is_public', true)
                ->with(['articles' => function ($q) {
                    $q->where('is_published', '=', true);
                }]);
        }

        // Date range filter
        if ($dateFrom) {
            $query->whereDate('faq_categories.created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('faq_categories.created_at', '<=', $dateTo);
        }

        // Status filter
        if ($status === 'public') {
            $query->where('is_public', true);
        } elseif ($status === 'private') {
            $query->where('is_public', false);
        }

        if ($perPage) {
            return $query->paginate($perPage);
        }

        return $query->get();
    }

    public function createCategory(array $data, int $authorId): FaqCategory
    {
        $data['author_id'] = $authorId;

        return FaqCategory::create($data);
    }

    public function updateCategory(FaqCategory $category, array $data): FaqCategory
    {
        if (isset($data['name']) && $category->name !== $data['name']) {
            $data['slug'] = Str::slug($data['name']);
        }

        $category->update($data);

        return $category;
    }

    public function deleteCategory(FaqCategory $category): bool
    {
        return \Illuminate\Support\Facades\DB::transaction(function () use ($category) {
            // Recursively delete articles (Soft Delete)
            $category->articles()->delete();

            return (bool) $category->delete();
        });
    }

    public function getArticles(?int $categoryId = null, bool $publishedOnly = false, int $perPage = 20, ?string $search = null, string $sortBy = 'created_at', string $sortDir = 'desc', ?string $dateFrom = null, ?string $dateTo = null, ?string $status = null): LengthAwarePaginator
    {
        // Use Scout for full-text search if search term provided
        if ($search && strlen($search) >= 2) {
            $scoutQuery = FaqArticle::search($search);

            // If categoryId provided, we need to filter after Scout search
            $scoutResults = $scoutQuery->get();
            $ids = $scoutResults->pluck('id')->toArray();

            if (empty($ids)) {
                return new \Illuminate\Pagination\LengthAwarePaginator([], 0, $perPage);
            }

            $query = FaqArticle::with('category', 'author')
                ->withCount('comments')
                ->whereIn('id', $ids);
        } else {
            $query = FaqArticle::with('category', 'author')->withCount('comments');
        }

        if ($categoryId) {
            $query->where('faq_articles.category_id', $categoryId);
        }

        // Status filter (takes precedence over publishedOnly)
        if ($status === 'published') {
            $query->where('faq_articles.is_published', true);
        } elseif ($status === 'draft') {
            $query->where('faq_articles.is_published', false);
        } elseif ($publishedOnly) {
            $query->where('faq_articles.is_published', true);
            // Ensure parent category is also public
            $query->whereHas('category', function ($q) {
                $q->where('is_public', true);
            });
        }

        // Date range filter
        if ($dateFrom) {
            $query->whereDate('faq_articles.created_at', '>=', $dateFrom);
        }
        if ($dateTo) {
            $query->whereDate('faq_articles.created_at', '<=', $dateTo);
        }

        // Handle sorting - including author and category via join
        $allowedSorts = ['title', 'slug', 'views', 'helpful_count', 'unhelpful_count', 'is_published', 'created_at', 'comments_count'];
        if ($sortBy === 'author') {
            $query->leftJoin('users', 'faq_articles.author_id', '=', 'users.id')
                ->orderBy('users.name', $sortDir)
                ->select('faq_articles.*');
        } elseif ($sortBy === 'category') {
            $query->leftJoin('faq_categories', 'faq_articles.category_id', '=', 'faq_categories.id')
                ->orderBy('faq_categories.name', $sortDir)
                ->select('faq_articles.*');
        } elseif (in_array($sortBy, $allowedSorts)) {
            $query->orderBy($sortBy, $sortDir);
        } else {
            $query->latest('faq_articles.created_at');
        }

        return $query->paginate($perPage);
    }

    public function getArticleBySlug(string $slug, bool $publishedOnly = false): FaqArticle
    {
        $query = FaqArticle::where('slug', $slug)->with('category', 'author')->withCount('comments');

        if ($publishedOnly) {
            $query->where('is_published', true);
            $query->whereHas('category', function ($q) {
                $q->where('is_public', true);
            });
        }

        return $query->firstOrFail();
    }

    public function createArticle(array $data, int $authorId): FaqArticle
    {
        // Resolve category UUID to ID
        if (isset($data['category_id'])) {
            $category = FaqCategory::where('public_id', $data['category_id'])->firstOrFail();
            $data['category_id'] = $category->id;
        }

        $data['author_id'] = $authorId;

        return FaqArticle::create($data);
    }

    public function updateArticle(FaqArticle $article, array $data): FaqArticle
    {
        // Resolve category UUID to ID
        if (isset($data['category_id'])) {
            $category = FaqCategory::where('public_id', $data['category_id'])->firstOrFail();
            $data['category_id'] = $category->id;
        }

        // --- Versioning Logic (Smart/Google Docs Style) ---
        // Fields that trigger versioning
        $relevantFields = ['title', 'content', 'tags'];
        $hasChanges = false;
        foreach ($relevantFields as $field) {
            // Note: Use loose comparison or strict?
            // $article->tags is array, $data['tags'] is array.
            if (isset($data[$field]) && $data[$field] != $article->$field) {
                $hasChanges = true;
                break;
            }
        }

        if ($hasChanges) {
            // Fetch Latest Version
            $latestVersion = $article->versions()->first(); // Ordered by updated_at or created_at desc in model

            $shouldCreateNew = true;
            $currentUserId = auth()->id();

            if ($latestVersion) {
                // Check debounce window (1 hour)
                $timeDiff = $latestVersion->created_at->diffInMinutes(now());
                // Check if same SESSION user (we track who *created* the snapshot/version, wait...)
                // The 'user_id' in Version table is the AUTHOR of that snapshot content.
                // We need to track the "Editor of the Session".
                // Actually, if I am User B, and I edit User A's article.
                // I create a snapshot of User A's content.
                // Then I become the 'active editor'.
                // If I edit AGAIN in 5 mins.
                // The 'Latest Version' is the one I just created (User A's content).
                // Who created the Version ROW? The timestamp is my session start.
                // BUT the 'user_id' column stores the CONTENT author.
                // This makes "Same User" check tricky if we store Content Author.

                // Alternative: We Assume 'created_at' is the session marker.
                // If created_at < 1 hour, we squash.
                // But what if User C jumps in?
                // We don't know who "Started the session" if we don't store "Archived By".

                // Correct Fix: We should create a new version if the LAST version is old.
                // Or if we simply trust the time window.
                // For now, Time Window is robust enough for "Google Docs Style".
                // If multiple users edit concurrently, they usually overwrite each other anyway unless we have lock.
                // So generic Time Window is acceptable.

                if ($timeDiff < 60) {
                    $shouldCreateNew = false;
                }
            }

            if ($shouldCreateNew) {
                // Archive Current (Old) State
                $article->versions()->create([
                    'user_id' => $currentUserId ?? $article->author_id, // Attribute snapshot to editor
                    'title' => $article->title,
                    'content' => $article->content,
                    'tags' => $article->tags,
                ]);
            } else {
                // Update timestamp of latest version to extend the "session" visibility
                $latestVersion->touch();
            }
        }

        if (isset($data['title']) && $article->title !== $data['title']) {
            $data['slug'] = Str::slug($data['title']);
        }

        $article->update($data);

        return $article;
    }

    public function restoreVersion(FaqArticle $article, \App\Models\FaqArticleVersion $version): FaqArticle
    {
        // 1. Force Archive current state before restoring
        $article->versions()->create([
            'user_id' => auth()->id() ?? $article->author_id,
            'title' => $article->title,
            'content' => $article->content,
            'tags' => $article->tags,
        ]);

        // 2. Restore content
        $article->update([
            'title' => $version->title,
            'content' => $version->content,
            'tags' => $version->tags,
            // Re-slugify if title changed
            'slug' => Str::slug($version->title),
        ]);

        return $article;
    }

    public function deleteArticle(FaqArticle $article): bool
    {
        return (bool) $article->delete();
    }

    public function voteArticle(FaqArticle $article, bool $isHelpful): FaqArticle
    {
        if ($isHelpful) {
            $article->increment('helpful_count');
        } else {
            $article->increment('unhelpful_count');
        }

        return $article->fresh();
    }

    /**
     * Bulk publish/unpublish articles
     *
     * @param  array  $ids  Array of article public_ids
     * @param  bool  $publish  True to publish, false to unpublish
     * @return int Number of articles updated
     */
    public function bulkPublishArticles(array $ids, bool $publish = true): int
    {
        return FaqArticle::whereIn('public_id', $ids)
            ->update(['is_published' => $publish]);
    }

    /**
     * Bulk publish/unpublish categories
     *
     * @param  array  $ids  Array of category ids
     * @param  bool  $public  True to publish, false to unpublish
     * @return int Number of categories updated
     */
    public function bulkPublishCategories(array $ids, bool $public = true): int
    {
        return FaqCategory::whereIn('public_id', $ids)
            ->update(['is_public' => $public]);
    }
}
