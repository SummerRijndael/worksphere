<?php

namespace App\Contracts;

use App\Models\FaqArticle;
use App\Models\FaqCategory;

interface FaqServiceInterface
{
    /**
     * Get all categories with specific logic (e.g. public only, or all).
     */
    public function getAllCategories(bool $publicOnly = false, ?int $perPage = null, ?string $search = null, string $sortBy = 'order', string $sortDir = 'asc', ?string $dateFrom = null, ?string $dateTo = null, ?string $status = null): \Illuminate\Contracts\Pagination\LengthAwarePaginator|\Illuminate\Database\Eloquent\Collection;

    /**
     * Create a new category.
     */
    public function createCategory(array $data, int $authorId): FaqCategory;

    /**
     * Update a category.
     */
    public function updateCategory(FaqCategory $category, array $data): FaqCategory;

    /**
     * Delete a category.
     */
    public function deleteCategory(FaqCategory $category): bool;

    /**
     * Get articles, optionally filtered by category and visibility.
     */
    public function getArticles(?int $categoryId = null, bool $publishedOnly = false, int $perPage = 20, ?string $search = null, string $sortBy = 'created_at', string $sortDir = 'desc', ?string $dateFrom = null, ?string $dateTo = null, ?string $status = null): \Illuminate\Contracts\Pagination\LengthAwarePaginator;

    /**
     * Get a specific article by slug.
     */
    public function getArticleBySlug(string $slug, bool $publishedOnly = false): FaqArticle;

    /**
     * Create a new article.
     */
    public function createArticle(array $data, int $authorId): FaqArticle;

    /**
     * Update an article.
     */
    public function updateArticle(FaqArticle $article, array $data): FaqArticle;

    /**
     * Delete an article.
     */
    public function deleteArticle(FaqArticle $article): bool;

    /**
     * Vote on an article's helpfulness.
     */
    public function voteArticle(FaqArticle $article, bool $isHelpful): FaqArticle;

    /**
     * Bulk publish or unpublish articles.
     */
    public function bulkPublishArticles(array $ids, bool $publish = true): int;

    /**
     * Bulk publish or unpublish categories.
     */
    public function bulkPublishCategories(array $ids, bool $public = true): int;
}
