import { BaseService } from './base.service';
import type { ApiResponse } from '@/types';

// Types
export interface DashboardStat {
  id: string;
  label: string;
  value: string;
  change: string;
  change_value: number;
  trend: 'up' | 'down' | 'neutral';
  icon: string;
  color: string;
}

export interface DashboardFeatures {
  projects_enabled: boolean;
  tickets_enabled: boolean;
  tasks_enabled: boolean;
  invoices_enabled: boolean;
}

export interface ActivityUser {
  name: string;
  avatar_url: string | null;
  initials: string;
}

export interface ActivityItem {
  id: number;
  user: ActivityUser;
  action: string;
  target: string;
  target_type: string;
  target_id: string;
  time: string;
  timestamp: string;
}

export interface ProjectSummary {
  id: string;
  name: string;
  progress: number;
  status: {
    value: string;
    label: string;
  };
  member_count: number;
  due_date: string | null;
  is_overdue: boolean;
}

export interface ChartDataset {
  label: string;
  data: number[];
  borderColor?: string;
  backgroundColor?: string | string[];
}

export interface ActivityChartData {
  labels: string[];
  datasets: ChartDataset[];
}

export interface ProjectStatusChartData {
  labels: string[];
  data: number[];
  backgroundColor: string[];
}

export interface TicketTrendsChartData {
  labels: string[];
  datasets: ChartDataset[];
}

export interface DashboardCharts {
  activity: ActivityChartData;
  project_status: ProjectStatusChartData;
  ticket_trends: TicketTrendsChartData;
}

export interface DashboardData {
  stats: DashboardStat[];
  features: DashboardFeatures;
  activity: ActivityItem[];
  projects: ProjectSummary[];
  charts: DashboardCharts;
}

export interface DashboardStatsResponse {
  stats: DashboardStat[];
  features: DashboardFeatures;
}

class DashboardService extends BaseService {
  /**
   * Fetch complete dashboard data
   */
  async fetchDashboard(teamId?: string): Promise<DashboardData> {
    try {
      const params = teamId ? { team_id: teamId } : {};
      const response = await this.api.get<ApiResponse<DashboardData>>(
        '/api/dashboard',
        { params }
      );
      return this.extractData(response);
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Fetch dashboard statistics only
   */
  async fetchStats(teamId?: string): Promise<DashboardStatsResponse> {
    try {
      const params = teamId ? { team_id: teamId } : {};
      const response = await this.api.get<ApiResponse<DashboardStatsResponse>>(
        '/api/dashboard/stats',
        { params }
      );
      return this.extractData(response);
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Fetch activity feed
   */
  async fetchActivity(teamId?: string, limit: number = 10): Promise<ActivityItem[]> {
    try {
      const params: Record<string, any> = { limit };
      if (teamId) {
        params.team_id = teamId;
      }
      const response = await this.api.get<ApiResponse<ActivityItem[]>>(
        '/api/dashboard/activity',
        { params }
      );
      return this.extractData(response);
    } catch (error) {
      return this.handleError(error);
    }
  }

  /**
   * Fetch chart data
   */
  async fetchCharts(
    teamId?: string,
    period: 'week' | 'month' | 'year' = 'week'
  ): Promise<DashboardCharts> {
    try {
      const params: Record<string, any> = { period };
      if (teamId) {
        params.team_id = teamId;
      }
      const response = await this.api.get<ApiResponse<DashboardCharts>>(
        '/api/dashboard/charts',
        { params }
      );
      return this.extractData(response);
    } catch (error) {
      return this.handleError(error);
    }
  }
}

export const dashboardService = new DashboardService();
