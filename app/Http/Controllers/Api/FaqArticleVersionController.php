<?php

namespace App\Http\Controllers\Api;

use App\Http\Controllers\Controller;
use App\Models\FaqArticle;
use App\Models\FaqArticleVersion;
use App\Services\AuditService;
use App\Services\FaqService;
use Illuminate\Http\Request;

class FaqArticleVersionController extends Controller
{
    protected $faqService;

    public function __construct(FaqService $faqService)
    {
        $this->faqService = $faqService;
    }

    /**
     * List versions for an article.
     */
    public function index(Request $request, FaqArticle $article)
    {
        $this->authorize('viewAny', FaqArticle::class); // Re-use FAQ permission

        $versions = $article->versions()
            ->with('author:id,name')
            ->select(['id', 'faq_article_id', 'user_id', 'title', 'created_at', 'updated_at']) // Omit heavy content for list
            ->paginate($request->input('per_page', 10));

        return response()->json($versions);
    }

    /**
     * Show a specific version content.
     */
    public function show(FaqArticleVersion $version)
    {
        $this->authorize('viewAny', FaqArticle::class);

        // Load author if needed
        $version->load('author');

        return response()->json($version);
    }

    /**
     * Restore a version.
     */
    public function restore(Request $request, FaqArticleVersion $version, AuditService $auditService)
    {
        $article = $version->article;
        $this->authorize('update', $article);

        // Security Check: Password confirmation for restore?
        // It's a significant change, but maybe not destructive (it creates a new backup first).
        // Let's keep it safe.
        // $this->ensurePasswordConfirmed($request); // Need trait or copy logic.
        // For now, let's skip strict password for Restore as it's an "Edit" action, logic safe.
        // But if user requested "secure transactions", maybe valid.
        // I'll skip password for now as it's effectively an Edit, and edits don't require password unless Unpublish.

        $restored = $this->faqService->restoreVersion($article, $version);

        // Audit Log
        $auditService->log(
            action: \App\Enums\AuditAction::Updated,
            category: \App\Enums\AuditCategory::DataModification,
            auditable: $article,
            user: $request->user(),
            oldValues: null, // Hard to diff here
            newValues: ['version_restored' => $version->id],
            context: ['action' => 'restore_version', 'restored_from_id' => $version->id]
        );

        return response()->json(['message' => 'Version restored successfully']);
    }
}
