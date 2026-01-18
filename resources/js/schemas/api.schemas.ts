import { z } from 'zod';

// User schema for API responses
export const userApiSchema = z.object({
  id: z.number(),
  public_id: z.string(),
  name: z.string(),
  email: z.string().email(),
  avatar: z.string().nullable().optional(),
  avatar_url: z.string().nullable().optional(),
  created_at: z.string(),
  updated_at: z.string(),
  is_password_set: z.boolean().optional(),
  password_last_updated_at: z.string().nullable().optional(),
  presence: z.enum(['online', 'offline', 'away', 'busy']).optional(),
});

export const authResponseSchema = z.object({
  user: userApiSchema,
  requires_2fa: z.boolean().optional(),
  methods: z.array(z.string()).optional(),
});

export const paginationMetaSchema = z.object({
  current_page: z.number(),
  from: z.number(),
  last_page: z.number(),
  per_page: z.number(),
  to: z.number(),
  total: z.number(),
});

export function paginatedResponseSchema<T extends z.ZodTypeAny>(itemSchema: T) {
  return z.object({
    data: z.array(itemSchema),
    meta: paginationMetaSchema,
    links: z.object({
      first: z.string(),
      last: z.string(),
      prev: z.string().nullable(),
      next: z.string().nullable(),
    }).optional(),
  });
}
