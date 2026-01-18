import { ref, type Ref } from 'vue';
import api from '@/lib/api';
import { useToast } from './useToast';
import type { Permission, Role } from '@/types/models/user';
import type { ApiResponse } from '@/types/api';

export interface PermissionOverride {
  id: number;
  permission: Permission;
  expires_at: string | null;
  created_at: string;
  // Add other fields as necessary based on API response
}

export interface RoleChangeRequest {
  id: number;
  user_id: number;
  requested_role_id: number;
  status: 'pending' | 'approved' | 'rejected';
  reason?: string;
  created_at: string;
  // Add other fields
}

export interface RoleStatistics {
  id: number;
  name: string;
  users_count: number;
}

export interface RoleChangeConfig {
  required_approvals: number;
  request_expiry_days: number;
}

/**
 * Composable for managing permission overrides API calls
 */
export function usePermissions() {
  const { toast } = useToast();

  const loading = ref(false);
  const overrides: Ref<PermissionOverride[]> = ref([]);
  const permissions: Ref<Permission[]> = ref([]);

  /**
   * Fetch user's permission overrides
   */
  async function fetchUserOverrides(userId: number | string): Promise<PermissionOverride[]> {
    loading.value = true;
    try {
      const response = await api.get<ApiResponse<PermissionOverride[]>>(`/api/users/${userId}/permission-overrides`);
      overrides.value = response.data.data || [];
      return overrides.value;
    } catch (error) {
      console.error('Failed to load permission overrides:', error);
      toast.error('Failed to load permission overrides');
      return [];
    } finally {
      loading.value = false;
    }
  }

  /**
   * Fetch available permissions grouped by category
   */
  async function fetchPermissions(): Promise<Permission[]> {
    try {
      const response = await api.get<ApiResponse<Permission[]>>('/api/permissions');
      permissions.value = response.data.data || [];
      return permissions.value;
    } catch (error) {
      console.error('Failed to load permissions:', error);
      toast.error('Failed to load permissions');
      return [];
    }
  }

  /**
   * Create a new permission override
   */
  async function createOverride(userId: number | string, data: any): Promise<PermissionOverride> {
    loading.value = true;
    try {
      const response = await api.post<ApiResponse<PermissionOverride>>(`/api/users/${userId}/permission-overrides`, data);
      toast.success(response.data.message || 'Permission override created');
      return response.data.data;
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to create override');
      throw error;
    } finally {
      loading.value = false;
    }
  }

  /**
   * Renew a temporary permission override
   */
  async function renewOverride(overrideId: number | string, expiresAt: string): Promise<PermissionOverride> {
    loading.value = true;
    try {
      const response = await api.post<ApiResponse<PermissionOverride>>(`/api/permission-overrides/${overrideId}/renew`, {
        expires_at: expiresAt,
      });
      toast.success(response.data.message || 'Permission renewed');
      return response.data.data;
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to renew override');
      throw error;
    } finally {
      loading.value = false;
    }
  }

  /**
   * Revoke a permission override
   */
  async function revokeOverride(overrideId: number | string, reason: string): Promise<boolean> {
    loading.value = true;
    try {
      const response = await api.delete<ApiResponse>(`/api/permission-overrides/${overrideId}`, {
        data: { reason },
      });
      toast.success(response.data.message || 'Permission override revoked');
      return true;
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to revoke override');
      throw error;
    } finally {
      loading.value = false;
    }
  }

  /**
   * Fetch user's effective permissions
   */
  async function fetchEffectivePermissions(userId: number | string, teamId: number | string | null = null): Promise<string[]> {
    try {
      const params = teamId ? { team_id: teamId } : {};
      const response = await api.get<ApiResponse<string[]>>(`/api/users/${userId}/effective-permissions`, { params });
      return response.data.data;
    } catch (error) {
      toast.error('Failed to load effective permissions');
      throw error;
    }
  }

  /**
   * Fetch expiring permissions
   */
  async function fetchExpiringPermissions(days: number = 7): Promise<PermissionOverride[]> {
    try {
      const response = await api.get<ApiResponse<PermissionOverride[]>>('/api/permission-overrides/expiring', {
        params: { days },
      });
      return response.data.data || [];
    } catch (error) {
      toast.error('Failed to load expiring permissions');
      throw error;
    }
  }

  return {
    loading,
    overrides,
    permissions,
    fetchUserOverrides,
    fetchPermissions,
    createOverride,
    renewOverride,
    revokeOverride,
    fetchEffectivePermissions,
    fetchExpiringPermissions,
  };
}

/**
 * Composable for managing role change requests API calls
 */
export function useRoleChangeRequests() {
  const { toast } = useToast();

  const loading = ref(false);
  const requests: Ref<RoleChangeRequest[]> = ref([]);
  const config: Ref<RoleChangeConfig> = ref({ required_approvals: 2, request_expiry_days: 7 });

  /**
   * Fetch all role change requests
   */
  async function fetchRequests(status: string | null = null): Promise<RoleChangeRequest[]> {
    loading.value = true;
    try {
      const params = status ? { status } : {};
      const response = await api.get<ApiResponse<RoleChangeRequest[]>>('/api/role-change-requests', { params });
      requests.value = response.data.data || [];
      return requests.value;
    } catch (error) {
      console.error('Failed to load role change requests:', error);
      toast.error('Failed to load role change requests');
      return [];
    } finally {
      loading.value = false;
    }
  }

  /**
   * Fetch pending requests for current admin
   */
  async function fetchPendingRequests(): Promise<RoleChangeRequest[]> {
    loading.value = true;
    try {
      const response = await api.get<ApiResponse<RoleChangeRequest[]>>('/api/role-change-requests/pending');
      requests.value = response.data.data || [];
      return requests.value;
    } catch (error) {
      console.error('Failed to load pending requests:', error);
      toast.error('Failed to load pending requests');
      return [];
    } finally {
      loading.value = false;
    }
  }

  /**
   * Fetch approval configuration
   */
  async function fetchConfig(): Promise<RoleChangeConfig | null> {
    try {
      const response = await api.get<ApiResponse<RoleChangeConfig>>('/api/role-change-requests/config');
      config.value = response.data.data;
      return config.value;
    } catch (error) {
      console.error('Failed to load config:', error);
      return null;
    }
  }

  /**
   * Create a new role change request
   */
  async function createRequest(data: any): Promise<RoleChangeRequest> {
    loading.value = true;
    try {
      const response = await api.post<ApiResponse<RoleChangeRequest>>('/api/role-change-requests', data);
      toast.success(response.data.message || 'Request created');
      return response.data.data;
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to create request');
      throw error;
    } finally {
      loading.value = false;
    }
  }

  /**
   * Approve a role change request
   */
  async function approveRequest(requestId: number | string, password: string, comment: string | null = null): Promise<any> {
    loading.value = true;
    try {
      const response = await api.post<ApiResponse>(`/api/role-change-requests/${requestId}/approve`, {
        password,
        comment,
      });
      toast.success(response.data.message || 'Request approved');
      return response.data;
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to approve request');
      throw error;
    } finally {
      loading.value = false;
    }
  }

  /**
   * Reject a role change request
   */
  async function rejectRequest(requestId: number | string, password: string, reason: string): Promise<any> {
    loading.value = true;
    try {
      const response = await api.post<ApiResponse>(`/api/role-change-requests/${requestId}/reject`, {
        password,
        reason,
      });
      toast.success(response.data.message || 'Request rejected');
      return response.data;
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to reject request');
      throw error;
    } finally {
      loading.value = false;
    }
  }

  return {
    loading,
    requests,
    config,
    fetchRequests,
    fetchPendingRequests,
    fetchConfig,
    createRequest,
    approveRequest,
    rejectRequest,
  };
}

/**
 * Composable for role management API calls
 */
export function useRoles() {
  const { toast } = useToast();

  const loading = ref(false);
  const roles: Ref<Role[]> = ref([]);
  const statistics: Ref<RoleStatistics[] | null> = ref(null);

  /**
   * Fetch all roles
   */
  async function fetchRoles(): Promise<Role[]> {
    loading.value = true;
    try {
      const response = await api.get<ApiResponse<Role[]>>('/api/roles');
      roles.value = response.data.data || [];
      return roles.value;
    } catch (error) {
      console.error('Failed to load roles:', error);
      toast.error('Failed to load roles');
      return [];
    } finally {
      loading.value = false;
    }
  }

  /**
   * Fetch role statistics
   */
  async function fetchStatistics(): Promise<RoleStatistics[] | null> {
    try {
      const response = await api.get<ApiResponse<RoleStatistics[]>>('/api/roles/statistics');
      statistics.value = response.data.data;
      return statistics.value;
    } catch (error) {
      console.error('Failed to load statistics:', error);
      toast.error('Failed to load statistics');
      return null;
    }
  }

  /**
   * Fetch a single role
   */
  async function fetchRole(roleId: number | string): Promise<Role | null> {
    loading.value = true;
    try {
      const response = await api.get<ApiResponse<Role>>(`/api/roles/${roleId}`);
      return response.data.data;
    } catch (error) {
      console.error('Failed to load role:', error);
      toast.error('Failed to load role details');
      return null;
    } finally {
      loading.value = false;
    }
  }

  /**
   * Fetch users for a specific role
   */
  async function fetchRoleUsers(roleId: number | string, page: number = 1, perPage: number = 10, search: string = ''): Promise<ApiResponse<any> | null> {
    loading.value = true;
    try {
      const response = await api.get<ApiResponse<any>>(`/api/roles/${roleId}/users`, {
        params: { page, per_page: perPage, search }
      });
      return response.data;
    } catch (error) {
      console.error('Failed to load role users:', error);
      toast.error('Failed to load role users');
      return null;
    } finally {
      loading.value = false;
    }
  }

  /**
   * Create a new role
   */
  async function createRole(data: any): Promise<Role | { requiresApproval: true; type: any; previewData: any }> {
    loading.value = true;
    try {
      const response = await api.post<ApiResponse<Role> & { requires_approval?: boolean; approval_type?: any; preview_data?: any }>('/api/roles', data);

      // Check if approval is required
      if (response.data.requires_approval) {
        toast.warning('This change requires multi-admin approval');
        return { requiresApproval: true, type: response.data.approval_type, previewData: response.data.preview_data };
      }

      toast.success(response.data.message || 'Role created');
      return response.data.data;
    } catch (error: any) {
      if (error.response && error.response.status === 422 && error.response.data.requires_approval) {
        toast.warning('This change requires multi-admin approval');
        return { requiresApproval: true, type: error.response.data.approval_type, previewData: error.response.data.preview_data };
      }
      toast.error(error.response?.data?.message || 'Failed to create role');
      throw error;
    } finally {
      loading.value = false;
    }
  }

  /**
   * Update a role
   */
  async function updateRole(roleId: number | string, data: any): Promise<Role | { requiresApproval: true; type: any } | null> {
    loading.value = true;
    try {
      const response = await api.put<ApiResponse<Role> & { requires_approval?: boolean; approval_type?: any }>(`/api/roles/${roleId}`, data);

      // Check if approval is required
      if (response.data.requires_approval) {
        toast.warning('This change requires multi-admin approval');
        return { requiresApproval: true, type: response.data.approval_type };
      }

      toast.success(response.data.message || 'Role updated');
      return response.data.data;
    } catch (error: any) {
      toast.error(error.response?.data?.message || 'Failed to update role');
      throw error;
    } finally {
      loading.value = false;
    }
  }

  return {
    loading,
    roles,
    statistics,
    fetchRoles,
    fetchRole,
    fetchRoleUsers,
    fetchStatistics,
    createRole,
    updateRole,
  };
}
