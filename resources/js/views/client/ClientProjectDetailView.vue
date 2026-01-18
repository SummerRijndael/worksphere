<script setup lang="ts">
import { ref, onMounted, computed } from 'vue';
import { useRoute, useRouter } from 'vue-router';
import { Card, Button, Badge, PageLoader } from '@/components/ui';
import { ArrowLeft, Folder, Calendar, CheckCircle2, Clock, AlertCircle, ListTodo } from 'lucide-vue-next';
import axios from 'axios';

const route = useRoute();
const router = useRouter();

const isLoading = ref(true);
const error = ref<string | null>(null);
const project = ref<any>(null);
const tasks = ref<any[]>([]);

const projectId = computed(() => route.params.id as string);

const formatDate = (dateString: string) => {
    if (!dateString) return '-';
    return new Date(dateString).toLocaleDateString('en-US', {
        month: 'short',
        day: 'numeric',
        year: 'numeric',
    });
};

const getStatusVariant = (status: string) => {
    const variants: Record<string, string> = {
        planning: 'secondary',
        in_progress: 'info',
        on_hold: 'warning',
        completed: 'success',
        cancelled: 'error',
        archived: 'secondary',
        pending: 'secondary',
        pending_qa: 'warning',
        qa_in_progress: 'warning',
        pending_client: 'info',
        client_approved: 'success',
        client_rejected: 'error',
    };
    return variants[status] || 'secondary';
};

const getProgressColor = (progress: number) => {
    if (progress >= 80) return 'bg-green-500';
    if (progress >= 50) return 'bg-blue-500';
    if (progress >= 25) return 'bg-amber-500';
    return 'bg-gray-400';
};

const completedTasks = computed(() => {
    return tasks.value.filter(t => ['completed', 'archived'].includes(t.status));
});

const pendingTasks = computed(() => {
    return tasks.value.filter(t => !['completed', 'archived'].includes(t.status));
});

const fetchProject = async () => {
    try {
        isLoading.value = true;
        error.value = null;

        const response = await axios.get(`/api/client-portal/projects/${projectId.value}`);
        project.value = response.data.data;
        tasks.value = response.data.tasks || [];
    } catch (err: any) {
        console.error('Failed to fetch project', err);
        if (err.response?.status === 404) {
            error.value = 'Project not found.';
        } else {
            error.value = 'Failed to load project. Please try again.';
        }
    } finally {
        isLoading.value = false;
    }
};

const goBack = () => {
    router.push('/portal/projects');
};

onMounted(() => {
    fetchProject();
});
</script>

<template>
    <div class="p-6 space-y-6">
        <!-- Header -->
        <div class="flex flex-col sm:flex-row justify-between items-start sm:items-center gap-4">
            <div class="flex items-center gap-4">
                <Button variant="ghost" size="sm" @click="goBack">
                    <ArrowLeft class="w-4 h-4" />
                </Button>
                <div>
                    <div class="flex items-center gap-3">
                        <h1 class="text-2xl font-bold text-[var(--text-primary)]">
                            {{ project?.name || 'Project Details' }}
                        </h1>
                        <Badge v-if="project" :variant="getStatusVariant(project.status)" size="md">
                            {{ project.status_label }}
                        </Badge>
                    </div>
                    <p v-if="project?.team" class="text-[var(--text-secondary)]">
                        {{ project.team.name }}
                    </p>
                </div>
            </div>
        </div>

        <!-- Loading State -->
        <PageLoader v-if="isLoading" />

        <!-- Error State -->
        <Card v-else-if="error" padding="lg" class="text-center">
            <AlertCircle class="w-12 h-12 mx-auto text-[var(--color-error)] mb-4" />
            <p class="text-[var(--text-primary)]">{{ error }}</p>
            <Button variant="outline" @click="goBack" class="mt-4">
                Back to Projects
            </Button>
        </Card>

        <!-- Project Content -->
        <template v-else-if="project">
            <div class="grid grid-cols-1 lg:grid-cols-3 gap-6">
                <!-- Main Content -->
                <div class="lg:col-span-2 space-y-6">
                    <!-- Description -->
                    <Card v-if="project.description" padding="lg">
                        <h2 class="font-semibold text-[var(--text-primary)] mb-3">About This Project</h2>
                        <p class="text-[var(--text-secondary)] whitespace-pre-wrap">{{ project.description }}</p>
                    </Card>

                    <!-- Progress -->
                    <Card padding="lg">
                        <div class="flex items-center justify-between mb-4">
                            <h2 class="font-semibold text-[var(--text-primary)]">Progress</h2>
                            <span class="text-2xl font-bold text-[var(--text-primary)]">{{ project.progress || 0 }}%</span>
                        </div>
                        <div class="h-4 bg-[var(--surface-tertiary)] rounded-full overflow-hidden">
                            <div 
                                class="h-full rounded-full transition-all duration-500"
                                :class="getProgressColor(project.progress || 0)"
                                :style="{ width: `${project.progress || 0}%` }"
                            ></div>
                        </div>
                        <div class="flex justify-between mt-3 text-sm text-[var(--text-muted)]">
                            <span>{{ completedTasks.length }} completed</span>
                            <span>{{ pendingTasks.length }} remaining</span>
                        </div>
                    </Card>

                    <!-- Tasks List -->
                    <Card padding="none" class="overflow-hidden">
                        <div class="p-4 border-b border-[var(--border-default)]">
                            <div class="flex items-center gap-2">
                                <ListTodo class="w-5 h-5 text-[var(--text-secondary)]" />
                                <h2 class="font-semibold text-[var(--text-primary)]">Tasks</h2>
                                <Badge variant="secondary" size="sm">{{ tasks.length }}</Badge>
                            </div>
                        </div>

                        <div v-if="tasks.length === 0" class="p-8 text-center text-[var(--text-muted)]">
                            No tasks available for this project.
                        </div>

                        <div v-else class="divide-y divide-[var(--border-default)]">
                            <div 
                                v-for="task in tasks" 
                                :key="task.public_id" 
                                class="p-4 flex items-start gap-4"
                            >
                                <!-- Status Indicator -->
                                <div class="mt-1">
                                    <CheckCircle2 
                                        v-if="task.is_completed" 
                                        class="w-5 h-5 text-[var(--color-success)]" 
                                    />
                                    <div 
                                        v-else 
                                        class="w-5 h-5 rounded-full border-2 border-[var(--border-strong)]"
                                    ></div>
                                </div>

                                <!-- Task Info -->
                                <div class="flex-1 min-w-0">
                                    <div class="flex items-center gap-2 flex-wrap">
                                        <span 
                                            class="font-medium"
                                            :class="task.is_completed ? 'text-[var(--text-muted)] line-through' : 'text-[var(--text-primary)]'"
                                        >
                                            {{ task.title }}
                                        </span>
                                        <Badge :variant="getStatusVariant(task.status)" size="sm">
                                            {{ task.status_label }}
                                        </Badge>
                                    </div>
                                    <p v-if="task.description" class="text-sm text-[var(--text-muted)] mt-1 line-clamp-2">
                                        {{ task.description }}
                                    </p>
                                    <div v-if="task.due_date" class="flex items-center gap-1 mt-2 text-xs text-[var(--text-muted)]">
                                        <Clock class="w-3 h-3" />
                                        <span :class="{ 'text-[var(--color-error)]': task.is_overdue }">
                                            Due {{ formatDate(task.due_date) }}
                                        </span>
                                    </div>
                                </div>

                                <!-- Priority -->
                                <Badge 
                                    v-if="task.priority && task.priority !== 'medium'" 
                                    :variant="task.priority === 'urgent' || task.priority === 'high' ? 'error' : 'secondary'"
                                    size="sm"
                                >
                                    {{ task.priority_label }}
                                </Badge>
                            </div>
                        </div>
                    </Card>
                </div>

                <!-- Sidebar -->
                <div class="space-y-6">
                    <!-- Project Info -->
                    <Card padding="lg">
                        <h3 class="font-semibold text-[var(--text-primary)] mb-4">Project Details</h3>
                        <dl class="space-y-4">
                            <div v-if="project.start_date">
                                <dt class="text-xs font-semibold uppercase text-[var(--text-muted)]">Start Date</dt>
                                <dd class="text-[var(--text-primary)] flex items-center gap-2">
                                    <Calendar class="w-4 h-4 text-[var(--text-muted)]" />
                                    {{ formatDate(project.start_date) }}
                                </dd>
                            </div>
                            <div v-if="project.due_date">
                                <dt class="text-xs font-semibold uppercase text-[var(--text-muted)]">Due Date</dt>
                                <dd class="text-[var(--text-primary)] flex items-center gap-2">
                                    <Calendar class="w-4 h-4 text-[var(--text-muted)]" />
                                    {{ formatDate(project.due_date) }}
                                </dd>
                            </div>
                            <div v-if="project.completed_at">
                                <dt class="text-xs font-semibold uppercase text-[var(--text-muted)]">Completed</dt>
                                <dd class="text-[var(--color-success)] flex items-center gap-2">
                                    <CheckCircle2 class="w-4 h-4" />
                                    {{ formatDate(project.completed_at) }}
                                </dd>
                            </div>
                        </dl>
                    </Card>

                    <!-- Task Summary -->
                    <Card padding="lg">
                        <h3 class="font-semibold text-[var(--text-primary)] mb-4">Task Summary</h3>
                        <div class="space-y-3">
                            <div class="flex justify-between items-center">
                                <span class="text-[var(--text-secondary)]">Total Tasks</span>
                                <span class="font-semibold text-[var(--text-primary)]">{{ project.tasks_count || 0 }}</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-[var(--text-secondary)]">Completed</span>
                                <span class="font-semibold text-[var(--color-success)]">{{ completedTasks.length }}</span>
                            </div>
                            <div class="flex justify-between items-center">
                                <span class="text-[var(--text-secondary)]">In Progress</span>
                                <span class="font-semibold text-[var(--color-info)]">{{ pendingTasks.length }}</span>
                            </div>
                        </div>
                    </Card>
                </div>
            </div>
        </template>
    </div>
</template>
