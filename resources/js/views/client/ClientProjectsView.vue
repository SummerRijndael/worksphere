<script setup lang="ts">
import { ref, onMounted, watch } from "vue";
import { useRouter } from "vue-router";
import {
    Card,
    Button,
    Badge,
    PageLoader,
    Input,
    SelectFilter,
} from "@/components/ui";
import {
    Folder,
    Search,
    ChevronLeft,
    ChevronRight,
    Calendar,
    AlertCircle,
    CheckCircle2,
    Clock,
} from "lucide-vue-next";
import axios from "axios";

const router = useRouter();

const isLoading = ref(true);
const error = ref<string | null>(null);
const projects = ref<any[]>([]);
const pagination = ref({
    current_page: 1,
    last_page: 1,
    per_page: 15,
    total: 0,
});

const filters = ref({
    status: "",
    search: "",
});

const statusOptions = [
    { value: "", label: "All Projects" },
    { value: "planning", label: "Planning" },
    { value: "in_progress", label: "In Progress" },
    { value: "on_hold", label: "On Hold" },
    { value: "completed", label: "Completed" },
];

const formatDate = (dateString: string) => {
    if (!dateString) return "-";
    return new Date(dateString).toLocaleDateString("en-US", {
        month: "short",
        day: "numeric",
        year: "numeric",
    });
};

const getStatusVariant = (status: string) => {
    const variants: Record<string, string> = {
        planning: "secondary",
        in_progress: "info",
        on_hold: "warning",
        completed: "success",
        cancelled: "error",
        archived: "secondary",
    };
    return variants[status] || "secondary";
};

const getProgressColor = (progress: number) => {
    if (progress >= 80) return "bg-green-500";
    if (progress >= 50) return "bg-blue-500";
    if (progress >= 25) return "bg-amber-500";
    return "bg-gray-400";
};

const fetchProjects = async (page = 1) => {
    try {
        isLoading.value = true;
        error.value = null;

        const params: Record<string, any> = {
            page,
            per_page: pagination.value.per_page,
        };

        if (filters.value.status) {
            params.status = filters.value.status;
        }
        if (filters.value.search) {
            params.search = filters.value.search;
        }

        const response = await axios.get("/api/client-portal/projects", {
            params,
        });
        projects.value = response.data.data;
        pagination.value = {
            current_page: response.data.meta.current_page,
            last_page: response.data.meta.last_page,
            per_page: response.data.meta.per_page,
            total: response.data.meta.total,
        };
    } catch (err: any) {
        console.error("Failed to fetch projects", err);
        if (err.response?.status === 404) {
            error.value = "No client profile found for your account.";
        } else {
            error.value = "Failed to load projects. Please try again.";
        }
    } finally {
        isLoading.value = false;
    }
};

const changePage = (page: number) => {
    if (page >= 1 && page <= pagination.value.last_page) {
        fetchProjects(page);
    }
};

const viewProject = (project: any) => {
    router.push(`/portal/projects/${project.public_id}`);
};

// Debounced search
let searchTimeout: ReturnType<typeof setTimeout>;
const onSearchChange = () => {
    clearTimeout(searchTimeout);
    searchTimeout = setTimeout(() => {
        fetchProjects(1);
    }, 300);
};

watch(
    () => filters.value.status,
    () => {
        fetchProjects(1);
    }
);

onMounted(() => {
    fetchProjects();
});
</script>

<template>
    <div class="p-6 space-y-6">
        <!-- Header -->
        <div
            class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4"
        >
            <div>
                <h1 class="text-2xl font-bold text-[var(--text-primary)]">
                    Projects
                </h1>
                <p class="text-[var(--text-secondary)]">
                    Track progress on your active projects
                </p>
            </div>
        </div>

        <!-- Filters -->
        <div class="flex flex-col sm:flex-row gap-4">
            <div class="flex-1 max-w-xs">
                <Input
                    v-model="filters.search"
                    placeholder="Search projects..."
                    @input="onSearchChange"
                >
                    <template #prefix>
                        <Search class="w-4 h-4 text-[var(--text-muted)]" />
                    </template>
                </Input>
            </div>
            <SelectFilter
                v-model="filters.status"
                :options="statusOptions"
                placeholder="Filter by status"
                class="w-48"
            />
        </div>

        <!-- Loading State -->
        <PageLoader v-if="isLoading" />

        <!-- Error State -->
        <Card v-else-if="error" padding="lg" class="text-center">
            <AlertCircle
                class="w-12 h-12 mx-auto text-[var(--color-error)] mb-4"
            />
            <p class="text-[var(--text-primary)]">{{ error }}</p>
        </Card>

        <!-- Projects Grid -->
        <template v-else>
            <Card v-if="projects.length === 0" padding="lg" class="text-center">
                <Folder
                    class="w-12 h-12 mx-auto text-[var(--text-muted)] mb-4"
                />
                <p class="text-[var(--text-primary)]">No projects found</p>
                <p class="text-sm text-[var(--text-muted)] mt-1">
                    {{
                        filters.status || filters.search
                            ? "Try adjusting your filters"
                            : "You don't have any projects yet"
                    }}
                </p>
            </Card>

            <div
                v-else
                class="grid grid-cols-1 md:grid-cols-2 lg:grid-cols-3 gap-6"
            >
                <Card
                    v-for="project in projects"
                    :key="project.public_id"
                    padding="none"
                    class="overflow-hidden cursor-pointer hover:shadow-lg transition-shadow"
                    @click="viewProject(project)"
                >
                    <div class="p-5">
                        <!-- Header -->
                        <div
                            class="flex items-start justify-between gap-3 mb-4"
                        >
                            <div class="flex items-center gap-3">
                                <div
                                    class="p-2 rounded-lg bg-purple-100 text-purple-600 dark:bg-purple-900/30 dark:text-purple-400"
                                >
                                    <Folder class="w-5 h-5" />
                                </div>
                                <div class="min-w-0">
                                    <h3
                                        class="font-semibold text-[var(--text-primary)] truncate"
                                    >
                                        {{ project.name }}
                                    </h3>
                                    <p
                                        v-if="project.team"
                                        class="text-xs text-[var(--text-muted)]"
                                    >
                                        {{ project.team.name }}
                                    </p>
                                </div>
                            </div>
                            <Badge
                                :variant="getStatusVariant(project.status)"
                                size="sm"
                            >
                                {{ project.status_label }}
                            </Badge>
                        </div>

                        <!-- Description -->
                        <p
                            v-if="project.description"
                            class="text-sm text-[var(--text-secondary)] line-clamp-2 mb-4"
                        >
                            {{ project.description }}
                        </p>

                        <!-- Progress Bar -->
                        <div class="space-y-2 mb-4">
                            <div class="flex justify-between text-sm">
                                <span class="text-[var(--text-muted)]"
                                    >Progress</span
                                >
                                <span
                                    class="font-medium text-[var(--text-primary)]"
                                    >{{ project.progress || 0 }}%</span
                                >
                            </div>
                            <div
                                class="h-2 bg-[var(--surface-tertiary)] rounded-full overflow-hidden"
                            >
                                <div
                                    class="h-full rounded-full transition-all duration-300"
                                    :class="
                                        getProgressColor(project.progress || 0)
                                    "
                                    :style="{
                                        width: `${project.progress || 0}%`,
                                    }"
                                ></div>
                            </div>
                        </div>

                        <!-- Meta Info -->
                        <div
                            class="flex items-center justify-between text-sm text-[var(--text-muted)]"
                        >
                            <div class="flex items-center gap-1">
                                <CheckCircle2 class="w-4 h-4" />
                                <span
                                    >{{ project.completed_tasks_count || 0 }}/{{
                                        project.tasks_count || 0
                                    }}
                                    tasks</span
                                >
                            </div>
                            <div
                                v-if="project.due_date"
                                class="flex items-center gap-1"
                            >
                                <Calendar class="w-4 h-4" />
                                <span>{{ formatDate(project.due_date) }}</span>
                            </div>
                        </div>
                    </div>
                </Card>
            </div>

            <!-- Pagination -->
            <div
                v-if="pagination.last_page > 1"
                class="flex items-center justify-between"
            >
                <p class="text-sm text-[var(--text-muted)]">
                    Showing
                    {{
                        (pagination.current_page - 1) * pagination.per_page + 1
                    }}
                    to
                    {{
                        Math.min(
                            pagination.current_page * pagination.per_page,
                            pagination.total
                        )
                    }}
                    of {{ pagination.total }} projects
                </p>
                <div class="flex items-center gap-2">
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="pagination.current_page === 1"
                        @click="changePage(pagination.current_page - 1)"
                    >
                        <ChevronLeft class="w-4 h-4" />
                    </Button>
                    <span class="text-sm text-[var(--text-secondary)]">
                        Page {{ pagination.current_page }} of
                        {{ pagination.last_page }}
                    </span>
                    <Button
                        variant="outline"
                        size="sm"
                        :disabled="
                            pagination.current_page === pagination.last_page
                        "
                        @click="changePage(pagination.current_page + 1)"
                    >
                        <ChevronRight class="w-4 h-4" />
                    </Button>
                </div>
            </div>
        </template>
    </div>
</template>
