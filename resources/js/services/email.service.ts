import { BaseService } from './base.service';
import type { ApiResponse, PaginatedResponse } from '@/types';
import type { Email } from '@/types/models/email';

export class EmailService extends BaseService {
  /**
   * List emails with filters
   */
  async list(params: {
    folder?: string;
    search?: string;
    page?: number;
    per_page?: number;
  }): Promise<PaginatedResponse<Email>> { // Note: Response type might need adapter
    try {
      const response = await this.api.get<PaginatedResponse<Email>>('/api/emails', { params });
      return response.data; 
    } catch (error) {
      this.handleError(error);
    }
  }

  /**
   * Get a single email
   */
  async find(id: string): Promise<Email> {
    try {
      const response = await this.api.get<ApiResponse<any>>(`/api/emails/${id}`);
      return this.extractData(response);
    } catch (error) {
      this.handleError(error);
    }
  }

  /**
   * Send an email
   */
  async send(data: FormData): Promise<Email> {
    try {
      const response = await this.api.post<ApiResponse<any>>('/api/emails', data, {
        headers: {
          'Content-Type': 'multipart/form-data',
        },
      });
      return this.extractData(response);
    } catch (error) {
      this.handleError(error);
    }
  }

  /**
   * Mark email as read/unread
   */
  async markAsRead(id: string, isRead: boolean): Promise<void> {
    try {
      // Endpoint expectation: PATCH /api/emails/{id} with is_read
      await this.api.patch(`/api/emails/${id}`, { is_read: isRead });
    } catch (error) {
      this.handleError(error);
    }
  }

  /**
   * Toggle star status
   */
  async toggleStar(id: string): Promise<void> {
    try {
        // Can be a toggle endpoint or update
        await this.api.post(`/api/emails/${id}/toggle-star`); 
    } catch (error) {
        this.handleError(error);
    }
  }

  /**
   * Move email to folder
   */
  async move(id: string, folder: string): Promise<void> {
      try {
          await this.api.post(`/api/emails/${id}/move`, { folder });
      } catch (error) {
          this.handleError(error);
      }
  }

  /**
   * Delete email (move to trash or permanent)
   */
  async delete(id: string): Promise<void> {
    try {
      await this.api.delete(`/api/emails/${id}`);
    } catch (error) {
      this.handleError(error);
    }
  }


  /**
   * Folders
   */
  async getFolders() {
    try {
        const response = await this.api.get('/api/emails/folders');
        return response.data;
    } catch (error) {
        this.handleError(error);
        return [];
    }
  }

  async createFolder(name: string, color?: string) {
      try {
          const response = await this.api.post('/api/emails/folders', { name, color });
          return response.data;
      } catch (error) {
          this.handleError(error);
      }
  }

  async deleteFolder(id: string) {
      try {
          await this.api.delete(`/api/emails/folders/${id}`);
      } catch (error) {
          this.handleError(error);
      }
  }

  /**
   * Labels
   */
  async getLabels() {
    try {
        const response = await this.api.get('/api/emails/labels');
        return response.data;
    } catch (error) {
        this.handleError(error);
        return [];
    }
  }

  async createLabel(name: string, color?: string) {
      try {
          const response = await this.api.post('/api/emails/labels', { name, color });
          return response.data;
      } catch (error) {
          this.handleError(error);
      }
  }

  async deleteLabel(id: string) {
      try {
          await this.api.delete(`/api/emails/labels/${id}`);
      } catch (error) {
          this.handleError(error);
      }
  }

  /**
   * Accounts
   */
  async getAccount(id: string) {
      try {
          // Supports finding by ID or public_id due to Route Binding usually
          // But our endpoint might require public_id if configured that way
          const response = await this.api.get(`/api/email-accounts/${id}`);
          return response.data;
      } catch (error) {
          this.handleError(error);
          return null;
      }
  }
}

export const emailService = new EmailService();
