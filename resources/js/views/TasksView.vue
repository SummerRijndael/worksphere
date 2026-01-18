<script setup lang="ts">
import { ref, computed, onMounted, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { Button, SelectFilter } from "@/components/ui";
import { Plus, Search, Grid, List as ListIcon } from "lucide-vue-next";
import TaskBoard from "@/components/tasks/TaskBoard.vue";
import TaskList from "@/components/tasks/TaskList.vue";
import TaskFormModal from "@/components/tasks/TaskFormModal.vue";
// TaskDetailModal removed - using full page view
import { useAuthStore } from "@/stores/auth";
import axios from "axios";

const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();

// State
const tasks = ref<any[]>([]);
const loading = ref(true);
const viewMode = ref<"list" | "board">("list");
const searchQuery = ref("");
const statusFilter = ref("");
const priorityFilter = ref("");
const scopeFilter = ref("assigned"); // 'all', 'assigned', 'created'

// Modals
const showCreateModal = ref(false);
const showEditModal = ref(false);
const selectedTask = ref<any>(null);

const teamOptions = computed(() => {
    return (
        authStore.user?.teams?.map((t) => ({
            value: t.public_id,
            label: t.name,
        })) || []
    );
});

const projects = ref<any[]>([]);
const projectFilter = ref("");
const projectOptions = computed(() => {
    return projects.value.map((p) => ({
        value: p.public_id,
        label: p.name,
    }));
});

const selectedTeamId = computed({
    get: () => authStore.currentTeam?.public_id || "",
    set: (val: string) => {
        if (val) authStore.switchTeam(val);
    },
});

// Scope Options
const scopeOptions = [
    { value: "assigned", label: "My Tasks" },
    { value: "created", label: "Created by Me" },
    { value: "all", label: "All Tasks" },
];

// Status Options
const statusOptions = [
    { value: "open", label: "Open" },
    { value: "in_progress", label: "In Progress" },
    { value: "submitted", label: "Submitted" },
    { value: "in_qa", label: "In QA" },
    { value: "completed", label: "Done" },
];

// Priority Options
const priorityOptions = [
    { value: "low", label: "Low" },
    { value: "medium", label: "Medium" },
    { value: "high", label: "High" },
    { value: "urgent", label: "Urgent" },
];

// Fetch Projects
const fetchProjects = async () => {
    if (!authStore.currentTeamId) {
        projects.value = [];
        return;
    }
    try {
        const response = await axios.get(
            `/api/teams/${authStore.currentTeamId}/projects`
        );
        projects.value = response.data.data;
    } catch (error) {
        console.error("Failed to fetch projects:", error);
    }
};

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
            team_id: authStore.currentTeamId, // Filter by current team if set
            project_id: projectFilter.value,
        };
        const response = await axios.get("/api/user/tasks", { params });
        tasks.value = response.data.data;
    } catch (error) {
        console.error("Failed to fetch tasks:", error);
    } finally {
        loading.value = false;
    }
};

// Handlers
const onTaskClick = (task: any) => {
    // Navigate to full page task detail view
    const taskPublicId = task.public_id || task.id;
    // We need project ID. In /api/user/tasks, project should be included.
    const projectPublicId =
        task.project?.public_id || task.project_id || task.project?.id;

    if (projectPublicId && taskPublicId) {
        // Find team ID if possible, but route is /projects/:projectId/tasks/:taskId
        // The view will handle fetching based on route params.
        // Wait, route is /projects/:projectId/tasks/:taskId
        // But ProjectDetailView is /projects/:id
        // My new route is: path: 'projects/:projectId/tasks/:taskId'
        // Actually, let's check router/index.ts. I added 'projects/:projectId/tasks/:taskId'.
        // Wait, 'projects/:id' acts as a prefix? No, they are sibling routes in 'admin' children.
        // So I should navigate to /admin/projects/:projectId/tasks/:taskId
        // But the router handles relative paths.

        // Let's assume standard router push.
        // Note: The task.project might be an object or ID.
        router.push(`/projects/${projectPublicId}/tasks/${taskPublicId}`);
    } else {
        console.error("Cannot navigate to task: missing project ID", task);
        // Fallback or error handling
    }
};

const onCreateTask = () => {
    selectedTask.value = null; // Ensure no task is selected for create
    showCreateModal.value = true;
};

const onEditTask = (task: any) => {
    selectedTask.value = task;
    showEditModal.value = true;
};

const onTaskSaved = () => {
    fetchTasks();
    showCreateModal.value = false;
    showEditModal.value = false;
    // If detail modal is open, refresh it or close it?
    // Usually close and refresh list.
    // If detail modal is open, refresh it or close it?
    // usually close and refresh list.
    // showDetailModal was removed.
};

const onTaskCreated = () => {
    fetchTasks();
    showCreateModal.value = false;
};

const onTaskDeleted = () => {
    fetchTasks();
};

// Watchers
watch([scopeFilter, statusFilter, priorityFilter, projectFilter], () => {
    fetchTasks();
});

// Debounced Search
let searchTimeout: any;
watch(searchQuery, () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(fetchTasks, 300);
});

// Initial Load
onMounted(() => {
    // Check route for initial view mode or filter
    if (route.path.includes("/board")) {
        viewMode.value = "board";
    }
    if (route.path.includes("/my")) {
        scopeFilter.value = "assigned";
    } else {
        // Default to assigned or all? Let's default to assigned for personal view
        scopeFilter.value = "assigned";
    }

    fetchTasks();
    fetchProjects();
});

// Watch current team changes to refetch
watch(
    () => authStore.currentTeamId,
    () => {
        projectFilter.value = ""; // Reset project filter
        fetchTasks();
        fetchProjects();
    }
);

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
                }
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
                <Button @click="onCreateTask">
                    <Plus class="h-4 w-4" />
                    New Task
                </Button>
            </div>
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
            <Button class="mt-4" @click="onCreateTask">
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
            />
            <TaskBoard
                v-else
                :tasks="tasks"
                show-project
                @task-click="onTaskClick"
                @task-moved="onTaskMoved"
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
    </div>
</template>
