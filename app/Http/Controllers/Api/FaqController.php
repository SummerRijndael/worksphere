<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FaqArticle;
use App\Models\User;
use Illuminate\Http\Request;
use Illuminate\Support\Facades\Cache;
use Spatie\Permission\Exceptions\PermissionDoesNotExist;

class FaqController extends Controller
{
    public function __construct(protected \App\Contracts\FaqServiceInterface $faqService) {}

    private function canViewInternalFaq(?User $user): bool
    {
        if (! $user) {
            return false;
        }

        $agentRoles = (array) config('support_chat.agent_roles', ['administrator', 'it_support', 'support']);
        if ($user->hasAnyRole($agentRoles) || $user->hasRole('administrator')) {
            return true;
        }

        try {
            return $user->hasPermissionTo('faq.manage')
                || $user->hasPermissionTo('support.chats.view')
                || $user->hasPermissionTo('support.chats.reply');
        } catch (PermissionDoesNotExist) {
            return false;
        }
    }

    private function ensureArticleIsAccessible(FaqArticle $article, bool $allowInternal = false): void
    {
        $article->loadMissing('category');

        if (! $article->is_published || ! $article->category || ! $article->category->is_public) {
            abort(404, 'FAQ article not found.');
        }

        if ($article->is_internal && ! $allowInternal) {
            abort(404, 'FAQ article not found.');
        }
    }

    /**
     * Get a unique visitor key using hybrid approach: user_id > fingerprint > IP
     */
    private function getVisitorKey(Request $request, string $prefix, int $articleId): string
    {
        // Priority 1: Authenticated user
        if ($request->user()) {
            return "{$prefix}:{$articleId}:user:{$request->user()->id}";
        }

        // Priority 2: Browser fingerprint (from header or body)
        $fingerprint = $request->header('X-Fingerprint') ?? $request->input('fingerprint');
        if ($fingerprint && strlen($fingerprint) >= 16) {
            return "{$prefix}:{$articleId}:fp:{$fingerprint}";
        }

        // Priority 3: IP address (fallback)
        return "{$prefix}:{$articleId}:ip:{$request->ip()}";
    }

    /**
     * Search FAQ articles using Laravel Scout.
     */
    public function search(Request $request)
    {
        $query = $request->input('q');
        $allowInternal = $this->canViewInternalFaq($request->user());

        if (! $query) {
            return \App\Http\Resources\Faq\FaqArticleResource::collection([]);
        }

        $articles = FaqArticle::search($query)
            ->query(fn ($q) => $q->where('is_published', true)
                ->whereHas('category', fn ($c) => $c->where('is_public', true))
                ->when(! $allowInternal, fn ($builder) => $builder->where('is_internal', false))
                ->with('category')
            )
            ->get();

        return \App\Http\Resources\Faq\FaqArticleResource::collection($articles);
    }

    /**
     * List all public categories and their published articles.
     */
    public function index()
    {
        $allowInternal = $this->canViewInternalFaq(request()->user());
        $categories = $this->faqService->getAllCategories(true, null, null, 'order', 'asc', null, null, null, $allowInternal);

        return \App\Http\Resources\Faq\FaqCategoryResource::collection($categories);
    }

    /**
     * Get a specific article by slug.
     */
    public function show(Request $request, $slug)
    {
        $allowInternal = $this->canViewInternalFaq($request->user());
        $article = $this->faqService->getArticleBySlug($slug, true, $allowInternal);
        $this->ensureArticleIsAccessible($article, $allowInternal);
        $article->load(['comments' => function ($query) {
            $query->where('is_approved', true)->orderBy('created_at', 'desc')->take(10);
        }, 'comments.user']);

        // Increment views using hybrid visitor key (1 hour window)
        $viewCacheKey = $this->getVisitorKey($request, 'faq_view', $article->id);
        if (! Cache::has($viewCacheKey)) {
            $article->increment('views', 1, []);
            Cache::put($viewCacheKey, true, now()->addHour());
        }

        return new \App\Http\Resources\Faq\FaqArticleResource($article);
    }

    /**
     * Vote on an article.
     */
    public function vote(Request $request, FaqArticle $article)
    {
        $request->validate([
            'is_helpful' => 'required|boolean',
            'fingerprint' => 'nullable|string|min:16|max:64',
        ]);
        $this->ensureArticleIsAccessible($article, $this->canViewInternalFaq($request->user()));

        // Check if this visitor has already voted using hybrid key
        $voteCacheKey = $this->getVisitorKey($request, 'faq_vote', $article->id);
        if (Cache::has($voteCacheKey)) {
            return response()->json([
                'message' => 'You have already voted on this article.',
                'already_voted' => true,
            ], 422);
        }

        $updatedArticle = $this->faqService->voteArticle($article, $request->boolean('is_helpful'));

        // Mark this visitor as having voted (persist for 30 days)
        Cache::put($voteCacheKey, $request->boolean('is_helpful') ? 'up' : 'down', now()->addDays(30));

        return new \App\Http\Resources\Faq\FaqArticleResource($updatedArticle);
    }

    /**
     * Store a new comment for an article.
     */
    public function comment(\App\Http\Requests\Faq\StoreFaqCommentRequest $request, FaqArticle $article, \App\Services\RecaptchaService $recaptcha)
    {
        $this->ensureArticleIsAccessible($article, $this->canViewInternalFaq($request->user()));
        $input = $request->validated();

        // Check V2 first if provided
        if (! empty($input['recaptcha_v2_token'])) {
            $v2Result = $recaptcha->verifyV2($input['recaptcha_v2_token'], $request->ip());
            if (! $v2Result['success']) {
                return response()->json([
                    'message' => 'Security challenge failed. Please try again.',
                    'errors' => ['recaptcha' => ['Security challenge failed.']],
                ], 422);
            }
        } else {
            // Verify V3
            $v3Result = $recaptcha->verify($input['recaptcha_token'], 'faq_comment', $request->ip());
            if (! $v3Result['success']) {
                // If V3 fails (score too low), require V2
                return response()->json([
                    'message' => 'Suspicious activity detected. Please complete the security challenge.',
                    'require_v2' => true,
                ], 422);
            }
        }

        $comment = $article->comments()->create([
            'content' => $input['content'],
            'name' => $request->user()?->name ?? $input['name'] ?? 'Guest',
            'user_id' => $request->user()?->id,
            'ip_address' => $request->ip(),
            'is_approved' => true, // Auto-approve for now
        ]);

        return response()->json([
            'message' => 'Comment posted successfully.',
            'comment' => $comment,
        ]);
    }

    /**
     * Get paginated comments for an article.
     */
    public function getComments(Request $request, FaqArticle $article)
    {
        $this->ensureArticleIsAccessible($article, $this->canViewInternalFaq($request->user()));

        $comments = $article->comments()
            ->where('is_approved', true)
            ->with('user')
            ->orderBy('created_at', 'desc')
            ->paginate(10);

        return \App\Http\Resources\Faq\FaqCommentResource::collection($comments);
    }

    /**
     * List published FAQ categories/articles for internal staff usage (includes internal articles).
     */
    public function internalIndex(Request $request)
    {
        abort_unless($this->canViewInternalFaq($request->user()), 403, 'You are not allowed to access internal FAQ.');

        $categories = $this->faqService->getAllCategories(true, null, null, 'order', 'asc', null, null, null, true);

        return \App\Http\Resources\Faq\FaqCategoryResource::collection($categories);
    }

    /**
     * Search published FAQ articles for internal staff usage (includes internal articles).
     */
    public function internalSearch(Request $request)
    {
        abort_unless($this->canViewInternalFaq($request->user()), 403, 'You are not allowed to access internal FAQ.');

        $query = $request->input('q');

        if (! $query) {
            return \App\Http\Resources\Faq\FaqArticleResource::collection([]);
        }

        $articles = FaqArticle::search($query)
            ->query(fn ($q) => $q->where('is_published', true)
                ->whereHas('category', fn ($c) => $c->where('is_public', true))
                ->with('category')
            )
            ->get();

        return \App\Http\Resources\Faq\FaqArticleResource::collection($articles);
    }

    /**
     * Get a published internal/public article by slug for staff usage.
     */
    public function internalShow(Request $request, $slug)
    {
        abort_unless($this->canViewInternalFaq($request->user()), 403, 'You are not allowed to access internal FAQ.');

        $article = $this->faqService->getArticleBySlug($slug, true, true);
        $this->ensureArticleIsAccessible($article, true);
        $article->load(['comments' => function ($query) {
            $query->where('is_approved', true)->orderBy('created_at', 'desc')->take(10);
        }, 'comments.user']);

        $viewCacheKey = $this->getVisitorKey($request, 'faq_view', $article->id);
        if (! Cache::has($viewCacheKey)) {
            $article->increment('views', 1, []);
            Cache::put($viewCacheKey, true, now()->addHour());
        }

        return new \App\Http\Resources\Faq\FaqArticleResource($article);
    }
}
