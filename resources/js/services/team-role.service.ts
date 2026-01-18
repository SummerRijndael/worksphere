import { BaseService } from './base.service';
import type { ApiResponse, PaginatedResponse } from '@/types';

export interface TeamRole {
  id: string;
  name: string;
  slug: string;
  description: string | null;
  color: string;
  level: number;
  is_default: boolean;
  is_system: boolean;
  can_be_deleted: boolean;
  permissions: string[];
  member_count: number;
  creator?: {
    id: string;
    name: string;
    avatar_url: string | null;
  };
  created_at: string;
  updated_at: string;
}

export interface CreateTeamRoleRequest {
  name: string;
  description?: string;
  color?: string;
  level?: number;
  is_default?: boolean;
  permissions?: string[];
}

export interface UpdateTeamRoleRequest {
  name?: string;
  description?: string;
  color?: string;
  level?: number;
  is_default?: boolean;
  permissions?: string[];
}

export interface TeamRoleFilters {
  search?: string;
  custom_only?: boolean;
  per_page?: number;
}

export interface PermissionGroup {
  key: string;
  label: string;
}

export interface AvailablePermissions {
  [module: string]: PermissionGroup[];
}

export class TeamRoleService extends BaseService {
  /**
   * Fetch team roles
   */
  async fetchRoles(
    teamId: string,
    filters: TeamRoleFilters = {}
  ): Promise<TeamRole[] | PaginatedResponse<TeamRole>> {
    try {
      const response = await this.api.get<TeamRole[] | PaginatedResponse<TeamRole>>(
        `/api/teams/${teamId}/roles`,
        { params: filters }
      );
      return response.data;
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Fetch single team role
   */
  async fetchRole(teamId: string, roleId: string): Promise<TeamRole> {
    try {
      const response = await this.api.get<ApiResponse<TeamRole>>(
        `/api/teams/${teamId}/roles/${roleId}`
      );
      return this.extractData(response);
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Create team role
   */
  async createRole(teamId: string, data: CreateTeamRoleRequest): Promise<TeamRole> {
    try {
      const response = await this.api.post<ApiResponse<TeamRole>>(
        `/api/teams/${teamId}/roles`,
        data
      );
      return this.extractData(response);
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Update team role
   */
  async updateRole(
    teamId: string,
    roleId: string,
    data: UpdateTeamRoleRequest
  ): Promise<TeamRole> {
    try {
      const response = await this.api.put<ApiResponse<TeamRole>>(
        `/api/teams/${teamId}/roles/${roleId}`,
        data
      );
      return this.extractData(response);
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Delete team role
   */
  async deleteRole(teamId: string, roleId: string): Promise<void> {
    try {
      await this.api.delete(`/api/teams/${teamId}/roles/${roleId}`);
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Get available permissions
   */
  async fetchAvailablePermissions(teamId: string): Promise<AvailablePermissions> {
    try {
      const response = await this.api.get<AvailablePermissions>(
        `/api/teams/${teamId}/roles/permissions`
      );
      return response.data;
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Get role members
   */
  async fetchRoleMembers(
    teamId: string,
    roleId: string,
    params: { search?: string; per_page?: number } = {}
  ): Promise<PaginatedResponse<any>> {
    try {
      const response = await this.api.get<PaginatedResponse<any>>(
        `/api/teams/${teamId}/roles/${roleId}/members`,
        { params }
      );
      return response.data;
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Assign role to member
   */
  async assignRoleToMember(
    teamId: string,
    roleId: string,
    memberId: string
  ): Promise<{ message: string; role: TeamRole }> {
    try {
      const response = await this.api.post<{ message: string; role: TeamRole }>(
        `/api/teams/${teamId}/roles/${roleId}/assign/${memberId}`
      );
      return response.data;
    } catch (error) {
      return this.handleError(error);
    }
  }
}

export const teamRoleService = new TeamRoleService();
