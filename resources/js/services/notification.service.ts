import { BaseService } from './base.service';
import type { Notification, PaginatedResponse, ApiResponse } from '@/types';

export class NotificationService extends BaseService {
  async fetchNotifications(page: number = 1): Promise<PaginatedResponse<Notification>> {
    try {
      const response = await this.api.get<PaginatedResponse<Notification>>(
        '/api/notifications',
        { params: { page } }
      );
      return response.data;
    } catch (error) {
      return this.handleError(error);
    }
  }

  async fetchUnreadCount(): Promise<number> {
    try {
      const response = await this.api.get<ApiResponse<{ count: number }>>(
        '/api/notifications/unread-count'
      );
      return this.extractData(response).count;
    } catch (error) {
      return this.handleError(error);
    }
  }

  async markAsRead(id: string): Promise<void> {
    try {
      await this.api.put(`/api/notifications/${id}/read`);
    } catch (error) {
      return this.handleError(error);
    }
  }

  async markAllAsRead(): Promise<void> {
    try {
      await this.api.put('/api/notifications/mark-all-read');
    } catch (error) {
      return this.handleError(error);
    }
  }

  async deleteNotification(id: string): Promise<void> {
    try {
      await this.api.delete(`/api/notifications/${id}`);
    } catch (error) {
      return this.handleError(error);
    }
  }
}

export const notificationService = new NotificationService();
