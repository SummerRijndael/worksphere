<script setup lang="ts">
import { ref, computed, onMounted, watch } from "vue";
import { useRouter } from "vue-router";
import { useAuthStore } from "@/stores/auth";
import { dashboardService } from "@/services";
import type {
    
    DashboardStat,
    DashboardFeatures,
    ActivityItem,
    DashboardCharts,
    FinancialData,
    TaskDetailData,
    ProjectSummary, // Changed from Project to ProjectSummary
} from "@/services/dashboard.service";
import { projectService, type Project } from "@/services/project.service";
import { Card, Button, Badge, Avatar, Dropdown } from "@/components/ui";
import {
    DashboardLineChart,
    DashboardDoughnutChart,
    DashboardBarChart,
} from "@/components/charts";
import {
    TrendingUp,
    TrendingDown,
    Minus,
    Users,
    FolderKanban,
    Clock,
    Ticket,
    ArrowRight,
    Plus,
    RefreshCw,
    AlertCircle,
    ChevronDown,
    Building2,
    DollarSign,
    CreditCard,
    CheckCircle2,
    Activity,
} from "lucide-vue-next";

const authStore = useAuthStore();
const router = useRouter();

// State
const isLoading = ref(true);
const isRefreshing = ref(false);
const error = ref<string | null>(null);

const stats = ref<DashboardStat[]>([]);
const features = ref<DashboardFeatures>({
    projects_enabled: false,
    tickets_enabled: false,
    tasks_enabled: false,
    invoices_enabled: false,
    is_demo_mode: false,
});
const activity = ref<ActivityItem[]>([]);
const projects = ref<ProjectSummary[]>([]);
const charts = ref<DashboardCharts | null>(null);
const financial = ref<FinancialData | null>(null);
const taskDetail = ref<TaskDetailData | null>(null);

const allProjects = ref<Project[]>([]);
const selectedProjectId = ref<string | null>(null);
const selectedProjectName = computed(() => {
    if (!selectedProjectId.value) return "All Projects";
    const p = allProjects.value.find(p => p.public_id === selectedProjectId.value);
    return p ? p.name : "All Projects";
});

const periods = [
    { label: "7 Days", value: "week" },
    { label: "30 Days", value: "30d" },
    { label: "60 Days", value: "60d" },
    { label: "90 Days", value: "90d" },
];
const selectedPeriod = ref("week");

// Icon mapping
const iconMap: Record<string, any> = {
    "folder-kanban": FolderKanban,
    clock: Clock,
    users: Users,
    ticket: Ticket,
};

// Computed
const currentTeamId = computed(() => authStore.currentTeam?.public_id);
const teams = computed(() => authStore.user?.teams || []);
const currentTeamName = computed(
    () => authStore.currentTeam?.name || "Select Team"
);

// Team switching
function switchTeam(teamPublicId: string) {
    authStore.switchTeam(teamPublicId);
}

// Navigate to team activity
function viewAllActivity() {
    if (currentTeamId.value) {
        router.push({
            name: "team-profile",
            params: { identifier: authStore.currentTeam?.slug || currentTeamId.value },
            query: { tab: "activity" },
        });
    }
}

// Fetch dashboard data
async function fetchDashboard() {
    try {
        error.value = null;
        const data = await dashboardService.fetchDashboard(
            currentTeamId.value,
            selectedPeriod.value,
            selectedProjectId.value || undefined
        );
        stats.value = data.stats;
        features.value = data.features;
        activity.value = data.activity;
        projects.value = data.projects;
        charts.value = data.charts;
        financial.value = data.financial;
        taskDetail.value = data.task_detail;
    } catch (e: any) {
        error.value = e.message || "Failed to load dashboard data";
        console.error("Dashboard fetch error:", e);
    } finally {
        isLoading.value = false;
    }
}

async function fetchAllProjects() {
    if (!currentTeamId.value || !features.value.projects_enabled) return;
    try {
        const response = await projectService.fetchProjects(currentTeamId.value, { per_page: 50 });
        allProjects.value = response.data;
    } catch (e) {
        console.error("Failed to fetch projects for selector:", e);
    }
}

async function refresh() {
    isRefreshing.value = true;
    await fetchDashboard();
    isRefreshing.value = false;
}

// Watch for team or period changes
watch([currentTeamId, selectedPeriod, selectedProjectId], () => {
    isLoading.value = true;
    fetchDashboard();
});

watch(currentTeamId, () => {
    selectedProjectId.value = null;
    fetchAllProjects();
});

onMounted(async () => {
    await fetchDashboard();
    fetchAllProjects();
});

function getStatusColor(
    status: string
): "primary" | "warning" | "secondary" | "success" | "default" {
    switch (status.toLowerCase()) {
        case "in_progress":
        case "in progress":
            return "primary";
        case "on_hold":
        case "on hold":
            return "warning";
        case "planning":
            return "secondary";
        case "completed":
            return "success";
        default:
            return "default";
    }
}

function getTrendIcon(trend: string) {
    switch (trend) {
        case "up":
            return TrendingUp;
        case "down":
            return TrendingDown;
        default:
            return Minus;
    }
}
</script>

<template>
    <div>
        <div class="space-y-6">
            <!-- Welcome Header -->
            <div
                class="flex flex-col sm:flex-row sm:items-center sm:justify-between gap-4"
            >
                <div>
                    <h1 class="text-2xl font-bold text-[var(--text-primary)]">
                        Welcome back, {{ authStore.displayName }}!
                    </h1>
                    <p class="text-[var(--text-secondary)] mt-1">
                        Here's what's happening with your projects today.
                    </p>
                </div>
                <div class="flex items-center gap-3">
                    <!-- Team Selector -->
                    <Dropdown v-if="teams.length > 1" align="end">
                        <template #trigger>
                            <Button variant="outline" size="sm" class="gap-2">
                                <Building2 class="h-4 w-4" />
                                <span class="max-w-[120px] truncate">{{
                                    currentTeamName
                                }}</span>
                                <ChevronDown class="h-4 w-4" />
                            </Button>
                        </template>
                        <div class="py-1">
                            <button
                                v-for="team in teams"
                                :key="team.public_id"
                                @click="switchTeam(team.public_id)"
                                class="w-full flex items-center gap-2 px-4 py-2 text-sm hover:bg-[var(--surface-secondary)] transition-colors"
                                :class="{
                                    'bg-[var(--interactive-primary)]/10 text-[var(--interactive-primary)]':
                                        team.public_id === currentTeamId,
                                }"
                            >
                                <span class="flex-1 text-left truncate">{{
                                    team.name
                                }}</span>
                                <span
                                    v-if="team.public_id === currentTeamId"
                                    class="h-2 w-2 rounded-full bg-[var(--interactive-primary)]"
                                />
                            </button>
                        </div>
                    </Dropdown>

                    <!-- Project Selector -->
                    <Dropdown v-if="features.projects_enabled" align="end">
                        <template #trigger>
                            <Button variant="outline" size="sm" class="gap-2">
                                <FolderKanban class="h-4 w-4" />
                                <span class="max-w-[120px] truncate">{{
                                    selectedProjectName
                                }}</span>
                                <ChevronDown class="h-4 w-4" />
                            </Button>
                        </template>
                        <div class="py-1 max-h-[300px] overflow-y-auto">
                            <button
                                @click="selectedProjectId = null"
                                class="w-full flex items-center gap-2 px-4 py-2 text-sm hover:bg-[var(--surface-secondary)] transition-colors"
                                :class="{
                                    'bg-[var(--interactive-primary)]/10 text-[var(--interactive-primary)]':
                                        !selectedProjectId,
                                }"
                            >
                                <span class="flex-1 text-left truncate">All Projects</span>
                                <span
                                    v-if="!selectedProjectId"
                                    class="h-2 w-2 rounded-full bg-[var(--interactive-primary)]"
                                />
                            </button>
                            <button
                                v-for="project in allProjects"
                                :key="project.public_id"
                                @click="selectedProjectId = project.public_id"
                                class="w-full flex items-center gap-2 px-4 py-2 text-sm hover:bg-[var(--surface-secondary)] transition-colors"
                                :class="{
                                    'bg-[var(--interactive-primary)]/10 text-[var(--interactive-primary)]':
                                        project.public_id === selectedProjectId,
                                }"
                            >
                                <span class="flex-1 text-left truncate">{{
                                    project.name
                                }}</span>
                                <span
                                    v-if="project.public_id === selectedProjectId"
                                    class="h-2 w-2 rounded-full bg-[var(--interactive-primary)]"
                                />
                            </button>
                        </div>
                    </Dropdown>

                    <div class="hidden sm:flex items-center gap-1 bg-[var(--surface-secondary)] p-1 rounded-lg border border-[var(--border-default)]">
                        <Button
                            v-for="p in periods"
                            :key="p.value"
                            variant="ghost"
                            size="sm"
                            class="px-3 h-8 text-xs font-medium transition-all"
                            :class="{ 
                                'bg-white dark:bg-[var(--surface-tertiary)] shadow-sm text-[var(--interactive-primary)]': selectedPeriod === p.value,
                                'text-[var(--text-secondary)] hover:text-[var(--text-primary)]': selectedPeriod !== p.value
                            }"
                            @click="selectedPeriod = p.value"
                        >
                            {{ p.label }}
                        </Button>
                    </div>

                    <Button
                        variant="ghost"
                        size="sm"
                        :disabled="isRefreshing"
                        @click="refresh"
                    >
                        <RefreshCw
                            class="h-4 w-4"
                            :class="{ 'animate-spin': isRefreshing }"
                        />
                    </Button>
    
                </div>
            </div>

            <!-- Error State -->
            <Card
                v-if="error"
                padding="lg"
                class="border-red-500/50 bg-red-500/10"
            >
                <div
                    class="flex items-center gap-3 text-red-600 dark:text-red-400"
                >
                    <AlertCircle class="h-5 w-5" />
                    <p>{{ error }}</p>
                    <Button variant="ghost" size="sm" @click="refresh"
                        >Retry</Button
                    >
                </div>
            </Card>

            <!-- Loading State -->
            <template v-if="isLoading">
                <!-- Stats Skeleton -->
                <div class="grid gap-4 sm:grid-cols-2 lg:grid-cols-4">
                    <Card v-for="i in 4" :key="i" padding="lg">
                        <div class="animate-pulse">
                            <div class="flex items-start justify-between">
                                <div class="space-y-2">
                                    <div
                                        class="h-4 w-24 bg-[var(--surface-tertiary)] rounded"
                                    ></div>
                                    <div
                                        class="h-8 w-16 bg-[var(--surface-tertiary)] rounded"
                                    ></div>
                                </div>
                                <div
                                    class="h-11 w-11 bg-[var(--surface-tertiary)] rounded-xl"
                                ></div>
                            </div>
                            <div
                                class="mt-4 h-4 w-20 bg-[var(--surface-tertiary)] rounded"
                            ></div>
                        </div>
                    </Card>
                </div>

                <!-- Charts Skeleton -->
                <div class="grid gap-6 lg:grid-cols-3">
                    <Card padding="lg" class="lg:col-span-2">
                        <div class="animate-pulse">
                            <div
                                class="h-5 w-32 bg-[var(--surface-tertiary)] rounded mb-4"
                            ></div>
                            <div
                                class="h-64 bg-[var(--surface-tertiary)] rounded"
                            ></div>
                        </div>
                    </Card>
                    <Card padding="lg">
                        <div class="animate-pulse">
                            <div
                                class="h-5 w-32 bg-[var(--surface-tertiary)] rounded mb-4"
                            ></div>
                            <div
                                class="h-56 bg-[var(--surface-tertiary)] rounded-full mx-auto w-56"
                            ></div>
                        </div>
                    </Card>
                </div>
            </template>

            <!-- Loaded Content -->
            <template v-else-if="!error">
                <!-- Stats Grid -->
                <div
                    class="grid gap-4"
                    :class="[
                        stats.length === 4
                            ? 'sm:grid-cols-2 lg:grid-cols-4'
                            : stats.length === 3
                            ? 'sm:grid-cols-3'
                            : 'sm:grid-cols-2',
                    ]"
                >
                    <Card
                        v-for="stat in stats"
                        :key="stat.id"
                        padding="lg"
                        hover
                        class="group"
                    >
                        <div class="flex items-start justify-between">
                            <div>
                                <p
                                    class="text-sm font-medium text-[var(--text-secondary)]"
                                >
                                    {{ stat.label }}
                                </p>
                                <p
                                    class="text-3xl font-bold text-[var(--text-primary)] mt-1 tracking-tight"
                                >
                                    {{ stat.value }}
                                </p>
                            </div>
                            <div
                                :class="[
                                    'flex h-11 w-11 items-center justify-center rounded-xl bg-gradient-to-br shadow-lg',
                                    stat.color,
                                ]"
                            >
                                <component
                                    :is="iconMap[stat.icon] || FolderKanban"
                                    class="h-5 w-5 text-white"
                                />
                            </div>
                        </div>
                        <div class="mt-4 flex items-center gap-2">
                            <div
                                :class="[
                                    'flex items-center gap-1 rounded-full px-2 py-0.5 text-xs font-semibold',
                                    stat.trend === 'up'
                                        ? 'bg-emerald-100 text-emerald-900 dark:bg-emerald-900/40 dark:text-emerald-300'
                                        : stat.trend === 'down'
                                        ? 'bg-red-100 text-red-900 dark:bg-red-900/40 dark:text-red-300'
                                        : 'bg-gray-100 text-gray-700 dark:bg-gray-800 dark:text-gray-300',
                                ]"
                            >
                                <component
                                    :is="getTrendIcon(stat.trend)"
                                    class="h-3 w-3"
                                />
                                {{ stat.change }}
                            </div>
                            <span class="text-xs text-[var(--text-muted)]"
                                >from last month</span
                            >
                        </div>
                    </Card>
                </div>

                <!-- Empty Stats Message -->
                <Card
                    v-if="stats.length === 0"
                    padding="lg"
                    class="text-center"
                >
                    <p class="text-[var(--text-secondary)]">
                        No statistics available for your current permissions.
                    </p>
                </Card>

                <!-- Financial Section -->
                <div v-if="financial && features.invoices_enabled" class="grid gap-6 sm:grid-cols-2">
                    <Card padding="lg" class="bg-gradient-to-br from-[var(--surface-primary)] to-[var(--surface-secondary)] border-l-4 border-emerald-500 overflow-hidden relative group">
                        <div class="absolute -right-4 -top-4 opacity-5 group-hover:opacity-10 transition-opacity">
                            <DollarSign class="h-32 w-32" />
                        </div>
                        <div class="flex items-center gap-4 relative">
                            <div class="h-12 w-12 rounded-2xl bg-emerald-500/10 flex items-center justify-center text-emerald-600">
                                <DollarSign class="h-6 w-6" />
                            </div>
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Collected Payments</p>
                                <div class="flex items-baseline gap-2">
                                    <h3 class="text-3xl font-black text-[var(--text-primary)]">
                                        {{ financial.collected.currency }} {{ financial.collected.value }}
                                    </h3>
                                </div>
                            </div>
                        </div>
                    </Card>

                    <Card padding="lg" class="bg-gradient-to-br from-[var(--surface-primary)] to-[var(--surface-secondary)] border-l-4 border-amber-500 overflow-hidden relative group">
                        <div class="absolute -right-4 -top-4 opacity-5 group-hover:opacity-10 transition-opacity">
                            <CreditCard class="h-32 w-32" />
                        </div>
                        <div class="flex items-center gap-4 relative">
                            <div class="h-12 w-12 rounded-2xl bg-amber-500/10 flex items-center justify-center text-amber-600">
                                <CreditCard class="h-6 w-6" />
                            </div>
                            <div>
                                <p class="text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Pending Payments</p>
                                <div class="flex items-baseline gap-2">
                                    <h3 class="text-3xl font-black text-[var(--text-primary)]">
                                        {{ financial.pending.currency }} {{ financial.pending.value }}
                                    </h3>
                                </div>
                            </div>
                        </div>
                    </Card>
                </div>

                <!-- Task High-Level Breakdown -->
                <Card v-if="taskDetail" padding="lg">
                    <div class="flex items-center justify-between mb-6">
                        <div>
                            <h3 class="text-lg font-bold text-[var(--text-primary)]">Task Breakdown</h3>
                            <p class="text-sm text-[var(--text-secondary)]">Total {{ taskDetail.total }} tasks documented</p>
                        </div>
                        <Badge variant="primary" class="font-mono">{{ taskDetail.total }} Tasks</Badge>
                    </div>
                    
                    <div class="grid gap-6 sm:grid-cols-3">
                        <div class="space-y-3">
                            <div class="flex items-center justify-between">
                                <span class="flex items-center gap-2 text-sm font-medium text-[var(--text-secondary)]">
                                    <CheckCircle2 class="h-4 w-4 text-emerald-500" />
                                    Completed
                                </span>
                                <span class="text-sm font-bold text-emerald-600">{{ taskDetail.completed.count }}</span>
                            </div>
                            <div class="h-2 rounded-full bg-[var(--surface-tertiary)] overflow-hidden">
                                <div class="h-full bg-emerald-500 transition-all duration-1000" :style="{ width: `${taskDetail.completed.percentage}%` }"></div>
                            </div>
                            <p class="text-[10px] text-[var(--text-muted)] text-right font-medium">{{ taskDetail.completed.percentage }}% of total</p>
                        </div>

                        <div class="space-y-3">
                            <div class="flex items-center justify-between">
                                <span class="flex items-center gap-2 text-sm font-medium text-[var(--text-secondary)]">
                                    <Activity class="h-4 w-4 text-blue-500" />
                                    In Progress
                                </span>
                                <span class="text-sm font-bold text-blue-600">{{ taskDetail.in_progress.count }}</span>
                            </div>
                            <div class="h-2 rounded-full bg-[var(--surface-tertiary)] overflow-hidden">
                                <div class="h-full bg-blue-500 transition-all duration-1000" :style="{ width: `${taskDetail.in_progress.percentage}%` }"></div>
                            </div>
                            <p class="text-[10px] text-[var(--text-muted)] text-right font-medium">{{ taskDetail.in_progress.percentage }}% of total</p>
                        </div>

                        <div class="space-y-3">
                            <div class="flex items-center justify-between">
                                <span class="flex items-center gap-2 text-sm font-medium text-[var(--text-secondary)]">
                                    <Clock class="h-4 w-4 text-red-500" />
                                    Past Due
                                </span>
                                <span class="text-sm font-bold text-red-600">{{ taskDetail.past_due.count }}</span>
                            </div>
                            <div class="h-2 rounded-full bg-[var(--surface-tertiary)] overflow-hidden">
                                <div class="h-full bg-red-500 transition-all duration-1000" :style="{ width: `${taskDetail.past_due.percentage}%` }"></div>
                            </div>
                            <p class="text-[10px] text-[var(--text-muted)] text-right font-medium">{{ taskDetail.past_due.percentage }}% of total</p>
                        </div>
                    </div>
                </Card>

                <!-- Charts Row -->
                <div
                    v-if="
                        charts &&
                        (features.projects_enabled || features.tickets_enabled)
                    "
                    class="grid gap-6 lg:grid-cols-3"
                >
                    <!-- Activity Chart -->
                    <Card padding="lg" class="lg:col-span-2">
                        <h3
                            class="text-lg font-semibold text-[var(--text-primary)] mb-4"
                        >
                            Activity Overview
                        </h3>
                        <DashboardLineChart
                            v-if="charts.activity"
                            :labels="charts.activity.labels"
                            :datasets="charts.activity.datasets"
                            :height="280"
                        />
                    </Card>

                    <!-- Project Status Doughnut -->
                    <Card v-if="features.projects_enabled" padding="lg">
                        <h3
                            class="text-lg font-semibold text-[var(--text-primary)] mb-4"
                        >
                            Project Status
                        </h3>
                        <DashboardDoughnutChart
                            v-if="
                                charts.project_status &&
                                charts.project_status.data.length > 0
                            "
                            :labels="charts.project_status.labels"
                            :data="charts.project_status.data"
                            :backgroundColor="
                                charts.project_status.backgroundColor
                            "
                            :height="280"
                        />
                        <div
                            v-else
                            class="h-64 flex items-center justify-center text-[var(--text-muted)]"
                        >
                            No projects yet
                        </div>
                    </Card>
                </div>

                <!-- Ticket Trends Chart -->
                <Card
                    v-if="
                        charts &&
                        features.tickets_enabled &&
                        charts.ticket_trends
                    "
                    padding="lg"
                >
                    <h3
                        class="text-lg font-semibold text-[var(--text-primary)] mb-4"
                    >
                        Ticket Trends
                    </h3>
                    <DashboardBarChart
                        :labels="charts.ticket_trends.labels"
                        :datasets="charts.ticket_trends.datasets"
                        :height="240"
                    />
                </Card>

                <!-- Empty State for Users with No Teams -->
                <div v-if="teams.length === 0" class="py-12 flex flex-col items-center justify-center text-center animate-fade-in">
                    <div class="w-24 h-24 rounded-3xl bg-[var(--surface-tertiary)] flex items-center justify-center mb-6 shadow-xl border border-[var(--border-default)] rotate-3">
                        <Users class="h-10 w-10 text-[var(--interactive-primary)]" />
                    </div>
                    <h2 class="text-3xl font-bold text-[var(--text-primary)] tracking-tight">Create your first team</h2>
                    <p class="text-[var(--text-secondary)] mt-3 max-w-md text-lg leading-relaxed">
                        It looks like you're not part of any teams yet. Create a team to start managing projects, tracking tasks, and collaborating with others.
                    </p>
                    <div class="mt-8 flex flex-col sm:flex-row gap-4">
                        <Button variant="primary" size="lg" shadow="lg" class="px-8 flex items-center gap-2" @click="$router.push('/teams?create=true')">
                            <Plus class="h-5 w-5" />
                            Create Workspace
                        </Button>
                        <Button variant="ghost" size="lg" class="text-[var(--text-secondary)] font-medium" @click="$router.push('/support')">
                            Learn more
                        </Button>
                    </div>
                </div>

                <!-- Content Grid -->
                <div v-else class="grid gap-6 lg:grid-cols-2">
                    <!-- Recent Activity -->
                    <Card padding="none">
                        <div
                            class="flex items-center justify-between p-5 border-b border-[var(--border-default)]"
                        >
                            <h2
                                class="text-lg font-semibold text-[var(--text-primary)]"
                            >
                                Recent Activity
                            </h2>
                            <Button
                                variant="ghost"
                                size="sm"
                                @click="viewAllActivity"
                            >
                                View all
                                <ArrowRight class="h-4 w-4" />
                            </Button>
                        </div>
                        <div class="divide-y divide-[var(--border-muted)]">
                            <div
                                v-for="item in activity"
                                :key="item.id"
                                class="flex items-start gap-3 p-4 hover:bg-[var(--surface-secondary)] transition-colors"
                            >
                                <Avatar
                                    :src="item.user.avatar_url"
                                    :fallback="item.user.initials"
                                    size="sm"
                                />
                                <div class="flex-1 min-w-0">
                                    <p
                                        class="text-sm text-[var(--text-primary)]"
                                    >
                                        <span class="font-semibold">{{
                                            item.user.name
                                        }}</span>
                                        <span
                                            class="text-[var(--text-secondary)]"
                                        >
                                            {{ " " + item.action + " " }}
                                        </span>
                                        <span class="font-semibold">{{
                                            item.target
                                        }}</span>
                                    </p>
                                    <p
                                        class="text-xs text-[var(--text-muted)] mt-1"
                                    >
                                        {{ item.time }}
                                    </p>
                                </div>
                            </div>
                            <div
                                v-if="activity.length === 0"
                                class="p-8 text-center text-[var(--text-muted)]"
                            >
                                No recent activity
                            </div>
                        </div>
                    </Card>

                    <!-- Active Projects -->
                    <Card v-if="features.projects_enabled" padding="none">
                        <div
                            class="flex items-center justify-between p-5 border-b border-[var(--border-default)]"
                        >
                            <h2
                                class="text-lg font-semibold text-[var(--text-primary)]"
                            >
                                Active Projects
                            </h2>
                            <Button
                                variant="ghost"
                                size="sm"
                                @click="$router.push('/projects')"
                            >
                                View all
                                <ArrowRight class="h-4 w-4" />
                            </Button>
                        </div>
                        <div class="divide-y divide-[var(--border-muted)]">
                            <div
                                v-for="project in projects"
                                :key="project.id"
                                class="p-4 hover:bg-[var(--surface-secondary)] transition-colors cursor-pointer"
                                @click="$router.push(`/projects/${project.id}`)"
                            >
                                <div
                                    class="flex items-start justify-between gap-4"
                                >
                                    <div class="flex-1 min-w-0">
                                        <p
                                            class="font-semibold text-[var(--text-primary)]"
                                        >
                                            {{ project.name }}
                                        </p>
                                        <div
                                            class="flex items-center gap-3 mt-2"
                                        >
                                            <Badge
                                                :variant="
                                                    getStatusColor(
                                                        project.status?.value
                                                    )
                                                "
                                                size="sm"
                                            >
                                                {{ project.status?.label }}
                                            </Badge>
                                            <span
                                                class="text-xs text-[var(--text-muted)]"
                                            >
                                                {{ project.member_count }}
                                                members
                                            </span>
                                        </div>
                                    </div>
                                    <div class="text-right">
                                        <p
                                            class="text-sm font-bold text-[var(--text-primary)]"
                                        >
                                            {{ project.progress }}%
                                        </p>
                                    </div>
                                </div>
                                <!-- Progress Bar -->
                                <div
                                    class="mt-3 h-2 rounded-full bg-[var(--surface-tertiary)] overflow-hidden"
                                >
                                    <div
                                        class="h-full rounded-full bg-gradient-to-r from-[var(--color-primary-500)] to-[var(--color-primary-600)] transition-all duration-500"
                                        :style="{
                                            width: `${project.progress}%`,
                                        }"
                                    />
                                </div>
                            </div>
                            <div
                                v-if="projects.length === 0"
                                class="p-8 text-center text-[var(--text-muted)]"
                            >
                                No active projects
                            </div>
                        </div>
                    </Card>

                    <!-- Tickets Summary (if projects not enabled, show this instead) -->
                    <Card
                        v-else-if="features.tickets_enabled"
                        padding="lg"
                        class="flex flex-col items-center justify-center min-h-[300px]"
                    >
                        <Ticket
                            class="h-12 w-12 text-[var(--text-muted)] mb-4"
                        />
                        <p class="text-[var(--text-secondary)] text-center">
                            View your tickets in the Tickets section
                        </p>
                        <Button
                            variant="outline"
                            class="mt-4"
                            @click="$router.push('/tickets')"
                        >
                            Go to Tickets
                        </Button>
                    </Card>
                </div>
            </template>
        </div>
    </div>
</template>
