import type { TicketPriority, TicketStatus, TicketType } from '../models/ticket';

export interface CreateTicketRequest {
  title: string;
  description: string;
  priority: TicketPriority;
  type: TicketType;
  tags?: string[];
  assigned_to_id?: number;
  client_id?: number;
  project_id?: number;
  due_date?: string;
}

export interface UpdateTicketRequest extends Partial<CreateTicketRequest> {
  status?: TicketStatus;
}

export interface TicketFilters {
  status?: TicketStatus | 'all';
  priority?: TicketPriority | 'all';
  type?: TicketType;
  search?: string;
  assigned_to?: number;
  page?: number;
  per_page?: number;
}
