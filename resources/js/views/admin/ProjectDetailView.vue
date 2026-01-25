<script setup lang="ts">
import { ref, computed, onMounted, watch } from "vue";
import { useRoute, useRouter } from "vue-router";
import {
    Card,
    Button,
    Badge,
    PageLoader,
    Avatar,
    Input,
    SelectFilter,
    Separator,
    Dropdown,
    Modal,
} from "@/components/ui";
import {
    Folder,
    Calendar,
    CheckCircle2,
    Clock,
    Users,
    ArrowLeft,
    LayoutGrid,
    LayoutList,
    Plus,
    Search,
    Briefcase,
    FileText,
    PieChart,
    MoreHorizontal,
    AlertCircle,
    UserPlus,
    Mail,
    X,
} from "lucide-vue-next";
import axios from "axios";
import { useAuthStore } from "@/stores/auth";
import { toast } from "vue-sonner";
import TaskBoard from "@/components/tasks/TaskBoard.vue";
import TaskList from "@/components/tasks/TaskList.vue";
// TaskDetailModal removed - now using full page TaskDetailView
import TaskFormModal from "@/components/tasks/TaskFormModal.vue";
import MediaManager from "@/components/tools/MediaManager.vue";

const route = useRoute();
const router = useRouter();
const authStore = useAuthStore();

const isLoading = ref(true);
const project = ref<any>(null);
const tasks = ref<any[]>([]);
const stats = ref<any>(null);
const activeTab = ref("tasks"); // overview, tasks, files, team

// Task Management
const taskViewMode = ref<"board" | "list">("board");
const showTaskForm = ref(false);
const selectedTask = ref<any>(null);
const taskFilters = ref({
    search: "",
    status: "",
    assignee: "",
});

// Member Management
const showMemberInvite = ref(false);
const teamMembers = ref<any[]>([]);
const selectedMemberToAdd = ref("");
const selectedMemberRole = ref("member");
const isAddingMember = ref(false);
const isFetchingTeamMembers = ref(false);

const availableTeamMembers = computed(() => {
    if (!teamMembers.value.length || !project.value) return [];
    const projectMemberIds = (project.value.members || []).map(
        (m: any) => m.public_id || m.id
    );
    return teamMembers.value.filter(
        (m: any) => !projectMemberIds.includes(m.public_id || m.id)
    );
});

const memberRoleOptions = [
    { value: "member", label: "Member" },
    { value: "lead", label: "Lead" },
    { value: "manager", label: "Manager" },
];

const currentTeamId = computed(() => authStore.currentTeam?.public_id);
const projectId = computed(() => route.params.id as string);

const formatDate = (dateString?: string) => {
    if (!dateString) return "-";
    return new Date(dateString).toLocaleDateString("en-US", {
        month: "short",
        day: "numeric",
        year: "numeric",
    });
};

const formatCurrency = (amount: number, currency = "USD") => {
    return new Intl.NumberFormat("en-US", {
        style: "currency",
        currency,
    }).format(amount);
};

const isOverdue = (dateString?: string) => {
    if (!dateString) return false;
    return new Date(dateString) < new Date();
};

const getTabIcon = (tab: string) => {
    switch (tab) {
        case "overview":
            return PieChart;
        case "tasks":
            return CheckCircle2;
        case "team":
            return Users;
        case "files":
            return Folder;
        default:
            return FileText;
    }
};

const isScrolled = ref(false);
const checkScroll = (e: Event) => {
    isScrolled.value = (e.target as HTMLElement).scrollTop > 0;
};

const getStatusVariant = (status: string) => {
    const variants: Record<string, string> = {
        draft: "secondary",
        active: "primary",
        on_hold: "warning",
        completed: "success",
        cancelled: "error",
        archived: "secondary",
    };
    return variants[status] || "secondary";
};

// Reactivity to route/auth changes
watch(
    [() => currentTeamId.value, () => projectId.value],
    ([newTeamId, newProjectId]) => {
        if (newTeamId && newProjectId) {
            fetchProject();
        }
    }
);

const fetchProject = async () => {
    // If auth is still loading, wait.
    if (authStore.isLoading) {
        console.log("[ProjectDetail] Auth is still loading, waiting...");
        return;
    }

    console.log("[ProjectDetail] Attempting fetch", {
        currentTeamId: currentTeamId.value,
        projectId: projectId.value,
    });

    if (!currentTeamId.value || !projectId.value) {
        console.warn("[ProjectDetail] Missing team or project ID", {
            currentTeamId: currentTeamId.value,
            projectId: projectId.value,
            authLoading: authStore.isLoading,
        });
        if (!authStore.isLoading) {
            isLoading.value = false;
        }
        return;
    }

    try {
        isLoading.value = true;

        // Fetch project details
        const url = `/api/teams/${currentTeamId.value}/projects/${projectId.value}`;
        console.log("[ProjectDetail] Fetching URL:", url);
        const projectRes = await axios.get(url);
        console.log(
            "[ProjectDetail] API Response:",
            projectRes.status,
            projectRes.data
        );
        // Handle both wrapped {data: project} and unwrapped {project} responses
        project.value = projectRes.data.data || projectRes.data;

        // Fetch stats
        try {
            const statsRes = await axios.get(
                `/api/teams/${currentTeamId.value}/projects/${projectId.value}/stats`
            );
            stats.value = statsRes.data;
        } catch (e) {
            console.error("[ProjectDetail] Failed to fetch stats", e);
        }

        // Fetch tasks
        await fetchTasks();
    } catch (err: any) {
        console.error("[ProjectDetail] Failed to fetch project", {
            status: err.response?.status,
            data: err.response?.data,
            message: err.message,
        });
        toast.error(
            err.response?.data?.message || "Failed to load project details"
        );
    } finally {
        isLoading.value = false;
    }
};

const fetchTasks = async () => {
    try {
        const params: any = {
            per_page: 100, // Load enough for board view
            sort_by: "sort_order",
            search: taskFilters.value.search,
            status: taskFilters.value.status,
            assignee: taskFilters.value.assignee,
        };

        const tasksRes = await axios.get(
            `/api/teams/${currentTeamId.value}/projects/${projectId.value}/tasks`,
            { params }
        );
        tasks.value = tasksRes.data.data;
    } catch (err) {
        console.error("Failed to fetch tasks", err);
    }
};

const onTaskClick = (task: any) => {
    // Navigate to full page task detail view
    const taskPublicId = task.public_id || task.id;
    router.push(`/projects/${projectId.value}/tasks/${taskPublicId}`);
};

const onTaskMoved = async (taskId: string, newStatus: string) => {
    // Optimistic update
    const taskIndex = tasks.value.findIndex((t) => t.public_id === taskId);
    if (taskIndex !== -1) {
        const oldStatus = tasks.value[taskIndex].status;
        tasks.value[taskIndex].status = newStatus;

        try {
            await axios.put(
                `/api/teams/${currentTeamId.value}/projects/${projectId.value}/tasks/${taskId}`,
                {
                    status: newStatus,
                }
            );
            toast.success("Task status updated");
            // Refresh specific task to get updated data (e.g. if workflow changed something else)
        } catch (err: any) {
            // Revert
            tasks.value[taskIndex].status = oldStatus;
            toast.error(err.response?.data?.message || "Failed to move task");
        }
    }
};

const onCreateTask = () => {
    console.log("ProjectDetailView: onCreateTask clicked", {
        currentTeamId: currentTeamId.value,
        projectId: projectId.value,
    });
    selectedTask.value = null; // Clear checking editing state
    showTaskForm.value = true;
};

const onEditTask = (task: any) => {
    // If coming from detail modal, we might want to close it or keep it?
    // Usually edit form is separate or inline.
    // With TaskFormModal, let's open it with the task.
    selectedTask.value = task;
    showTaskForm.value = true;
};

const onTaskSaved = (savedTask: any) => {
    // If editing, update in list
    const index = tasks.value.findIndex((t) => t.id === savedTask.id);
    if (index !== -1) {
        tasks.value[index] = savedTask;
    } else {
        tasks.value.unshift(savedTask);
    }
    fetchTasks(); // Refresh to be sure
};

const onTaskDeleted = (deletedTask: any) => {
    tasks.value = tasks.value.filter((t) => t.id !== deletedTask.id);
};

const onTaskUpdatedFromDetail = (updatedTask: any) => {
    const index = tasks.value.findIndex((t) => t.id === updatedTask.id);
    if (index !== -1) {
        tasks.value[index] = updatedTask;
    }
};

const onEditTaskFromDetail = (task: any) => {
    // Navigate back and open edit form
    selectedTask.value = task;
    showTaskForm.value = true;
};

// Filters
let searchTimeout: ReturnType<typeof setTimeout>;
const onSearchChange = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        fetchTasks();
    }, 300);
};
watch(() => taskFilters.value.status, fetchTasks);
watch(() => taskFilters.value.assignee, fetchTasks);

// Team Members Functions
const fetchTeamMembers = async () => {
    if (!currentTeamId.value) return;
    try {
        isFetchingTeamMembers.value = true;
        const response = await axios.get(
            `/api/teams/${currentTeamId.value}/members`
        );
        teamMembers.value = response.data.data || response.data || [];
    } catch (err) {
        console.error("Failed to fetch team members", err);
    } finally {
        isFetchingTeamMembers.value = false;
    }
};

const openMemberInvite = () => {
    selectedMemberToAdd.value = "";
    selectedMemberRole.value = "member";
    showMemberInvite.value = true;
};

const addProjectMember = async () => {
    if (
        !selectedMemberToAdd.value ||
        !currentTeamId.value ||
        !projectId.value
    ) {
        toast.error("Please select a team member");
        return;
    }

    try {
        isAddingMember.value = true;
        await axios.post(
            `/api/teams/${currentTeamId.value}/projects/${projectId.value}/members/${selectedMemberToAdd.value}`,
            {
                role: selectedMemberRole.value,
            }
        );
        toast.success("Member added to project");
        showMemberInvite.value = false;
        // Refresh project to get updated members list
        await fetchProject();
    } catch (err: any) {
        toast.error(err.response?.data?.message || "Failed to add member");
    } finally {
        isAddingMember.value = false;
    }
};

interface ProjectFile {
    id: string | number;
    file_name: string;
    name?: string; // Fallback for MediaManager
    mime_type: string;
    size: number;
    url: string;
    thumbnail_url?: string;
    created_at: string;
    extension?: string;
    [key: string]: any;
}

interface UploadItem {
    id: string;
    file: File;
    progress: number;
    status: string;
    error?: string;
}

// File Manager Logic
const files = ref<ProjectFile[]>([]);
const filesLoading = ref(false);
const isUploading = ref(false);
const uploadQueue = ref<UploadItem[]>([]);
const filePage = ref(1);
const filePerPage = ref(10);
const fileSearch = ref("");
const fileFilters = ref({ type: "" });

const filteredFiles = computed(() => {
    let result = files.value;

    if (fileSearch.value) {
        const q = fileSearch.value.toLowerCase();
        result = result.filter((f) => f.file_name.toLowerCase().includes(q));
    }

    if (fileFilters.value.type) {
        if (fileFilters.value.type === "image") {
            result = result.filter((f) => f.mime_type.startsWith("image/"));
        } else if (fileFilters.value.type === "document") {
            result = result.filter(
                (f) =>
                    f.mime_type.includes("pdf") ||
                    f.mime_type.includes("word") ||
                    f.mime_type.includes("text")
            );
        }
    }

    return result;
});

const paginatedFiles = computed(() => {
    const start = (filePage.value - 1) * filePerPage.value;
    const end = start + filePerPage.value;
    return filteredFiles.value.slice(start, end);
});

const fetchFiles = async () => {
    if (!project.value) return;
    filesLoading.value = true;
    try {
        const response = await axios.get(
            `/api/teams/${currentTeamId.value}/projects/${projectId.value}/files`
        );
        const rawFiles = response.data.data || response.data;
        files.value = rawFiles.map((f: any) => ({
            ...f,
            name: f.name || f.file_name, // Ensure MediaManager has a name to display
        }));
    } catch (err) {
        console.error("Error fetching files:", err);
        toast.error("Failed to load files");
    } finally {
        filesLoading.value = false;
    }
};

const handleUpload = async (newFiles: File[]) => {
    // Add to queue
    newFiles.forEach((file) => {
        uploadQueue.value.push({
            id: Math.random().toString(36).substr(2, 9),
            file,
            progress: 0,
            status: "pending",
        });
    });

    // Explicit upload required
    // processUploadQueue();
};

const processUploadQueue = async () => {
    if (isUploading.value) return;
    const pendingItems = uploadQueue.value.filter(
        (i) => i.status === "pending"
    );
    if (pendingItems.length === 0) return;

    isUploading.value = true;
    let successCount = 0;

    for (const item of pendingItems) {
        item.status = "uploading";
        const formData = new FormData();
        formData.append("file", item.file);

        try {
            await axios.post(
                `/api/teams/${currentTeamId.value}/projects/${projectId.value}/files`,
                formData,
                {
                    headers: { "Content-Type": "multipart/form-data" },
                    onUploadProgress: (progressEvent) => {
                        const total = progressEvent.total || 100; // Default to avoids divide by zero/undefined
                        const percentCompleted = Math.round(
                            (progressEvent.loaded * 100) / total
                        );
                        item.progress = percentCompleted;
                    },
                }
            );
            item.status = "completed";
            item.progress = 100;
            successCount++;
        } catch (error) {
            console.error(`Error uploading ${item.file.name}:`, error);
            item.status = "error";
            item.error = "Upload failed";
            toast.error(`Failed to upload ${item.file.name}`);
        }
    }

    if (successCount > 0) {
        toast.success(`Uploaded ${successCount} files successfully`);
        fetchFiles();
        // Clear completed
        uploadQueue.value = uploadQueue.value.filter(
            (item) => item.status !== "completed"
        );
    }

    isUploading.value = uploadQueue.value.some((i) => i.status === "uploading");
};

const removeFileFromQueue = (index: number) => {
    uploadQueue.value.splice(index, 1);
};

const handleDeleteFile = async (mediaId: string) => {
    if (!confirm("Are you sure you want to delete this file?")) return;
    try {
        await axios.delete(
            `/api/teams/${currentTeamId.value}/projects/${projectId.value}/files/${mediaId}`
        );
        toast.success("File deleted successfully");
        fetchFiles();
    } catch (error) {
        console.error("Error deleting file:", error);
        toast.error("Failed to delete file");
    }
};

const handleDownload = (file: any) => {
    window.open(file.original_url, "_blank");
};

const handleBulkDelete = async (mediaIds: string[]) => {
    // Basic bulk delete implementation (loop) or specific endpoint if available
    if (!confirm(`Are you sure you want to delete ${mediaIds.length} files?`))
        return;

    try {
        // Since project bulk delete might not imply custom endpoint, let's try individual or custom
        // Route list showed /files/bulk-delete on Team, but check project?
        // Project routes: delete /files/{mediaId}. No bulk listed.
        // We'll simulate loop for now or add endpoint. Team has bulk-delete. Project assumes similar?
        // Let's loop for now to be safe.
        for (const id of mediaIds) {
            await axios.delete(
                `/api/teams/${currentTeamId.value}/projects/${projectId.value}/files/${id}`
            );
        }
        toast.success("Files deleted successfully");
        fetchFiles();
    } catch (error) {
        console.error("Error deleting files:", error);
        toast.error("Failed to delete files");
    }
};

const handleBulkDownload = async (mediaIds: string[]) => {
    // Project bulk download endpoint?
    // Not listed explicitly. Team has it.
    toast.error("Bulk download not yet supported for projects");
};

const handleViewMedia = (payload: any) => {
    // Implementation for preview modal if desired, or just open new tab
    window.open(payload.item.original_url, "_blank");
};

watch(activeTab, (newTab) => {
    if (newTab === "files" && files.value.length === 0) {
        fetchFiles();
    }
});

onMounted(() => {
    fetchProject();
    fetchTeamMembers();
});
</script>

<template>
    <div class="min-h-[calc(100vh-4rem)] flex flex-col bg-[var(--bg-subtle)]">
        <PageLoader v-if="isLoading" />

        <template v-else-if="project">
            <!-- Header Section -->
            <div
                class="shrink-0 bg-[var(--surface-primary)] border-b border-[var(--border-subtle)] z-10 relative"
            >
                <!-- Top Bar -->
                <div class="px-6 sm:px-8 py-4 flex items-center gap-4">
                    <Button
                        variant="ghost"
                        size="sm"
                        class="-ml-2 text-[var(--text-secondary)] hover:text-[var(--text-primary)]"
                        @click="router.push('/admin/projects')"
                    >
                        <ArrowLeft class="w-4 h-4 mr-2" />
                        Back
                    </Button>
                    <Separator orientation="vertical" class="h-5" />

                    <div class="flex items-center gap-3">
                        <Avatar
                            :name="project.name"
                            variant="square"
                            size="md"
                            class="rounded-lg ring-1 ring-[var(--border-subtle)]"
                        />
                        <div>
                            <h1
                                class="text-lg font-bold text-[var(--text-primary)] leading-none mb-1 flex items-center gap-2"
                            >
                                {{ project.name }}
                                <Badge
                                    :variant="getStatusVariant(project.status)"
                                    size="sm"
                                    class="ml-1 capitalize"
                                >
                                    {{
                                        project.status?.label ||
                                        project.status?.value ||
                                        "Unknown"
                                    }}
                                </Badge>
                            </h1>
                            <div
                                class="flex items-center gap-3 text-xs text-[var(--text-secondary)]"
                            >
                                <span
                                    v-if="project.client"
                                    class="flex items-center gap-1.5 hover:text-[var(--text-primary)] transition-colors cursor-pointer"
                                    title="Client"
                                >
                                    <Briefcase class="w-3.5 h-3.5" />
                                    {{ project.client.name }}
                                </span>
                                <span
                                    v-if="project.due_date"
                                    class="flex items-center gap-1.5"
                                    :class="
                                        isOverdue(project.due_date)
                                            ? 'text-red-500 font-medium'
                                            : ''
                                    "
                                    title="Due Date"
                                >
                                    <Calendar class="w-3.5 h-3.5" />
                                    {{ formatDate(project.due_date) }}
                                </span>
                            </div>
                        </div>
                    </div>

                    <div class="ml-auto flex items-center gap-2">
                        <Button
                            v-if="activeTab === 'tasks'"
                            @click="onCreateTask"
                            size="sm"
                            class="shadow-sm"
                        >
                            <Plus class="w-4 h-4 mr-2" />
                            New Task
                        </Button>
                        <Dropdown
                            align="end"
                            :items="[
                                {
                                    label: 'Edit Project',
                                    icon: Users,
                                    action: () => {},
                                }, // TODO: Wired up edit modal
                                { separator: true },
                                {
                                    label: 'Archive Project',
                                    variant: 'danger',
                                    icon: FileText,
                                    action: () => {},
                                },
                            ]"
                        >
                            <Button variant="outline" size="sm">
                                <MoreHorizontal class="w-4 h-4" />
                            </Button>
                        </Dropdown>
                    </div>
                </div>

                <!-- Tabs -->
                <div
                    class="px-6 sm:px-8 flex gap-8 border-t border-[var(--border-subtle)]/50"
                >
                    <button
                        v-for="tab in ['overview', 'tasks', 'team', 'files']"
                        :key="tab"
                        class="py-3 text-sm font-medium border-b-2 capitalize transition-all duration-200 flex items-center gap-2"
                        :class="
                            activeTab === tab
                                ? 'border-[var(--interactive-primary)] text-[var(--interactive-primary)]'
                                : 'border-transparent text-[var(--text-secondary)] hover:text-[var(--text-primary)] hover:border-[var(--border-default)]'
                        "
                        @click="activeTab = tab"
                    >
                        <component :is="getTabIcon(tab)" class="w-4 h-4" />
                        {{ tab }}
                    </button>
                </div>
            </div>

            <!-- Content Area -->
            <div class="flex-1 relative">
                <Transition name="fade-slide" mode="out-in">
                    <!-- Overview Tab -->
                    <div
                        v-if="activeTab === 'overview'"
                        class="absolute inset-0 overflow-y-auto p-6 sm:p-8 space-y-8 bg-[var(--bg-default)]"
                    >
                        <!-- Stats Grid -->
                        <div
                            class="grid grid-cols-1 sm:grid-cols-2 lg:grid-cols-4 gap-4"
                            v-if="stats"
                        >
                            <!-- Total/Progress Card -->
                            <div
                                class="relative overflow-hidden rounded-xl border border-blue-500/30 bg-gray-900/50 dark:bg-gray-900/50 px-6 py-6 transition-all hover:border-blue-500/50"
                            >
                                <div
                                    class="absolute -right-6 -top-6 h-24 w-24 rounded-full bg-blue-500/10 blur-2xl transition-all group-hover:bg-blue-500/20"
                                ></div>

                                <div
                                    class="flex items-start justify-between mb-6"
                                >
                                    <div
                                        class="flex h-10 w-10 items-center justify-center rounded-lg bg-blue-500/10 text-blue-500"
                                    >
                                        <PieChart class="h-5 w-5" />
                                    </div>
                                    <Badge
                                        variant="secondary"
                                        class="bg-blue-500/10 text-blue-500 border-none rounded-full px-3"
                                    >
                                        Overall
                                    </Badge>
                                </div>

                                <div class="relative">
                                    <h3
                                        class="text-xs font-bold uppercase tracking-wider text-[var(--text-muted)] mb-1"
                                    >
                                        Project Progress
                                    </h3>
                                    <p
                                        class="text-3xl font-bold text-[var(--text-primary)]"
                                    >
                                        {{ stats.progress_percentage }}%
                                    </p>
                                </div>
                            </div>

                            <!-- Resolved Card -->
                            <div
                                class="relative overflow-hidden rounded-xl border border-emerald-500/30 bg-gray-900/50 dark:bg-gray-900/50 px-6 py-6 transition-all hover:border-emerald-500/50"
                            >
                                <div
                                    class="absolute -right-6 -top-6 h-24 w-24 rounded-full bg-emerald-500/10 blur-2xl transition-all group-hover:bg-emerald-500/20"
                                ></div>

                                <div
                                    class="flex items-start justify-between mb-6"
                                >
                                    <div
                                        class="flex h-10 w-10 items-center justify-center rounded-lg bg-emerald-500/10 text-emerald-500"
                                    >
                                        <CheckCircle2 class="h-5 w-5" />
                                    </div>
                                    <Badge
                                        variant="secondary"
                                        class="bg-emerald-500/10 text-emerald-500 border-none rounded-full px-3"
                                    >
                                        Tasks
                                    </Badge>
                                </div>

                                <div class="relative">
                                    <h3
                                        class="text-xs font-bold uppercase tracking-wider text-[var(--text-muted)] mb-1"
                                    >
                                        Completed
                                    </h3>
                                    <p
                                        class="text-3xl font-bold text-[var(--text-primary)]"
                                    >
                                        {{ stats.completed_tasks }}
                                    </p>
                                </div>
                            </div>

                            <!-- In Progress Card -->
                            <div
                                class="relative overflow-hidden rounded-xl border border-amber-500/30 bg-gray-900/50 dark:bg-gray-900/50 px-6 py-6 transition-all hover:border-amber-500/50"
                            >
                                <div
                                    class="absolute -right-6 -top-6 h-24 w-24 rounded-full bg-amber-500/10 blur-2xl transition-all group-hover:bg-amber-500/20"
                                ></div>

                                <div
                                    class="flex items-start justify-between mb-6"
                                >
                                    <div
                                        class="flex h-10 w-10 items-center justify-center rounded-lg bg-amber-500/10 text-amber-500"
                                    >
                                        <Clock class="h-5 w-5" />
                                    </div>
                                    <Badge
                                        variant="secondary"
                                        class="bg-amber-500/10 text-amber-500 border-none rounded-full px-3"
                                    >
                                        Active
                                    </Badge>
                                </div>

                                <div class="relative">
                                    <h3
                                        class="text-xs font-bold uppercase tracking-wider text-[var(--text-muted)] mb-1"
                                    >
                                        In Progress
                                    </h3>
                                    <p
                                        class="text-3xl font-bold text-[var(--text-primary)]"
                                    >
                                        {{ stats.in_progress_tasks }}
                                    </p>
                                </div>
                            </div>

                            <!-- Unassigned/Overdue Card -->
                            <div
                                class="relative overflow-hidden rounded-xl border border-rose-500/30 bg-gray-900/50 dark:bg-gray-900/50 px-6 py-6 transition-all hover:border-rose-500/50"
                            >
                                <div
                                    class="absolute -right-6 -top-6 h-24 w-24 rounded-full bg-rose-500/10 blur-2xl transition-all group-hover:bg-rose-500/20"
                                ></div>

                                <div
                                    class="flex items-start justify-between mb-6"
                                >
                                    <div
                                        class="flex h-10 w-10 items-center justify-center rounded-lg bg-rose-500/10 text-rose-500"
                                    >
                                        <AlertCircle class="h-5 w-5" />
                                    </div>
                                    <Badge
                                        variant="secondary"
                                        class="bg-rose-500/10 text-rose-500 border-none rounded-full px-3"
                                    >
                                        Action
                                    </Badge>
                                </div>

                                <div class="relative">
                                    <h3
                                        class="text-xs font-bold uppercase tracking-wider text-[var(--text-muted)] mb-1"
                                    >
                                        Overdue
                                    </h3>
                                    <p
                                        class="text-3xl font-bold text-[var(--text-primary)]"
                                    >
                                        {{ stats.overdue_tasks }}
                                    </p>
                                </div>
                            </div>
                        </div>

                        <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                            <!-- Left Column: Description & Tasks -->
                            <div class="lg:col-span-2 space-y-6">
                                <Card
                                    padding="lg"
                                    class="border-[var(--border-subtle)] shadow-sm"
                                >
                                    <h3
                                        class="text-base font-semibold text-[var(--text-primary)] mb-4 flex items-center gap-2"
                                    >
                                        <FileText
                                            class="w-4 h-4 text-[var(--interactive-primary)]"
                                        />
                                        About Project
                                    </h3>
                                    <div
                                        class="prose prose-sm dark:prose-invert max-w-none text-[var(--text-secondary)] leading-relaxed"
                                    >
                                        {{
                                            project.description ||
                                            "No description provided."
                                        }}
                                    </div>
                                </Card>

                                <Card
                                    padding="lg"
                                    class="border-[var(--border-subtle)] shadow-sm"
                                >
                                    <div
                                        class="flex items-center justify-between mb-4"
                                    >
                                        <h3
                                            class="text-base font-semibold text-[var(--text-primary)] flex items-center gap-2"
                                        >
                                            <CheckCircle2
                                                class="w-4 h-4 text-[var(--interactive-primary)]"
                                            />
                                            Recent Tasks
                                        </h3>
                                        <Button
                                            variant="link"
                                            size="sm"
                                            @click="activeTab = 'tasks'"
                                            class="text-[var(--interactive-primary)]"
                                        >
                                            View All
                                        </Button>
                                    </div>

                                    <div
                                        v-if="tasks.length > 0"
                                        class="space-y-3"
                                    >
                                        <div
                                            v-for="task in tasks.slice(0, 5)"
                                            :key="task.id"
                                            class="flex items-center justify-between p-3 rounded-lg bg-[var(--surface-secondary)]/50 border border-[var(--border-subtle)] hover:border-[var(--border-default)] transition-colors cursor-pointer group"
                                            @click="onTaskClick(task)"
                                        >
                                            <div
                                                class="flex items-center gap-3"
                                            >
                                                <div
                                                    class="w-2 h-2 rounded-full shrink-0"
                                                    :class="{
                                                        'bg-emerald-500':
                                                            (task.status?.value || task.status) ===
                                                            'completed',
                                                        'bg-blue-500':
                                                            (task.status?.value || task.status) ===
                                                            'in_progress',
                                                        'bg-amber-500':
                                                            (task.status?.value || task.status) ===
                                                            'in_qa',
                                                        'bg-[var(--text-muted)]':
                                                            ![
                                                                'completed',
                                                                'in_progress',
                                                                'in_qa',
                                                            ].includes(
                                                                task.status?.value || task.status
                                                            ),
                                                    }"
                                                ></div>
                                                <span
                                                    class="text-sm font-medium text-[var(--text-primary)] group-hover:text-[var(--interactive-primary)] transition-colors"
                                                    >{{ task.title }}</span
                                                >
                                            </div>
                                            <div
                                                class="flex items-center gap-4"
                                            >
                                                <Avatar
                                                    v-if="task.assignee"
                                                    :name="task.assignee.name"
                                                    :src="
                                                        task.assignee.avatar_url
                                                    "
                                                    size="xs"
                                                    class="hidden sm:block"
                                                />
                                                <Badge
                                                    variant="secondary"
                                                    size="sm"
                                                    class="bg-[var(--surface-tertiary)] text-[var(--text-secondary)]"
                                                >
                                                    {{
                                                        task.status?.label || 
                                                        (typeof task.status === 'string' ? task.status.replace("_", " ") : task.status?.value || "Unknown")
                                                    }}
                                                </Badge>
                                            </div>
                                        </div>
                                    </div>
                                    <div
                                        v-else
                                        class="text-center py-8 text-[var(--text-muted)] text-sm"
                                    >
                                        No tasks found in this project.
                                    </div>
                                </Card>
                            </div>

                            <!-- Right Column: Meta Details -->
                            <div class="space-y-6">
                                <Card
                                    padding="lg"
                                    class="border-[var(--border-subtle)] shadow-sm h-fit"
                                >
                                    <h3
                                        class="text-xs font-semibold text-[var(--text-muted)] uppercase tracking-wider mb-4"
                                    >
                                        Project Details
                                    </h3>

                                    <div class="space-y-4">
                                        <div
                                            class="flex items-center justify-between py-1"
                                        >
                                            <div
                                                class="flex items-center gap-2 text-sm text-[var(--text-secondary)]"
                                            >
                                                <Calendar
                                                    class="w-4 h-4 text-[var(--text-muted)]"
                                                />
                                                <span>Start Date</span>
                                            </div>
                                            <span
                                                class="text-sm font-medium text-[var(--text-primary)]"
                                                >{{
                                                    formatDate(
                                                        project.start_date
                                                    )
                                                }}</span
                                            >
                                        </div>
                                        <Separator />
                                        <div
                                            class="flex items-center justify-between py-1"
                                        >
                                            <div
                                                class="flex items-center gap-2 text-sm text-[var(--text-secondary)]"
                                            >
                                                <Clock
                                                    class="w-4 h-4 text-[var(--text-muted)]"
                                                />
                                                <span>Due Date</span>
                                            </div>
                                            <span
                                                class="text-sm font-medium"
                                                :class="
                                                    isOverdue(project.due_date)
                                                        ? 'text-red-600'
                                                        : 'text-[var(--text-primary)]'
                                                "
                                            >
                                                {{
                                                    formatDate(project.due_date)
                                                }}
                                            </span>
                                        </div>
                                        <template v-if="project.budget">
                                            <Separator />
                                            <div
                                                class="flex items-center justify-between py-1"
                                            >
                                                <div
                                                    class="flex items-center gap-2 text-sm text-[var(--text-secondary)]"
                                                >
                                                    <div
                                                        class="w-4 h-4 flex items-center justify-center font-bold text-[var(--text-muted)]"
                                                    >
                                                        $
                                                    </div>
                                                    <span>Budget</span>
                                                </div>
                                                <span
                                                    class="text-sm font-medium text-[var(--text-primary)] font-mono"
                                                    >{{
                                                        formatCurrency(
                                                            project.budget,
                                                            project.currency
                                                        )
                                                    }}</span
                                                >
                                            </div>
                                        </template>
                                        <Separator />
                                        <div
                                            class="flex items-center justify-between py-1"
                                        >
                                            <div
                                                class="flex items-center gap-2 text-sm text-[var(--text-secondary)]"
                                            >
                                                <Users
                                                    class="w-4 h-4 text-[var(--text-muted)]"
                                                />
                                                <span>Creator</span>
                                            </div>
                                            <div
                                                class="flex items-center gap-2"
                                            >
                                                <Avatar
                                                    :name="
                                                        project.creator?.name
                                                    "
                                                    :src="
                                                        project.creator
                                                            ?.avatar_url
                                                    "
                                                    size="xs"
                                                />
                                                <span
                                                    class="text-sm text-[var(--text-primary)] truncate max-w-[100px]"
                                                    >{{
                                                        project.creator?.name
                                                    }}</span
                                                >
                                            </div>
                                        </div>
                                    </div>
                                </Card>

                                <Card
                                    padding="lg"
                                    class="border-[var(--border-subtle)] shadow-sm"
                                >
                                    <div
                                        class="flex items-center justify-between mb-4"
                                    >
                                        <h3
                                            class="text-xs font-semibold text-[var(--text-muted)] uppercase tracking-wider"
                                        >
                                            Team Members
                                        </h3>
                                        <Button
                                            variant="ghost"
                                            size="xs"
                                            @click="activeTab = 'team'"
                                            class="text-[var(--text-muted)] hover:text-[var(--text-primary)]"
                                        >
                                            View All
                                        </Button>
                                    </div>

                                    <div class="space-y-3">
                                        <template
                                            v-for="member in (
                                                project.members || []
                                            ).slice(0, 5)"
                                            :key="member.id"
                                        >
                                            <div
                                                class="flex items-center gap-3 p-2 rounded-lg hover:bg-[var(--surface-secondary)] transition-colors cursor-default"
                                            >
                                                <Avatar
                                                    :name="member.name"
                                                    :src="member.avatar_url"
                                                    size="sm"
                                                />
                                                <div class="flex-1 min-w-0">
                                                    <div
                                                        class="text-sm font-medium text-[var(--text-primary)] truncate"
                                                    >
                                                        {{ member.name }}
                                                    </div>
                                                    <div
                                                        class="text-xs text-[var(--text-muted)] truncate"
                                                    >
                                                        {{ member.email }}
                                                    </div>
                                                </div>
                                                <Badge
                                                    variant="outline"
                                                    size="sm"
                                                    class="text-[10px] uppercase"
                                                    >{{ member.role }}</Badge
                                                >
                                            </div>
                                        </template>

                                        <div
                                            v-if="
                                                (project.members || []).length >
                                                5
                                            "
                                            class="text-center pt-2"
                                        >
                                            <span
                                                class="text-xs text-[var(--text-muted)]"
                                                >+{{
                                                    project.members.length - 5
                                                }}
                                                more members</span
                                            >
                                        </div>
                                    </div>
                                </Card>
                            </div>
                        </div>
                    </div>

                    <!-- Tasks Tab -->
                    <div
                        v-else-if="activeTab === 'tasks'"
                        class="absolute inset-0 flex flex-col"
                    >
                        <!-- Filters Bar -->
                        <div
                            class="px-6 py-3 bg-[var(--surface-primary)]/50 border-b border-[var(--border-subtle)] flex flex-wrap gap-4 items-center justify-between backdrop-blur-sm sticky top-0 z-10 transition-shadow"
                            :class="{ 'shadow-sm': isScrolled }"
                        >
                            <div
                                class="flex items-center gap-3 flex-1 min-w-[200px]"
                            >
                                <Input
                                    v-model="taskFilters.search"
                                    placeholder="Filter tasks..."
                                    class="w-full max-w-xs bg-[var(--surface-primary)]"
                                    @input="onSearchChange"
                                >
                                    <template #prefix>
                                        <Search
                                            class="w-4 h-4 text-[var(--text-muted)]"
                                        />
                                    </template>
                                </Input>
                                <!-- Basic Status Filter Dropdown can go here -->
                            </div>
                            <div
                                class="flex items-center gap-2 bg-[var(--surface-secondary)] p-1 rounded-lg border border-[var(--border-subtle)]"
                            >
                                <button
                                    class="p-1.5 rounded-md transition-all duration-200"
                                    :class="
                                        taskViewMode === 'list'
                                            ? 'bg-[var(--surface-primary)] shadow-sm text-[var(--interactive-primary)]'
                                            : 'text-[var(--text-muted)] hover:text-[var(--text-secondary)]'
                                    "
                                    @click="taskViewMode = 'list'"
                                    title="List View"
                                >
                                    <LayoutList class="w-4 h-4" />
                                </button>
                                <button
                                    class="p-1.5 rounded-md transition-all duration-200"
                                    :class="
                                        taskViewMode === 'board'
                                            ? 'bg-[var(--surface-primary)] shadow-sm text-[var(--interactive-primary)]'
                                            : 'text-[var(--text-muted)] hover:text-[var(--text-secondary)]'
                                    "
                                    @click="taskViewMode = 'board'"
                                    title="Board View"
                                >
                                    <LayoutGrid class="w-4 h-4" />
                                </button>
                            </div>
                        </div>

                        <div
                            class="flex-1 overflow-hidden p-6 bg-[var(--bg-default)]"
                            @scroll="checkScroll"
                        >
                            <TaskBoard
                                v-if="taskViewMode === 'board'"
                                :tasks="tasks"
                                @task-click="onTaskClick"
                                @task-moved="onTaskMoved"
                                class="h-full"
                            />
                            <div v-else class="w-full">
                                <TaskList
                                    :tasks="tasks"
                                    @task-click="onTaskClick"
                                    @edit-task="onEditTask"
                                />
                            </div>
                        </div>
                    </div>

                    <!-- Team Tab -->
                    <div
                        v-else-if="activeTab === 'team'"
                        class="w-full p-6 sm:p-8 space-y-8"
                    >
                        <div class="flex items-center justify-between">
                            <div>
                                <h2
                                    class="text-2xl font-bold text-[var(--text-primary)]"
                                >
                                    Team Members
                                </h2>
                                <p class="text-[var(--text-muted)] mt-1">
                                    Manage access and roles for this project
                                </p>
                            </div>
                            <Button @click="openMemberInvite">
                                <UserPlus class="w-4 h-4 mr-2" />
                                Add Member
                            </Button>
                        </div>

                        <div
                            class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6"
                        >
                            <div
                                v-for="member in project.members"
                                :key="member.id"
                                class="group relative overflow-hidden rounded-xl border border-[var(--border-subtle)] bg-[var(--surface-primary)] p-0 transition-all hover:border-[var(--interactive-primary)]/50 hover:shadow-lg dark:bg-gray-900/40"
                            >
                                <!-- Card Header / Banner -->
                                <div
                                    class="h-24 w-full bg-gradient-to-r from-[var(--surface-secondary)] to-[var(--bg-default)] relative opacity-50 group-hover:opacity-80 transition-opacity"
                                >
                                    <div
                                        class="absolute inset-0 bg-grid-white/[0.02] bg-[length:16px_16px]"
                                    ></div>
                                </div>

                                <!-- Content -->
                                <div class="relative px-6 pb-6 -mt-10">
                                    <div class="flex justify-between items-end">
                                        <Avatar
                                            :name="member.name"
                                            :src="member.avatar_url"
                                            size="lg"
                                            class="ring-4 ring-[var(--bg-default)] shadow-sm"
                                        />
                                        <Badge
                                            variant="secondary"
                                            class="mb-6 capitalize bg-[var(--surface-secondary)] text-[var(--text-secondary)] group-hover:bg-[var(--interactive-primary)]/10 group-hover:text-[var(--interactive-primary)] transition-colors"
                                        >
                                            {{ member.role }}
                                        </Badge>
                                    </div>

                                    <div class="mt-4 space-y-1">
                                        <h3
                                            class="font-bold text-lg text-[var(--text-primary)] group-hover:text-[var(--interactive-primary)] transition-colors"
                                        >
                                            {{ member.name }}
                                        </h3>
                                        <p
                                            class="text-sm text-[var(--text-muted)]"
                                        >
                                            {{ member.email }}
                                        </p>
                                    </div>

                                    <div
                                        class="mt-6 flex items-center gap-3 pt-6 border-t border-[var(--border-subtle)]"
                                    >
                                        <div
                                            class="flex-1 text-xs text-[var(--text-muted)]"
                                        >
                                            <span class="block font-medium"
                                                >Joined</span
                                            >
                                            {{ formatDate(member.created_at) }}
                                        </div>
                                        <div class="flex gap-2">
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                class="h-8 w-8 text-[var(--text-muted)] hover:text-[var(--text-primary)]"
                                            >
                                                <Mail class="w-4 h-4" />
                                            </Button>
                                            <Button
                                                variant="ghost"
                                                size="icon"
                                                class="h-8 w-8 text-[var(--text-muted)] hover:text-red-500 hover:bg-red-500/10"
                                            >
                                                <X class="w-4 h-4" />
                                            </Button>
                                        </div>
                                    </div>
                                </div>
                            </div>

                            <!-- Add New Member Placeholder Card -->
                            <button
                                @click="openMemberInvite"
                                class="group relative flex h-full min-h-[200px] flex-col items-center justify-center rounded-xl border border-dashed border-[var(--border-subtle)] bg-[var(--bg-subtle)]/50 transition-all hover:border-[var(--interactive-primary)] hover:bg-[var(--interactive-primary)]/5"
                            >
                                <div
                                    class="flex h-12 w-12 items-center justify-center rounded-full bg-[var(--surface-secondary)] group-hover:scale-110 transition-transform mb-4"
                                >
                                    <Plus
                                        class="w-6 h-6 text-[var(--text-muted)] group-hover:text-[var(--interactive-primary)]"
                                    />
                                </div>
                                <span
                                    class="font-medium text-[var(--text-secondary)] group-hover:text-[var(--interactive-primary)]"
                                    >Add Team Member</span
                                >
                            </button>
                        </div>
                    </div>

                    <!-- Files Tab -->
                    <div
                        v-else-if="activeTab === 'files'"
                        class="w-full h-full p-6 sm:p-8"
                    >
                        <div class="h-[calc(100vh-14rem)] min-h-[500px]">
                            <MediaManager
                                :items="paginatedFiles"
                                :total="filteredFiles.length"
                                :current-page="filePage"
                                :per-page="filePerPage"
                                :search="fileSearch"
                                :filters="fileFilters"
                                :loading="filesLoading"
                                :can-upload="true"
                                :can-delete="true"
                                :uploading="isUploading"
                                :upload-queue="uploadQueue"
                                :storage-used="project.storage_used || 0"
                                :storage-limit="project.storage_limit || 0"
                                @update:page="filePage = $event"
                                @update:per-page="filePerPage = $event"
                                @update:search="fileSearch = $event"
                                @update:filters="fileFilters = $event"
                                @upload="handleUpload"
                                @delete="handleDeleteFile"
                                @download="handleDownload"
                                @view="handleViewMedia"
                                @remove-upload="removeFileFromQueue"
                                @process-queue="processUploadQueue"
                                @bulk-delete="handleBulkDelete"
                                @bulk-download="handleBulkDownload"
                            />
                        </div>
                    </div>
                </Transition>
            </div>

            <!-- Modals -->

            <TaskFormModal
                v-model:open="showTaskForm"
                :task="selectedTask"
                :team-id="currentTeamId"
                :project-id="projectId"
                :project-members="project.members || []"
                @task-saved="onTaskSaved"
            />

            <!-- Add Project Member Modal -->
            <Modal
                v-model:open="showMemberInvite"
                title="Add Team Member to Project"
            >
                <div class="space-y-4">
                    <p class="text-sm text-[var(--text-secondary)]">
                        Select a team member to add to this project. Only team
                        members can be added.
                    </p>

                    <div
                        v-if="availableTeamMembers.length === 0"
                        class="p-4 bg-[var(--surface-secondary)] rounded-lg text-center"
                    >
                        <Users
                            class="w-8 h-8 text-[var(--text-muted)] mx-auto mb-2"
                        />
                        <p class="text-sm text-[var(--text-muted)]">
                            {{
                                isFetchingTeamMembers
                                    ? "Loading team members..."
                                    : "All team members are already in this project"
                            }}
                        </p>
                    </div>

                    <template v-else>
                        <div>
                            <label
                                class="block text-sm font-medium text-[var(--text-secondary)] mb-1"
                                >Team Member</label
                            >
                            <SelectFilter
                                v-model="selectedMemberToAdd"
                                :options="
                                    availableTeamMembers.map((m) => ({
                                        value: m.public_id,
                                        label: m.name,
                                    }))
                                "
                                placeholder="Select a team member"
                            />
                        </div>

                        <div>
                            <label
                                class="block text-sm font-medium text-[var(--text-secondary)] mb-1"
                                >Project Role</label
                            >
                            <SelectFilter
                                v-model="selectedMemberRole"
                                :options="memberRoleOptions"
                            />
                        </div>
                    </template>
                </div>

                <template #footer>
                    <div class="flex justify-end gap-3">
                        <Button
                            variant="outline"
                            @click="showMemberInvite = false"
                            >Cancel</Button
                        >
                        <Button
                            @click="addProjectMember"
                            :disabled="!selectedMemberToAdd || isAddingMember"
                            :loading="isAddingMember"
                        >
                            Add to Project
                        </Button>
                    </div>
                </template>
            </Modal>
        </template>

        <div
            v-else
            class="flex flex-col items-center justify-center flex-1 h-full text-center p-8"
        >
            <div class="bg-[var(--surface-secondary)] p-4 rounded-full mb-4">
                <Folder class="w-8 h-8 text-[var(--text-muted)]" />
            </div>
            <h3 class="text-lg font-semibold text-[var(--text-primary)]">
                Project not found
            </h3>
            <p class="text-[var(--text-secondary)] mt-2 mb-6 max-w-sm">
                The project you are looking for does not exist or you do not
                have permission to view it.
            </p>
            <Button @click="router.push('/admin/projects')">
                <ArrowLeft class="w-4 h-4 mr-2" />
                Return to Projects
            </Button>
            <div
                class="mt-8 p-4 bg-gray-100 dark:bg-gray-800 rounded text-left text-xs font-mono overflow-auto max-w-lg"
            >
                <p>Debug Info:</p>
                <p>Team ID: {{ currentTeamId || "None" }}</p>
                <p>Project ID: {{ projectId || "None" }}</p>
                <p>Loading: {{ isLoading }}</p>
            </div>
        </div>
    </div>
</template>
