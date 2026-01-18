<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FaqArticle;
use App\Models\FaqCategory;
use Illuminate\Http\Request;

class FaqManageController extends Controller
{
    public function __construct(protected \App\Contracts\FaqServiceInterface $faqService)
    {
        // Enforce policies via middleware or in methods.
        // Since we are using FormRequests with authorize(), standard resourceful policy mapping or manual checks are good.
        // We will rely on FormRequest authorize() for writes, and manual check/policy middleware for reads/list if needed.
        // The router already applies 'permission:settings.update' to this whole group, but we want granular checks.
        // However, user asked for 'permissions config', so we should respect that.
        // The router currently has `middleware('permission:settings.update')` for this group in api.php.
        // We probably should update api.php to use the new permissions too.
    }

    protected function ensurePasswordConfirmed(Request $request)
    {
        $confirmedAt = $request->session()->get('auth.password_confirmed_at');
        // Default 3 hours (10800 seconds)
        // We can just rely on the existence or freshness.
        // Logic: if not set, or too old > 3 hours
        if (! $confirmedAt || (time() - $confirmedAt) > 10800) {
            abort(423, 'Password confirmation required.');
        }
    }

    // Categories

    public function indexCategories(Request $request)
    {
        // Check permission if not handled by route middleware
        $this->authorize('viewAny', FaqCategory::class);

        $perPage = $request->input('per_page', 20);
        $search = $request->input('search');
        $sortBy = $request->input('sort_by', 'order');
        $sortDir = $request->input('sort_dir', 'asc');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $status = $request->input('status');
        $categories = $this->faqService->getAllCategories(false, $perPage, $search, $sortBy, $sortDir, $dateFrom, $dateTo, $status);

        return \App\Http\Resources\Faq\FaqCategoryResource::collection($categories);
    }

    public function storeCategory(\App\Http\Requests\Faq\StoreFaqCategoryRequest $request)
    {
        $category = $this->faqService->createCategory($request->validated(), $request->user()->id);

        return new \App\Http\Resources\Faq\FaqCategoryResource($category);
    }

    public function updateCategory(\App\Http\Requests\Faq\UpdateFaqCategoryRequest $request, FaqCategory $category, \App\Services\AuditService $auditService)
    {
        $validated = $request->validated();

        // Audit unpublish
        if (isset($validated['is_public']) && $category->is_public && ! $validated['is_public']) {
            $this->ensurePasswordConfirmed($request);
            $auditService->log(
                action: \App\Enums\AuditAction::Updated,
                category: \App\Enums\AuditCategory::DataModification,
                auditable: $category,
                user: $request->user(),
                oldValues: ['is_public' => true],
                newValues: ['is_public' => false],
                context: ['reason' => $request->input('reason'), 'action' => 'unpublish']
            );
        }

        $updated = $this->faqService->updateCategory($category, $validated);

        return new \App\Http\Resources\Faq\FaqCategoryResource($updated);
    }

    public function destroyCategory(\Illuminate\Http\Request $request, FaqCategory $category, \App\Services\AuditService $auditService)
    {
        $this->authorize('delete', $category);
        $this->ensurePasswordConfirmed($request);

        $reason = $request->input('reason');

        // Log to audit before deletion
        $auditService->log(
            action: \App\Enums\AuditAction::Deleted,
            category: \App\Enums\AuditCategory::DataModification,
            auditable: $category,
            user: $request->user(),
            oldValues: $category->toArray(),
            newValues: null,
            context: ['reason' => $reason, 'articles_deleted' => $category->articles()->count()]
        );

        $this->faqService->deleteCategory($category);

        return response()->json(null, 204);
    }

    // Articles

    public function indexArticles(Request $request)
    {
        $this->authorize('viewAny', FaqArticle::class);

        $perPage = $request->input('per_page', 20);
        $sortBy = $request->input('sort_by', 'created_at');
        $sortDir = $request->input('sort_dir', 'desc');
        $dateFrom = $request->input('date_from');
        $dateTo = $request->input('date_to');
        $status = $request->input('status'); // 'published', 'draft', or null

        $articles = $this->faqService->getArticles(
            $request->input('category_id'),
            false, // Show drafts too (status filter handles this now)
            $perPage,
            $request->input('search'),
            $sortBy,
            $sortDir,
            $dateFrom,
            $dateTo,
            $status
        );

        return \App\Http\Resources\Faq\FaqArticleResource::collection($articles);
    }

    public function storeArticle(\App\Http\Requests\Faq\StoreFaqArticleRequest $request)
    {
        $article = $this->faqService->createArticle($request->validated(), $request->user()->id);

        return new \App\Http\Resources\Faq\FaqArticleResource($article);
    }

    public function showArticle(FaqArticle $article)
    {
        $this->authorize('viewAny', FaqArticle::class); // or view?

        return new \App\Http\Resources\Faq\FaqArticleResource($article->load('category', 'author'));
    }

    public function updateArticle(\App\Http\Requests\Faq\UpdateFaqArticleRequest $request, FaqArticle $article, \App\Services\AuditService $auditService)
    {
        $validated = $request->validated();

        // Audit unpublish
        if (isset($validated['is_published']) && $article->is_published && ! $validated['is_published']) {
            $this->ensurePasswordConfirmed($request);
            $auditService->log(
                action: \App\Enums\AuditAction::Updated,
                category: \App\Enums\AuditCategory::DataModification,
                auditable: $article,
                user: $request->user(),
                oldValues: ['is_published' => true],
                newValues: ['is_published' => false],
                context: ['reason' => $request->input('reason'), 'action' => 'unpublish']
            );
        }

        $updated = $this->faqService->updateArticle($article, $validated);

        return new \App\Http\Resources\Faq\FaqArticleResource($updated);
    }

    public function destroyArticle(\Illuminate\Http\Request $request, FaqArticle $article, \App\Services\AuditService $auditService)
    {
        $this->authorize('delete', $article);
        $this->ensurePasswordConfirmed($request);

        $reason = $request->input('reason');

        // Log to audit before deletion
        $auditService->log(
            action: \App\Enums\AuditAction::Deleted,
            category: \App\Enums\AuditCategory::DataModification,
            auditable: $article,
            user: $request->user(),
            oldValues: $article->toArray(),
            newValues: null,
            context: ['reason' => $reason]
        );

        $this->faqService->deleteArticle($article);

        return response()->json(null, 204);
    }

    public function stats()
    {
        $this->authorize('viewAny', FaqArticle::class);

        return response()->json([
            'total_articles' => FaqArticle::count(),
            'total_views' => FaqArticle::sum('views'),
            'total_votes' => FaqArticle::sum('helpful_count') + FaqArticle::sum('unhelpful_count'),
            'most_viewed' => FaqArticle::orderByDesc('views')->limit(5)->get(['title', 'views', 'public_id']),
            'helpful_rate' => FaqArticle::selectRaw('
                    SUM(helpful_count) as helper, 
                    SUM(helpful_count) + SUM(unhelpful_count) as total
                ')->first(),
        ]);
    }

    /**
     * Bulk publish or unpublish articles
     */
    public function bulkPublish(\Illuminate\Http\Request $request, \App\Services\AuditService $auditService)
    {
        $this->authorize('update', FaqArticle::class);

        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'string',
            'publish' => 'required|boolean',
            'reason' => 'nullable|string|max:500',
        ]);

        if (! $validated['publish']) {
            $this->ensurePasswordConfirmed($request);
        }

        $count = $this->faqService->bulkPublishArticles(
            $validated['ids'],
            $validated['publish']
        );

        // Log to audit trail for unpublish actions (with reason)
        if (! $validated['publish'] && ! empty($validated['reason'])) {
            $articles = FaqArticle::whereIn('public_id', $validated['ids'])->get();
            foreach ($articles as $article) {
                $auditService->log(
                    action: \App\Enums\AuditAction::Updated,
                    category: \App\Enums\AuditCategory::DataModification,
                    auditable: $article,
                    user: $request->user(),
                    oldValues: ['is_published' => true],
                    newValues: ['is_published' => false],
                    context: ['reason' => $validated['reason'], 'action' => 'bulk_unpublish']
                );
            }
        }

        return response()->json([
            'message' => $count.' article(s) '.($validated['publish'] ? 'published' : 'unpublished'),
            'count' => $count,
        ]);
    }

    public function bulkPublishCategories(\Illuminate\Http\Request $request, \App\Services\AuditService $auditService)
    {
        $this->authorize('update', FaqCategory::class); // using policy provided by user rule

        $validated = $request->validate([
            'ids' => 'required|array|min:1',
            'ids.*' => 'string|exists:faq_categories,public_id',
            'publish' => 'required|boolean',
            'reason' => 'nullable|string|max:500',
        ]);

        if (! $validated['publish']) {
            $this->ensurePasswordConfirmed($request);
        }

        $count = $this->faqService->bulkPublishCategories(
            $validated['ids'],
            $validated['publish']
        );

        // Log to audit trail for unpublish actions (with reason)
        if (! $validated['publish'] && ! empty($validated['reason'])) {
            $categories = FaqCategory::whereIn('public_id', $validated['ids'])->get();
            foreach ($categories as $cat) {
                $auditService->log(
                    action: \App\Enums\AuditAction::Updated,
                    category: \App\Enums\AuditCategory::DataModification,
                    auditable: $cat,
                    user: $request->user(),
                    oldValues: ['is_public' => true],
                    newValues: ['is_public' => false],
                    context: ['reason' => $validated['reason'], 'action' => 'bulk_unpublish']
                );
            }
        }

        return response()->json([
            'message' => $count.' category(s) '.($validated['publish'] ? 'published' : 'unpublished'),
            'count' => $count,
        ]);
    }
}
