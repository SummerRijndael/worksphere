import type { User } from './user';

export interface Ticket {
  id: number;
  public_id: string;
  title: string;
  description: string;
  status: TicketStatus;
  priority: TicketPriority;
  type: TicketType;
  tags: string[];
  assigned_to?: User;
  created_by: User;
  client?: Client;
  project?: Project;
  attachments?: Attachment[];
  comments_count?: number;
  created_at: string;
  updated_at: string;
  due_date?: string | null;
}

export type TicketStatus = 'open' | 'in_progress' | 'resolved' | 'closed' | 'pending';
export type TicketPriority = 'low' | 'medium' | 'high' | 'urgent';
export type TicketType = 'bug' | 'feature' | 'question' | 'improvement';

export interface Client {
  id: number;
  public_id: string;
  name: string;
  email: string;
  company?: string;
  avatar?: string;
}

export interface Project {
  id: number;
  public_id: string;
  name: string;
  description?: string;
  status: string;
}

export interface Attachment {
  id: number;
  filename: string;
  url: string;
  size: number;
  mime_type: string;
}
