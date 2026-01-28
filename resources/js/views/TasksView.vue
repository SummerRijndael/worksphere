<script setup lang="ts">
import { ref, computed, onMounted, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { Button, SelectFilter, Tooltip } from "@/components/ui";
import {
    Plus,
    Search,
    Grid,
    List as ListIcon,
    User,
    Info,
    RefreshCw,
} from "lucide-vue-next";
import TaskBoard from "@/components/tasks/TaskBoard.vue";
import TaskList from "@/components/tasks/TaskList.vue";
import TaskFormModal from "@/components/tasks/TaskFormModal.vue";
import QuickAssignModal from "@/components/tasks/QuickAssignModal.vue";
// TaskDetailModal removed - using full page view
import { useAuthStore } from "@/stores/auth";
import axios from "axios";

const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();
const hasTeams = computed(() => authStore.hasTeams);

// Helper to check permissions
const can = (permission: string) => authStore.hasPermission(permission);

// Role Logic
const userRole = computed(() => {
    if (!authStore.user || !authStore.currentTeamId) return null;
    const team = authStore.user.teams.find(
        (t) => t.public_id === authStore.currentTeamId,
    );
    return team?.membership?.role || null;
});

const isTeamLead = computed(() => userRole.value === "team_lead");
const isQA = computed(() => userRole.value === "quality_assessor");
const isOperator = computed(() => userRole.value === "operator");

// State
const tasks = ref<any[]>([]);
const loading = ref(true);
const viewMode = ref<"list" | "board">("list");
const searchQuery = ref("");
const statusFilter = ref("");
const priorityFilter = ref("");
const scopeFilter = ref("assigned"); // 'all', 'assigned', 'created'

// Tabs
const currentTab = ref("my_tasks");
const tabs = [
    { id: "my_tasks", label: "My Tasks", icon: ListIcon },
    { id: "qa_queue", label: "QA Queue", icon: Search },
    { id: "pm_queue", label: "PM Queue", icon: User },
    { id: "all_tasks", label: "All Tasks", icon: Grid },
];

const visibleTabs = computed(() => {
    return tabs.filter((tab) => {
        if (tab.id === "pm_queue") return isTeamLead.value;
        if (tab.id === "qa_queue") return isTeamLead.value || isQA.value;
        if (tab.id === "all_tasks") return !isOperator.value;
        return true;
    });
});

// Filter Options
const scopeOptions = [
    { label: "Assigned to me", value: "assigned" },
    { label: "Created by me", value: "created" },
    { label: "All Tasks", value: "all" },
];

const statusOptions = [
    { label: "Open", value: "open" },
    { label: "In Progress", value: "in_progress" },
    { label: "Submitted", value: "submitted" },
    { label: "In QA", value: "in_qa" },
    { label: "Approved", value: "approved" },
    { label: "Client Review", value: "sent_to_client" },
    { label: "Completed", value: "completed" },
];

const priorityOptions = [
    { label: "All", value: "" },
    { label: "Low", value: "1" },
    { label: "Medium", value: "2" },
    { label: "High", value: "3" },
    { label: "Urgent", value: "4" },
];

// Project Filter Logic
const projectFilter = ref("");
const projectOptions = ref<any[]>([]);
const teamOptions = ref<any[]>([]);
const selectedTeamId = ref("");

// Modal State
const showCreateModal = ref(false);
const showEditModal = ref(false);
const selectedTask = ref<any>(null);

// Fetch Projects for a specific team
const fetchProjects = async (teamId: string) => {
    projectOptions.value = [];
    projectFilter.value = ""; // Reset project filter

    if (!teamId) return;

    try {
        const response = await axios.get(`/api/teams/${teamId}/projects`);
        // Handle no projects case safely
        const projects = response.data.data || [];

        projectOptions.value = projects.map((p: any) => ({
            label: p.name,
            value: p.id,
        }));

        // Default to first project as requested by user
        if (projectOptions.value.length > 0) {
            projectFilter.value = projectOptions.value[0].value;
        }
    } catch (e) {
        console.error("Failed to fetch projects", e);
    }
};

const onCreateTask = () => {
    selectedTask.value = null;
    showCreateModal.value = true;
};

const onTaskClick = (task: any) => {
    // Navigate to full detail page using Team Scope
    // Assuming task.project.team is available. If not, fallback to authStore.currentTeamId?
    // UserTaskController eager loads project.team.
    const teamId =
        task.project?.team?.public_id ||
        task.project?.team_id ||
        authStore.currentTeamId;

    if (teamId) {
        router.push({
            name: "team-task-detail",
            params: {
                teamId: teamId,
                projectId: task.project?.id || task.project_id,
                taskId: task.public_id,
            },
        });
    } else {
        // Fallback for safety (though unlikely if model structure holds)
        router.push(
            `/projects/${task.project?.id || task.project_id}/tasks/${task.public_id}`,
        );
    }
};

const onEditTask = (task: any) => {
    selectedTask.value = task;
    showEditModal.value = true;
};

const onTaskCreated = (newTask: any) => {
    showCreateModal.value = false;

    // Check if the new task is visible in the current view
    const isAssignedToMe =
        newTask?.assignee?.id === authStore.user?.id ||
        newTask?.assignee?.public_id === authStore.user?.public_id;

    if (scopeFilter.value === "assigned" && !isAssignedToMe) {
        // toast.success("Task created. Switch to 'Created by me' or 'All Tasks' to view it.");
    } else {
        // toast.success("Task created");
    }

    fetchTasks();
};

const onTaskSaved = () => {
    fetchTasks();
    showEditModal.value = false;
};

// Quick Assign Logic
const showQuickAssignModal = ref(false);
const selectedTaskForAssign = ref<any>(null);
const quickAssignType = ref<'operator' | 'qa'>('operator');

const onQuickAssign = (task: any, type: 'operator' | 'qa' = 'operator') => {
    selectedTaskForAssign.value = task;
    quickAssignType.value = type;
    showQuickAssignModal.value = true;
};

const onTaskAssigned = () => {
    fetchTasks();
    // Keep modal open? No, QuickAssignModal closes itself via v-model or emit
    // showQuickAssignModal.value = false; // handled in component by emitting update:open
};

// ... (Modals/options logic same) ...

// Fetch Tasks
const fetchTasks = async () => {
    loading.value = true;
    try {
        const params: any = {
            scope: scopeFilter.value,
            search: searchQuery.value,
            status: statusFilter.value,
            priority: priorityFilter.value,
            include_archived: false,
            // Use the locally selected team ID, not the global store one,
            // because the user is filtering by team in this view.
            team_id: selectedTeamId.value,
            project_id: projectFilter.value,
        };

        console.log("Fetching tasks with params:", params); // Debug log

        // Specific sorts or additional filters per tab can go here
        if (currentTab.value === "qa_queue") {
            params.sort = "submitted_at";
            params.direction = "asc";
        }

        const response = await axios.get("/api/user/tasks", { params });
        tasks.value = response.data.data;
    } catch (error) {
        console.error("Failed to fetch tasks:", error);
    } finally {
        loading.value = false;
    }
};

const STORAGE_KEY = "tasks_view_settings";

// Helper to save state
const saveState = () => {
    // Only save if we have a team selected (to avoid saving empty resets)
    if (!selectedTeamId.value) return;

    const state = {
        scopeFilter: scopeFilter.value,
        statusFilter: statusFilter.value,
        priorityFilter: priorityFilter.value,
        projectFilter: projectFilter.value,
        currentTab: currentTab.value,
        viewMode: viewMode.value,
    };
    localStorage.setItem(STORAGE_KEY, JSON.stringify(state));
};

// Helper to load state
const loadState = () => {
    const saved = localStorage.getItem(STORAGE_KEY);
    if (!saved) return false;
    try {
        const state = JSON.parse(saved);
        if (state.scopeFilter !== undefined)
            scopeFilter.value = state.scopeFilter;
        if (state.statusFilter !== undefined)
            statusFilter.value = state.statusFilter;
        if (state.priorityFilter !== undefined)
            priorityFilter.value = state.priorityFilter;
        if (state.projectFilter !== undefined)
            projectFilter.value = state.projectFilter;
        if (state.currentTab !== undefined) currentTab.value = state.currentTab;
        if (state.viewMode !== undefined) viewMode.value = state.viewMode;
        return true;
    } catch (e) {
        console.error("Failed to parse saved task state", e);
        return false;
    }
};

// Initial Load
onMounted(async () => {
    // 1. Populate Team Options
    if (authStore.user?.teams?.length > 0) {
        teamOptions.value = authStore.user.teams.map((t: any) => ({
            label: t.name,
            value: t.public_id,
        }));

        // 2. Default Team Selection
        // Prioritize current auth team if in list, else first one
        const currentInList = teamOptions.value.find(
            (t: any) => t.value === authStore.currentTeamId,
        );
        selectedTeamId.value = currentInList
            ? currentInList.value
            : teamOptions.value[0].value;
    }

    // 3. Set Default Filters
    const hasSavedState = loadState();
    if (!hasSavedState) {
        currentTab.value = "my_tasks";
        statusFilter.value = "open";
        scopeFilter.value = "assigned";
    }

    // 4. Fetch Projects & Tasks
    if (selectedTeamId.value) {
        await fetchProjects(selectedTeamId.value);
    }

    // Explicitly call fetchTasks AFTER project defaults are set
    fetchTasks();
});

// Watchers
watch(
    [scopeFilter, statusFilter, priorityFilter, projectFilter, viewMode],
    () => {
        saveState();
        fetchTasks();
    },
);

// Sync Tab with Scope
watch(currentTab, (newTab) => {
    if (newTab === "all_tasks") {
        scopeFilter.value = "all";
    } else if (newTab === "my_tasks") {
        scopeFilter.value = "assigned";
    } else if (newTab === "qa_queue") {
        // Maybe 'all' or specific QA logic if needed, but 'all' allows filtering by status
        scopeFilter.value = "all";
        // Optional: set status filter?
        if (statusFilter.value === "open") statusFilter.value = "";
    }
    saveState();
    // Note: Changing scopeFilter triggers the watcher above, calling fetchTasks.
});

// Watch Team Selection Change
watch(selectedTeamId, async (newTeamId) => {
    if (newTeamId) {
        // When team changes, fetch projects for that team
        await fetchProjects(newTeamId);
        // Then fetch tasks (projectFilter change above might trigger it, but let's ensure)
        // Actually projectFilter change triggers watcher above.
        // But if projectFilter goes "" -> "", watcher might not fire.
        // So we should enforce fetchTasks if projectFilter didn't change?
        // Or simpler: fetchTasks relies on defaults.
        // If projects empty, projectFilter is "".
        fetchTasks();
    }
});

// Computed
const activeFilterCount = computed(() => {
    let count = 0;
    if (statusFilter.value) count++;
    if (priorityFilter.value) count++;
    return count;
});

const clearFilters = () => {
    statusFilter.value = "";
    priorityFilter.value = "";
    searchQuery.value = "";
};

// Handle Task Moved (Kanban)
const onTaskMoved = async (taskId: string, newStatus: string) => {
    // Optimistic update
    const taskIndex = tasks.value.findIndex((t: any) => t.public_id === taskId);
    if (taskIndex !== -1) {
        const task = tasks.value[taskIndex];
        const oldStatus = task.status;
        task.status = newStatus;

        try {
            // We need project ID to update task...
            // The TaskResource should include project.
            const project = task.project; // Nested in resource
            if (!project) throw new Error("Project context missing");

            await axios.put(
                `/api/teams/${project.team.id}/projects/${project.id}/tasks/${task.public_id}`,
                {
                    status: newStatus,
                },
            );
        } catch (error) {
            console.error("Failed to move task:", error);
            // Revert
            task.status = oldStatus;
        }
    }
};
</script>

<template>
    <div class="h-full flex flex-col space-y-6">
        <!-- Header -->
        <div
            class="flex flex-col sm:flex-row sm:items-center justify-between gap-4"
        >
            <div>
                <h1 class="text-2xl font-bold text-[var(--text-primary)]">
                    Tasks
                </h1>
                <p class="text-[var(--text-secondary)] mt-1">
                    Manage tasks across all your projects
                </p>
            </div>
            <div class="flex items-center gap-2">
                <div v-if="teamOptions.length > 0" class="w-48">
                    <SelectFilter
                        v-model="selectedTeamId"
                        :options="teamOptions"
                        placeholder="Select Team"
                    />
                </div>
                <!-- Refresh Button -->
                <Button
                    variant="outline"
                    size="icon"
                    @click="fetchTasks"
                    :disabled="loading"
                    title="Refresh Tasks"
                >
                    <RefreshCw
                        class="h-4 w-4"
                        :class="{ 'animate-spin': loading }"
                    />
                </Button>

                <Tooltip v-if="!hasTeams">
                    <template #trigger>
                        <Button disabled class="opacity-60 cursor-not-allowed">
                            <Plus class="h-4 w-4" />
                            New Task
                        </Button>
                    </template>
                    <div class="flex items-center gap-2">
                        <Info class="h-4 w-4" />
                        <span>Join a team to create tasks</span>
                    </div>
                </Tooltip>
                <Button v-else-if="can('tasks.create')" @click="onCreateTask">
                    <Plus class="h-4 w-4" />
                    New Task
                </Button>
            </div>
        </div>

        <div
            class="flex items-center gap-1 border-b border-[var(--border-default)] mb-4"
        >
            <button
                v-for="tab in visibleTabs"
                :key="tab.id"
                @click="currentTab = tab.id"
                class="flex items-center gap-2 px-4 py-2 border-b-2 text-sm font-medium transition-all"
                :class="
                    currentTab === tab.id
                        ? 'border-[var(--interactive-primary)] text-[var(--interactive-primary)]'
                        : 'border-transparent text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:border-[var(--border-default)]'
                "
            >
                <component :is="tab.icon" class="w-4 h-4" />
                {{ tab.label }}
            </button>
        </div>

        <!-- Toolbar -->
        <div
            class="flex flex-col lg:flex-row gap-4 items-start lg:items-center justify-between bg-[var(--surface-primary)] p-4 rounded-xl border border-[var(--border-subtle)] shadow-sm"
        >
            <div class="flex flex-wrap items-center gap-3 flex-1 w-full">
                <!-- Search -->
                <div class="relative flex-1 min-w-[200px] max-w-sm">
                    <Search
                        class="absolute left-3 top-1/2 h-4 w-4 -translate-y-1/2 text-[var(--text-muted)]"
                    />
                    <input
                        v-model="searchQuery"
                        type="text"
                        placeholder="Search tasks..."
                        class="w-full h-9 pl-9 pr-4 rounded-lg bg-[var(--surface-secondary)] border border-[var(--border-default)] text-sm focus:outline-none focus:ring-2 focus:ring-[var(--interactive-primary)]/20 focus:border-[var(--interactive-primary)] transition-all"
                    />
                </div>

                <div
                    class="h-6 w-px bg-[var(--border-default)] hidden sm:block"
                ></div>

                <!-- Scope -->
                <SelectFilter
                    v-model="scopeFilter"
                    :options="scopeOptions"
                    placeholder="View"
                    class="w-40"
                />

                <!-- Project Filter -->
                <SelectFilter
                    v-model="projectFilter"
                    :options="projectOptions"
                    placeholder="Project"
                    class="w-40"
                    searchable
                />

                <!-- Filters -->
                <SelectFilter
                    v-model="statusFilter"
                    :options="statusOptions"
                    placeholder="Status"
                    class="w-32"
                />

                <SelectFilter
                    v-model="priorityFilter"
                    :options="priorityOptions"
                    placeholder="Priority"
                    class="w-32"
                />

                <Button
                    v-if="activeFilterCount > 0"
                    variant="ghost"
                    size="sm"
                    class="text-[var(--interactive-primary)]"
                    @click="clearFilters"
                >
                    Clear filters
                </Button>
            </div>

            <!-- View Toggle -->
            <div
                class="flex items-center gap-2 bg-[var(--surface-secondary)] p-1 rounded-lg border border-[var(--border-default)]"
            >
                <button
                    class="p-1.5 rounded-md transition-all"
                    :class="
                        viewMode === 'list'
                            ? 'bg-[var(--surface-elevated)] text-[var(--interactive-primary)] shadow-sm'
                            : 'text-[var(--text-muted)] hover:text-[var(--text-primary)]'
                    "
                    @click="viewMode = 'list'"
                >
                    <ListIcon class="h-4 w-4" />
                </button>
                <button
                    class="p-1.5 rounded-md transition-all"
                    :class="
                        viewMode === 'board'
                            ? 'bg-[var(--surface-elevated)] text-[var(--interactive-primary)] shadow-sm'
                            : 'text-[var(--text-muted)] hover:text-[var(--text-primary)]'
                    "
                    @click="viewMode = 'board'"
                >
                    <Grid class="h-4 w-4" />
                </button>
            </div>
        </div>

        <!-- Content -->
        <div
            v-if="loading"
            class="flex-1 flex items-center justify-center min-h-[400px]"
        >
            <div
                class="animate-spin rounded-full h-8 w-8 border-b-2 border-[var(--interactive-primary)]"
            ></div>
        </div>

        <div
            v-else-if="tasks.length === 0"
            class="flex-1 flex flex-col items-center justify-center min-h-[400px] text-center border-2 border-dashed border-[var(--border-default)] rounded-xl bg-[var(--surface-secondary)]/30"
        >
            <div
                class="h-12 w-12 rounded-full bg-[var(--surface-secondary)] flex items-center justify-center mb-4"
            >
                <ListIcon class="h-6 w-6 text-[var(--text-muted)]" />
            </div>
            <h3 class="text-lg font-medium text-[var(--text-primary)]">
                No tasks found
            </h3>
            <p class="text-[var(--text-secondary)] mt-1 max-w-sm">
                No tasks match your current filters. Try adjusting them or
                create a new task.
            </p>
            <Tooltip v-if="!hasTeams">
                <template #trigger>
                    <Button disabled class="mt-4 opacity-60 cursor-not-allowed">
                        <Plus class="h-4 w-4" />
                        Create Task
                    </Button>
                </template>
                <div class="flex items-center gap-2">
                    <Info class="h-4 w-4" />
                    <span>Join a team to create tasks</span>
                </div>
            </Tooltip>
            <Button
                v-else-if="can('tasks.create')"
                class="mt-4"
                @click="onCreateTask"
            >
                <Plus class="h-4 w-4" />
                Create Task
            </Button>
        </div>

        <div v-else class="flex-1 min-h-0">
            <TaskList
                v-if="viewMode === 'list'"
                :tasks="tasks"
                show-project
                @task-click="onTaskClick"
                @edit-task="onEditTask"
                @quick-assign="onQuickAssign"
            />
            <TaskBoard
                v-else
                :tasks="tasks"
                show-project
                @task-click="onTaskClick"
                @task-moved="onTaskMoved"
                @quick-assign="onQuickAssign"
            />
        </div>

        <!-- Modals -->

        <TaskFormModal
            v-if="showCreateModal"
            :open="showCreateModal"
            :task="null"
            @update:open="showCreateModal = $event"
            @close="showCreateModal = false"
            @task-saved="onTaskCreated"
        />

        <TaskFormModal
            v-if="showEditModal && selectedTask"
            :open="showEditModal"
            :task="selectedTask"
            :project-id="
                selectedTask.project?.id ||
                selectedTask.project?.public_id ||
                selectedTask.project_id ||
                ''
            "
            :team-id="
                selectedTask.project?.team_id ||
                selectedTask.project?.team?.public_id ||
                authStore.currentTeamId ||
                ''
            "
            @update:open="showEditModal = $event"
            @close="showEditModal = false"
            @task-saved="onTaskSaved"
        />

        <QuickAssignModal
            v-if="showQuickAssignModal && selectedTaskForAssign"
            :open="showQuickAssignModal"
            :task="selectedTaskForAssign"
            :assign-type="quickAssignType"
            @update:open="showQuickAssignModal = $event"
            @assigned="onTaskAssigned"
        />
    </div>
</template>
