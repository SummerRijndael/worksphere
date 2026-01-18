import api from '@/lib/api';

export interface ProjectOverviewStats {
  total_projects: number;
  active_projects: number;
  total_budget: number;
  total_revenue: number;
  avg_progress: number;
}

export interface ProjectStatusDistribution {
  status: string;
  count: number;
  color: string;
}

export interface BudgetVsRevenueItem {
  id: number;
  name: string;
  budget: number;
  revenue: number;
  utilization_rate: number;
}

export interface ProjectReportOverview {
  stats: ProjectOverviewStats;
  charts: {
    status_distribution: ProjectStatusDistribution[];
    budget_vs_revenue: BudgetVsRevenueItem[];
  };
}

export interface ProjectReportItem {
  id: number;
  public_id: string;
  name: string;
  client_name: string;
  status: {
      value: string;
      label: string;
      color: string;
  }; 
  progress: number;
  budget: number;
  collected_revenue: number;
  due_date: string | null;
  is_overdue: boolean;
  overdue_tasks_count: number;
}

export interface ProjectReportFilters {
  status?: string;
  client_id?: number | string;
  search?: string;
  per_page?: number;
  page?: number;
}

export const reportService = {
  getProjectOverview(filters: ProjectReportFilters = {}): Promise<ProjectReportOverview> {
    return api.get('/api/reports/projects/overview', { params: filters }).then((response) => response.data);
  },

  getProjectList(filters: ProjectReportFilters = {}): Promise<{ data: ProjectReportItem[]; meta: any; links: any }> {
    return api.get('/api/reports/projects/list', { params: filters }).then((response) => response.data);
  },

  getProjectSelectorList(teamId?: number | string): Promise<Array<{ id: number; name: string }>> {
    return api.get('/api/reports/projects/selector', { params: { team_id: teamId } }).then((response) => response.data);
  },
};
