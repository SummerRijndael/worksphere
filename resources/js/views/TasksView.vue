<script setup lang="ts">
import { ref, computed, onMounted, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import { Button, SelectFilter, Tooltip } from "@/components/ui";
import { Plus, Search, Grid, List as ListIcon, User, Info } from "lucide-vue-next";
import TaskBoard from "@/components/tasks/TaskBoard.vue";
import TaskList from "@/components/tasks/TaskList.vue";
import TaskFormModal from "@/components/tasks/TaskFormModal.vue";
// TaskDetailModal removed - using full page view
import { useAuthStore } from "@/stores/auth";
import axios from "axios";

const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();
const hasTeams = computed(() => authStore.hasTeams);

// Role Logic
const userRole = computed(() => {
    if (!authStore.user || !authStore.currentTeamId) return null;
    const team = authStore.user.teams.find(t => t.public_id === authStore.currentTeamId);
    return team?.membership?.role || null;
});

const isTeamLead = computed(() => userRole.value === 'team_lead');
const isQA = computed(() => userRole.value === 'quality_assessor');
const isOperator = computed(() => userRole.value === 'operator');

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
    return tabs.filter(tab => {
        if (tab.id === 'pm_queue') return isTeamLead.value;
        if (tab.id === 'qa_queue') return isTeamLead.value || isQA.value;
        if (tab.id === 'all_tasks') return !isOperator.value;
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

const fetchProjects = async () => {
    if (!authStore.currentTeamId) return;
    try {
        const response = await axios.get(
            `/api/teams/${authStore.currentTeamId}/projects`
        );
        projectOptions.value = response.data.data.map((p: any) => ({
            label: p.name,
            value: p.id,
        }));
    } catch (e) {
        console.error("Failed to fetch projects");
    }
};

const onCreateTask = () => {
    selectedTask.value = null;
    showCreateModal.value = true;
};

const onTaskClick = (task: any) => {
    // Navigate to full detail page
    router.push(
        `/projects/${task.project.id}/tasks/${task.public_id}`
    );
};

const onEditTask = (task: any) => {
    selectedTask.value = task;
    showEditModal.value = true;
};

const onTaskCreated = () => {
    fetchTasks();
    showCreateModal.value = false;
};

const onTaskSaved = () => {
    fetchTasks();
    showEditModal.value = false;
};

// ... (Modals/options logic same)

// Watch Tab Changes to update filters
watch(currentTab, (newTab) => {
    // Reset filters first
    statusFilter.value = "";
    priorityFilter.value = "";
    scopeFilter.value = "all"; // Default to all usually, overridden by specific tab logic

    switch (newTab) {
        case "my_tasks":
            scopeFilter.value = "assigned";
            break;
        case "qa_queue":
            statusFilter.value = "submitted,in_qa";
            break;
        case "pm_queue":
            statusFilter.value = "pm_review";
            break;
        case "all_tasks":
            scopeFilter.value = "all";
            break;
    }
    // Fetch triggered by watchers on filters
});

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
            team_id: authStore.currentTeamId,
            project_id: projectFilter.value,
        };
        
        // Specific sorts or additional filters per tab can go here
        if (currentTab.value === 'qa_queue') {
             // For QA, maybe we want to see oldest submitted first?
             params.sort = 'submitted_at';
             params.direction = 'asc';
        }

        const response = await axios.get("/api/user/tasks", { params });
        tasks.value = response.data.data;
    } catch (error) {
        console.error("Failed to fetch tasks:", error);
    } finally {
        loading.value = false;
    }
};
// ... (rest of handlers)

// Initial Load
onMounted(() => {
    // Check route filters? 
    // For now default to my_tasks
    currentTab.value = "my_tasks"; 
    
    // Explicitly fetch tasks on mount to ensure data loads even if currentTab doesn't change
    fetchTasks();
    fetchProjects();
});

// Watchers
watch([scopeFilter, statusFilter, priorityFilter, projectFilter], () => {
    fetchTasks();
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
                <Button v-else @click="onCreateTask">
                    <Plus class="h-4 w-4" />
                    New Task
                </Button>
            </div>
        </div>

        <div class="flex items-center gap-1 border-b border-[var(--border-default)] mb-4">
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
            <Button v-else class="mt-4" @click="onCreateTask">
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
