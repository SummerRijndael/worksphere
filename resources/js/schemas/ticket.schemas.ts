import { z } from 'zod';

export const ticketSchema = z.object({
  title: z.string()
    .min(5, 'Title must be at least 5 characters')
    .max(255, 'Title is too long'),
  description: z.string()
    .min(10, 'Description must be at least 10 characters')
    .max(5000, 'Description is too long'),
  priority: z.enum(['low', 'medium', 'high', 'urgent']),
  type: z.enum(['bug', 'feature', 'question', 'improvement']),
  tags: z.array(z.string()).optional().default([]),
  assigned_to_id: z.number().optional(),
  client_id: z.number().optional(),
  project_id: z.number().optional(),
  due_date: z.string().datetime().optional(),
});

export const updateTicketSchema = ticketSchema.partial().extend({
  status: z.enum(['open', 'in_progress', 'resolved', 'closed', 'pending']).optional(),
});

export type TicketInput = z.infer<typeof ticketSchema>;
export type UpdateTicketInput = z.infer<typeof updateTicketSchema>;
