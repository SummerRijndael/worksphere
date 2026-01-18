import { BaseService } from './base.service';
import type { ApiResponse, PaginatedResponse } from '@/types';

export interface ProjectStatus {
  value: string;
  label: string;
  color: string;
}

export interface ProjectPriority {
  value: string;
  label: string;
  color: string;
}

export interface ProjectMember {
  id: string;
  name: string;
  email: string;
  avatar_url: string | null;
  role: 'manager' | 'member' | 'viewer';
  joined_at: string;
}

export interface ProjectClient {
  id: string;
  name: string;
  email: string | null;
}

export interface Project {
  id: string;
  name: string;
  slug: string;
  description: string | null;
  status: ProjectStatus;
  priority: ProjectPriority;
  start_date: string | null;
  due_date: string | null;
  completed_at: string | null;
  budget: string | null;
  currency: string;
  progress_percentage: number;
  is_overdue: boolean;
  days_until_due: number | null;
  settings: Record<string, any> | null;
  client?: ProjectClient;
  creator?: {
    id: string;
    name: string;
    email: string;
    avatar_url: string | null;
  };
  archiver?: {
    id: string;
    name: string;
  } | null;
  archived_at: string | null;
  members?: ProjectMember[];
  member_count?: number;
  tasks_count?: number;
  created_at: string;
  updated_at: string;
}

export interface CreateProjectRequest {
  name: string;
  description?: string;
  status?: string;
  priority?: string;
  start_date?: string;
  due_date?: string;
  client_id?: string;
  budget?: number;
  currency?: string;
  settings?: Record<string, any>;
  members?: Array<{ user_id: string; role?: string }>;
}

export interface UpdateProjectRequest {
  name?: string;
  description?: string;
  status?: string;
  priority?: string;
  start_date?: string;
  due_date?: string;
  client_id?: string | null;
  budget?: number | null;
  currency?: string;
  settings?: Record<string, any>;
}

export interface ProjectFilters {
  search?: string;
  status?: string;
  priority?: string;
  client_id?: string;
  archived?: boolean;
  include_archived?: boolean;
  overdue?: boolean;
  sort_by?: string;
  sort_direction?: 'asc' | 'desc';
  per_page?: number;
  page?: number;
}

export interface ProjectStats {
  total_tasks: number;
  completed_tasks: number;
  in_progress_tasks: number;
  pending_tasks: number;
  overdue_tasks: number;
  member_count: number;
  progress_percentage: number;
  days_until_due: number | null;
  is_overdue: boolean;
}

export interface ProjectFile {
  id: number;
  uuid: string;
  name: string;
  file_name: string;
  mime_type: string;
  size: number;
  url: string;
  thumb_url: string | null;
  created_at: string;
}

export interface CalendarEvent {
  id: string;
  title: string;
  start: string;
  type: 'project_deadline' | 'task_deadline';
  color?: string;
  status?: string;
  priority?: string;
}

export class ProjectService extends BaseService {
  /**
   * Fetch projects for a team
   */
  async fetchProjects(
    teamId: string,
    filters: ProjectFilters = {}
  ): Promise<PaginatedResponse<Project>> {
    try {
      const response = await this.api.get<PaginatedResponse<Project>>(
        `/api/teams/${teamId}/projects`,
        { params: filters }
      );
      return response.data;
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Fetch single project
   */
  async fetchProject(teamId: string, projectId: string): Promise<Project> {
    try {
      const response = await this.api.get<ApiResponse<Project>>(
        `/api/teams/${teamId}/projects/${projectId}`
      );
      return this.extractData(response);
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Create project
   */
  async createProject(teamId: string, data: CreateProjectRequest): Promise<Project> {
    try {
      const response = await this.api.post<ApiResponse<Project>>(
        `/api/teams/${teamId}/projects`,
        data
      );
      return this.extractData(response);
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Update project
   */
  async updateProject(
    teamId: string,
    projectId: string,
    data: UpdateProjectRequest
  ): Promise<Project> {
    try {
      const response = await this.api.put<ApiResponse<Project>>(
        `/api/teams/${teamId}/projects/${projectId}`,
        data
      );
      return this.extractData(response);
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Delete project
   */
  async deleteProject(teamId: string, projectId: string): Promise<void> {
    try {
      await this.api.delete(`/api/teams/${teamId}/projects/${projectId}`);
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Archive project
   */
  async archiveProject(
    teamId: string,
    projectId: string
  ): Promise<{ message: string; project: Project }> {
    try {
      const response = await this.api.post<{ message: string; project: Project }>(
        `/api/teams/${teamId}/projects/${projectId}/archive`
      );
      return response.data;
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Unarchive project
   */
  async unarchiveProject(
    teamId: string,
    projectId: string
  ): Promise<{ message: string; project: Project }> {
    try {
      const response = await this.api.post<{ message: string; project: Project }>(
        `/api/teams/${teamId}/projects/${projectId}/unarchive`
      );
      return response.data;
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Get project statistics
   */
  async fetchProjectStats(teamId: string, projectId: string): Promise<ProjectStats> {
    try {
      const response = await this.api.get<ProjectStats>(
        `/api/teams/${teamId}/projects/${projectId}/stats`
      );
      return response.data;
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Get project calendar events
   */
  async fetchCalendarEvents(teamId: string, projectId: string): Promise<CalendarEvent[]> {
    try {
      const response = await this.api.get<CalendarEvent[]>(
        `/api/teams/${teamId}/projects/${projectId}/calendar`
      );
      return response.data;
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Add member to project
   */
  async addMember(
    teamId: string,
    projectId: string,
    userId: string,
    role: string = 'member'
  ): Promise<{ message: string }> {
    try {
      const response = await this.api.post<{ message: string }>(
        `/api/teams/${teamId}/projects/${projectId}/members/${userId}`,
        { role }
      );
      return response.data;
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Remove member from project
   */
  async removeMember(
    teamId: string,
    projectId: string,
    userId: string
  ): Promise<{ message: string }> {
    try {
      const response = await this.api.delete<{ message: string }>(
        `/api/teams/${teamId}/projects/${projectId}/members/${userId}`
      );
      return response.data;
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Update member role
   */
  async updateMemberRole(
    teamId: string,
    projectId: string,
    userId: string,
    role: string
  ): Promise<{ message: string }> {
    try {
      const response = await this.api.put<{ message: string }>(
        `/api/teams/${teamId}/projects/${projectId}/members/${userId}`,
        { role }
      );
      return response.data;
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Get project files
   */
  async fetchFiles(
    teamId: string,
    projectId: string,
    collection: string = 'attachments'
  ): Promise<ProjectFile[]> {
    try {
      const response = await this.api.get<ProjectFile[]>(
        `/api/teams/${teamId}/projects/${projectId}/files`,
        { params: { collection } }
      );
      return response.data;
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Upload file to project
   */
  async uploadFile(
    teamId: string,
    projectId: string,
    file: File,
    collection: string = 'attachments'
  ): Promise<{ message: string; file: ProjectFile }> {
    try {
      const formData = new FormData();
      formData.append('file', file);
      formData.append('collection', collection);

      const response = await this.api.post<{ message: string; file: ProjectFile }>(
        `/api/teams/${teamId}/projects/${projectId}/files`,
        formData,
        {
          headers: {
            'Content-Type': 'multipart/form-data',
          },
        }
      );
      return response.data;
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Delete file from project
   */
  async deleteFile(
    teamId: string,
    projectId: string,
    mediaId: number
  ): Promise<{ message: string }> {
    try {
      const response = await this.api.delete<{ message: string }>(
        `/api/teams/${teamId}/projects/${projectId}/files/${mediaId}`
      );
      return response.data;
    } catch (error) {
      return this.handleError(error);
    }
  }
}

export const projectService = new ProjectService();
