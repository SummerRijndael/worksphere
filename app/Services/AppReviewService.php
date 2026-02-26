<?php

namespace App\Services;

use App\Contracts\AppReviewServiceContract;
use App\Models\AppReview;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;
use Illuminate\Support\Facades\Cache;

class AppReviewService implements AppReviewServiceContract
{
    /**
     * List published reviews.
     */
    public function listPublished(int $limit = 10): Collection
    {
        return AppReview::with('user:id,name,public_id')
            ->published()
            ->latest()
            ->limit($limit)
            ->get();
    }

    /**
     * List all reviews for moderation (paginated).
     */
    public function listForModeration(int $perPage = 20, ?string $search = null): LengthAwarePaginator
    {
        $query = AppReview::with('user:id,name,email,public_id');

        if ($search) {
            $query->where(function ($q) use ($search) {
                $q->where('comment', 'like', "%{$search}%")
                  ->orWhereHas('user', function ($uq) use ($search) {
                      $uq->where('name', 'like', "%{$search}%")
                        ->orWhere('email', 'like', "%{$search}%");
                  });
            });
        }

        return $query->latest()->paginate($perPage);
    }

    /**
     * Submit a new review.
     */
    public function submit(User $user, array $data): AppReview
    {
        $review = AppReview::create([
            'user_id' => $user->id,
            'rating' => $data['rating'],
            'comment' => $this->sanitizeComment($data['comment']),
            'is_published' => false, // Always default to false for moderation
        ]);

        Cache::forget('app_review_sentiment_stats');

        return $review->load('user:id,name,public_id');
    }

    /**
     * Update review publication status.
     */
    public function updateStatus(AppReview $review, bool $isPublished): AppReview
    {
        $review->update([
            'is_published' => $isPublished,
        ]);

        Cache::forget('app_review_sentiment_stats');

        return $review->load('user:id,name,public_id');
    }

    /**
     * Get sentiment statistics.
     */
    public function getSentimentStats(): array
    {
        return Cache::remember('app_review_sentiment_stats', 3600, function () {
            $avg = AppReview::published()->avg('rating') ?: 0;
            $total = AppReview::published()->count();
            
            $status = 'Mixed';
            if ($avg >= 4.5) $status = 'Excellent';
            elseif ($avg >= 3.5) $status = 'Positive';
            elseif ($avg < 2.5 && $total > 0) $status = 'Needs Attention';

            return [
                'average_rating' => round($avg, 1),
                'total_reviews' => $total,
                'vibe_status' => $status,
            ];
        });
    }

    /**
     * Sanitize the review comment.
     */
    private function sanitizeComment(string $comment): string
    {
        // Strip URLs to prevent spam
        $comment = preg_replace('/https?:\/\/\S+/i', '[URL Removed]', $comment);
        
        // Trim and strip tags
        return strip_tags(trim($comment));
    }
}
