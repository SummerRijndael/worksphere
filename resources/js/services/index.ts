export { authService } from './auth.service';
export { ticketService } from './ticket.service';
export { notificationService } from './notification.service';
export { permissionService } from './permission.service';
export { teamRoleService } from './team-role.service';
export { projectService } from './project.service';
export { dashboardService } from './dashboard.service';
export { faqService } from './faq.service';
export type {
  DashboardStat,
  DashboardFeatures,
  ActivityItem,
  ProjectSummary,
  DashboardCharts,
  DashboardData,
} from './dashboard.service';
export type {
  TeamRole,
  CreateTeamRoleRequest,
  UpdateTeamRoleRequest,
  TeamRoleFilters,
  AvailablePermissions,
} from './team-role.service';
export type {
  Project,
  ProjectMember,
  ProjectStatus,
  ProjectPriority,
  ProjectStats,
  ProjectFile,
  ProjectFilters,
  CreateProjectRequest,
  UpdateProjectRequest,
  CalendarEvent,
} from './project.service';
