import { BaseService } from './base.service';
import type {
  Ticket,
  CreateTicketRequest,
  UpdateTicketRequest,
  TicketFilters,
  PaginatedResponse,
  ApiResponse,
} from '@/types';
import { validateOrThrow } from '@/utils/validation';
import { ticketSchema, updateTicketSchema } from '@/schemas/ticket.schemas';

export class TicketService extends BaseService {
  /**
   * Fetch paginated tickets
   */
  async fetchTickets(filters: TicketFilters = {}): Promise<PaginatedResponse<Ticket>> {
    try {
      const response = await this.api.get<PaginatedResponse<Ticket>>('/api/tickets', {
        params: filters,
      });

      return response.data;
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Fetch single ticket
   */
  async fetchTicket(id: string): Promise<Ticket> {
    try {
      const response = await this.api.get<ApiResponse<Ticket>>(`/api/tickets/${id}`);
      return this.extractData(response);
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Create ticket
   */
  async createTicket(data: CreateTicketRequest): Promise<Ticket> {
    try {
      const validatedData = validateOrThrow(ticketSchema, data);

      const response = await this.api.post<ApiResponse<Ticket>>(
        '/api/tickets',
        validatedData
      );

      return this.extractData(response);
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Update ticket
   */
  async updateTicket(id: string, data: UpdateTicketRequest): Promise<Ticket> {
    try {
      const validatedData = validateOrThrow(updateTicketSchema, data);

      const response = await this.api.put<ApiResponse<Ticket>>(
        `/api/tickets/${id}`,
        validatedData
      );

      return this.extractData(response);
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Delete ticket
   */
  async deleteTicket(id: string): Promise<void> {
    try {
      await this.api.delete(`/api/tickets/${id}`);
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Bulk update tickets
   */
  async bulkUpdateStatus(ids: string[], status: string): Promise<void> {
    try {
      await this.api.post('/api/tickets/bulk-update', {
        ids,
        status,
      });
    } catch (error) {
      return this.handleError(error);
    }
  }
}

export const ticketService = new TicketService();
