<?php

namespace App\Contracts;

use App\Models\AppReview;
use App\Models\User;
use Illuminate\Contracts\Pagination\LengthAwarePaginator;
use Illuminate\Support\Collection;

interface AppReviewServiceContract
{
    /**
     * List published reviews.
     */
    public function listPublished(int $limit = 10): Collection;

    /**
     * List all reviews for moderation (paginated).
     */
    public function listForModeration(int $perPage = 20, ?string $search = null): LengthAwarePaginator;

    /**
     * Submit a new review.
     *
     * @param array<string, mixed> $data
     */
    public function submit(User $user, array $data): AppReview;

    /**
     * Update review publication status.
     */
    public function updateStatus(AppReview $review, bool $isPublished): AppReview;

    /**
     * Get sentiment statistics.
     */
    public function getSentimentStats(): array;
}
