import { BaseService } from './base.service';
import type { Permission, ApiResponse } from '@/types';

export class PermissionService extends BaseService {
  async fetchPermissions(): Promise<Permission[]> {
    try {
      const response = await this.api.get<ApiResponse<Permission[]>>('/api/permissions');
      return this.extractData(response);
    } catch (error) {
      return this.handleError(error);
    }
  }

  async fetchUserOverrides(userId: string): Promise<any[]> {
    try {
      const response = await this.api.get<ApiResponse<any[]>>(
        `/api/users/${userId}/permission-overrides`
      );
      return this.extractData(response);
    } catch (error) {
      return this.handleError(error);
    }
  }

  async createOverride(userId: string, data: any): Promise<any> {
    try {
      const response = await this.api.post<ApiResponse<any>>(
        `/api/users/${userId}/permission-overrides`,
        data
      );
      return this.extractData(response);
    } catch (error) {
      return this.handleError(error);
    }
  }

  async revokeOverride(overrideId: string, reason: string): Promise<void> {
    try {
      await this.api.delete(`/api/permission-overrides/${overrideId}`, {
        data: { reason },
      });
    } catch (error) {
      return this.handleError(error);
    }
  }
}

export const permissionService = new PermissionService();
