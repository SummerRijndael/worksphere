<?php

namespace App\Http\Controllers\Api;

use App\Contracts\AppReviewServiceContract;
use App\Http\Controllers\Controller;
use App\Http\Resources\AppReviewResource;
use App\Models\AppReview;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Http\Resources\Json\AnonymousResourceCollection;

class ReviewController extends Controller
{
    public function __construct(
        protected AppReviewServiceContract $reviewService
    ) {}
    /**
     * Display a listing of published reviews.
     */
    public function index(): AnonymousResourceCollection
    {
        $reviews = $this->reviewService->listPublished();

        return AppReviewResource::collection($reviews);
    }

    /**
     * Store a newly created review in storage.
     */
    public function store(Request $request): JsonResponse
    {
        $this->authorize('create', AppReview::class);

        $validated = $request->validate([
            'rating' => 'required|integer|min:1|max:5',
            'comment' => 'required|string|max:1000',
        ]);

        $review = $this->reviewService->submit($request->user(), $validated);

        return response()->json([
            'message' => 'Review submitted successfully and is awaiting moderation.',
            'data' => new AppReviewResource($review),
        ], 201);
    }

    /**
     * Display a listing of all reviews for admin moderation.
     */
    public function adminIndex(Request $request): AnonymousResourceCollection
    {
        $this->authorize('viewAny', AppReview::class);

        $reviews = $this->reviewService->listForModeration(
            (int) $request->get('per_page', 20),
            $request->get('search')
        );

        return AppReviewResource::collection($reviews);
    }

    /**
     * Update the published status of a review.
     */
    public function updateStatus(Request $request, AppReview $review): JsonResponse
    {
        $this->authorize('update', $review);

        $validated = $request->validate([
            'is_published' => 'required|boolean',
        ]);

        $review = $this->reviewService->updateStatus($review, $validated['is_published']);

        return response()->json([
            'message' => 'Review status updated successfully.',
            'data' => new AppReviewResource($review),
        ]);
    }
}
