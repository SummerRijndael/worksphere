import { z } from 'zod';

// ============================================
// Category Schemas
// ============================================

export const faqCategorySchema = z.object({
  name: z.string()
    .min(2, 'Name must be at least 2 characters')
    .max(100, 'Name is too long'),
  description: z.string()
    .max(500, 'Description is too long')
    .optional()
    .nullable(),
  icon: z.string()
    .max(50, 'Icon name is too long')
    .optional()
    .nullable(),
  order: z.number().int().min(0).default(0),
  is_public: z.boolean().default(true),
});

export const updateFaqCategorySchema = faqCategorySchema.partial();

// ============================================
// Article Schemas
// ============================================

export const faqArticleSchema = z.object({
  title: z.string()
    .min(5, 'Title must be at least 5 characters')
    .max(255, 'Title is too long'),
  content: z.string()
    .min(20, 'Content must be at least 20 characters'),
  category_id: z.string().uuid('Invalid category ID'),
  is_published: z.boolean().default(false),
  tags: z.array(z.string()).optional().default([]),
});

export const updateFaqArticleSchema = faqArticleSchema.partial();

// ============================================
// Type Exports
// ============================================

export type FaqCategoryInput = z.infer<typeof faqCategorySchema>;
export type UpdateFaqCategoryInput = z.infer<typeof updateFaqCategorySchema>;
export type FaqArticleInput = z.infer<typeof faqArticleSchema>;
export type UpdateFaqArticleInput = z.infer<typeof updateFaqArticleSchema>;

// ============================================
// Response Types
// ============================================

export interface FaqCategory {
  id: string;
  public_id: string;
  name: string;
  slug: string;
  description: string | null;
  icon: string | null;
  order: number;
  is_public: boolean;
  articles_count: number;
  total_views: number;
  total_helpful: number;
  total_unhelpful: number;
  author: {
    id: number;
    name: string;
    avatar: string | null;
  } | null;
  created_at: string;
  updated_at: string;
}

export interface FaqArticle {
  id: string;
  public_id: string;
  title: string;
  slug: string;
  content: string;
  views: number;
  helpful_count: number;
  unhelpful_count: number;
  comments_count: number;
  is_published: boolean;
  category: {
    id: string;
    name: string;
    slug: string;
  } | null;
  author: {
    id: number;
    name: string;
    avatar: string | null;
  } | null;
  created_at: string;
  updated_at: string;
}

export interface FaqStats {
  total_articles: number;
  total_views: number;
  total_votes: number;
  most_viewed: Array<{
    title: string;
    views: number;
    public_id: string;
  }>;
  helpful_rate: {
    helper: number;
    total: number;
  } | null;
}

export interface FaqFilters {
  page?: number;
  per_page?: number;
  search?: string;
  category_id?: string;
  sort_by?: string;
  sort_dir?: 'asc' | 'desc';
  date_from?: string;
  date_to?: string;
  status?: 'published' | 'draft' | 'public' | 'private' | '';
}
