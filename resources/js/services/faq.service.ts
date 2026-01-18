import { BaseService } from './base.service';
import { validateOrThrow } from '@/utils/validation';
import {
  faqCategorySchema,
  updateFaqCategorySchema,
  faqArticleSchema,
  updateFaqArticleSchema,
  type FaqCategory,
  type FaqCategoryInput,
  type UpdateFaqCategoryInput,
  type FaqArticle,
  type FaqArticleInput,
  type UpdateFaqArticleInput,
  type FaqStats,
  type FaqFilters,
} from '@/schemas/faq.schemas';
import type { PaginatedResponse, ApiResponse } from '@/types';

export class FaqService extends BaseService {
  // ============================================
  // Categories
  // ============================================

  /**
   * Fetch paginated categories (admin)
   */
  async fetchCategories(filters: FaqFilters = {}): Promise<PaginatedResponse<FaqCategory>> {
    try {
      const response = await this.api.get<PaginatedResponse<FaqCategory>>(
        '/api/admin/faq/categories',
        { params: filters }
      );
      return response.data;
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Fetch all public categories (for dropdowns, public pages)
   */
  async fetchPublicCategories(): Promise<FaqCategory[]> {
    try {
      const response = await this.api.get<ApiResponse<FaqCategory[]>>('/api/faq/categories');
      return this.extractData(response);
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Create category
   */
  async createCategory(data: FaqCategoryInput): Promise<FaqCategory> {
    try {
      const validatedData = validateOrThrow(faqCategorySchema, data);
      const response = await this.api.post<ApiResponse<FaqCategory>>(
        '/api/admin/faq/categories',
        validatedData
      );
      return this.extractData(response);
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Update category
   */
  async updateCategory(id: string, data: UpdateFaqCategoryInput): Promise<FaqCategory> {
    try {
      const validatedData = validateOrThrow(updateFaqCategorySchema, data);
      const response = await this.api.put<ApiResponse<FaqCategory>>(
        `/api/admin/faq/categories/${id}`,
        validatedData
      );
      return this.extractData(response);
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Delete category
   */
  async deleteCategory(id: string, reason?: string): Promise<void> {
    try {
      await this.api.delete(`/api/admin/faq/categories/${id}`, {
        data: { reason }
      });
    } catch (error) {
      return this.handleError(error);
    }
  }

  // ============================================
  // Articles
  // ============================================

  /**
   * Fetch paginated articles (admin)
   */
  async fetchArticles(filters: FaqFilters = {}): Promise<PaginatedResponse<FaqArticle>> {
    try {
      const response = await this.api.get<PaginatedResponse<FaqArticle>>(
        '/api/admin/faq/articles',
        { params: filters }
      );
      return response.data;
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Fetch single article by ID (admin)
   */
  async fetchArticle(id: string): Promise<FaqArticle> {
    try {
      const response = await this.api.get<ApiResponse<FaqArticle>>(
        `/api/admin/faq/articles/${id}`
      );
      return this.extractData(response);
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Fetch article by slug (public)
   */
  async fetchArticleBySlug(slug: string): Promise<FaqArticle> {
    try {
      const response = await this.api.get<ApiResponse<FaqArticle>>(
        `/api/faq/articles/${slug}`
      );
      return this.extractData(response);
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Create article
   */
  async createArticle(data: FaqArticleInput): Promise<FaqArticle> {
    try {
      const validatedData = validateOrThrow(faqArticleSchema, data);
      const response = await this.api.post<ApiResponse<FaqArticle>>(
        '/api/admin/faq/articles',
        validatedData
      );
      return this.extractData(response);
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Update article
   */
  async updateArticle(id: string, data: UpdateFaqArticleInput): Promise<FaqArticle> {
    try {
      const validatedData = validateOrThrow(updateFaqArticleSchema, data);
      const response = await this.api.put<ApiResponse<FaqArticle>>(
        `/api/admin/faq/articles/${id}`,
        validatedData
      );
      return this.extractData(response);
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Delete article
   */
  async deleteArticle(id: string, reason?: string): Promise<void> {
    try {
      await this.api.delete(`/api/admin/faq/articles/${id}`, {
        data: { reason }
      });
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Vote on article (helpful/unhelpful)
   */
  async voteArticle(slug: string, isHelpful: boolean): Promise<FaqArticle> {
    try {
      const response = await this.api.post<ApiResponse<FaqArticle>>(
        `/api/faq/articles/${slug}/vote`,
        { is_helpful: isHelpful }
      );
      return this.extractData(response);
    } catch (error) {
      return this.handleError(error);
    }
  }

  // ============================================
  // Stats
  // ============================================

  /**
   * Fetch FAQ stats
   */
  async fetchStats(): Promise<FaqStats> {
    try {
      const response = await this.api.get<FaqStats>('/api/admin/faq/stats');
      return response.data;
    } catch (error) {
      return this.handleError(error);
    }
  }

  // ============================================
  // Bulk Actions
  // ============================================

  /**
   * Bulk publish or unpublish articles
   */
  async bulkPublish(ids: string[], publish: boolean, reason?: string): Promise<{ message: string; count: number }> {
    try {
      const response = await this.api.post<{ message: string; count: number }>(
        '/api/admin/faq/articles/bulk-publish',
        { ids, publish, reason }
      );
      return response.data;
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Bulk publish or unpublish categories
   */
  async bulkPublishCategories(ids: number[], publish: boolean, reason?: string): Promise<{ message: string; count: number }> {
    try {
      const response = await this.api.post<{ message: string; count: number }>(
        '/api/admin/faq/categories/bulk-publish',
        { ids, publish, reason }
      );
      return response.data;
    } catch (error) {
      return this.handleError(error);
    }
  }
}

export const faqService = new FaqService();
