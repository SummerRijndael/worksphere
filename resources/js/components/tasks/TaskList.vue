<script setup lang="ts">
import { defineProps, defineEmits } from 'vue';
import { Badge, Avatar, Button, Dropdown } from '@/components/ui';
import { 
    Calendar, MoreHorizontal, CheckCircle2, Circle, Clock, ArrowUpCircle, 
    Upload, Search, CheckCircle, XCircle, Send, XOctagon, CheckSquare, 
    Archive, AlertCircle, AlertTriangle, Minus, ArrowUp, ArrowDown, Folder, Users
} from 'lucide-vue-next';
import { isPast, parseISO } from 'date-fns';

interface Task {
    public_id: string;
    title: string;
    description: string;
    status: {
        value: string;
        label: string;
        color: string;
    };
    priority: string | number;
    due_date?: string;
    assignee?: {
        name: string;
        avatar_url: string;
    };
    created_at: string;
}

interface Props {
    tasks: Task[];
    showProject?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    showProject: false
});

const emit = defineEmits<{
    (e: 'task-click', task: Task): void;
    (e: 'edit-task', task: Task): void;
    (e: 'delete-task', task: Task): void;
}>();

const getStatusColor = (status: any) => {
    // If status is an object from the API
    if (typeof status === 'object' && status !== null && status.color) {
        return status.color;
    }
    
    // Fallback for legacy string status
    switch (status) {
        case 'completed': return 'success';
        case 'in_progress': return 'primary';
        case 'review': return 'warning';
        case 'submitted': return 'warning';
        case 'open': return 'info';
        case 'in_qa': return 'warning';
        default: return 'secondary';
    }
};

const getStatusLabel = (status: any) => {
    // If status is an object from the API
    if (typeof status === 'object' && status !== null && status.label) {
        return status.label;
    }
    
    // Fallback for legacy string status
    switch (status) {
        case 'completed': return 'Done';
        case 'in_progress': return 'In Progress';
        case 'review': return 'Review';
        case 'submitted': return 'Submitted';
        case 'open': return 'Open';
        case 'in_qa': return 'In QA';
        default: return status?.replace('_', ' ') || 'To Do';
    }
};

const getStatusIcon = (status: any) => {
    const value = (typeof status === 'object' && status !== null) ? status.value : status;

    switch (value) {
        case 'completed': return CheckSquare;
        case 'in_progress': return Clock;
        case 'in_qa': return Search;
        case 'review': return ArrowUpCircle;
        case 'submitted': return Upload;
        case 'open': return Circle;
        case 'approved': return CheckCircle;
        case 'rejected': return XCircle;
        case 'sent_to_client': return Send;
        case 'client_approved': return CheckCircle2;
        case 'client_rejected': return XOctagon;
        case 'archived': return Archive;
        default: return Circle;
    }
};

const getPriorityColor = (priority: string | number) => {
    const p = String(priority).toLowerCase();
    if (p === '4' || p === 'urgent' || p === 'critical') return 'text-red-500 bg-red-50 dark:bg-red-500/10 border-red-200 dark:border-red-900';
    if (p === '3' || p === 'high') return 'text-orange-500 bg-orange-50 dark:bg-orange-500/10 border-orange-200 dark:border-orange-900';
    if (p === '2' || p === 'medium') return 'text-blue-500 bg-blue-50 dark:bg-blue-500/10 border-blue-200 dark:border-blue-900';
    return 'text-gray-500 bg-gray-50 dark:bg-gray-500/10 border-gray-200 dark:border-gray-800'; // 1 or low
};

const getPriorityLabel = (priority: string | number) => {
    const p = String(priority).toLowerCase();
    if (p === '4' || p === 'urgent' || p === 'critical') return 'Critical';
    if (p === '3' || p === 'high') return 'High';
    if (p === '2' || p === 'medium') return 'Medium';
    return 'Low';
};

const getPriorityIcon = (priority: string | number) => {
    const p = String(priority).toLowerCase();
    if (p === '4' || p === 'urgent' || p === 'critical') return AlertCircle;
    if (p === '3' || p === 'high') return ArrowUp;
    if (p === '2' || p === 'medium') return Minus;
    return ArrowDown;
};

const formatDate = (dateString?: string) => {
    if (!dateString) return '-';
    return new Date(dateString).toLocaleDateString(undefined, { month: 'short', day: 'numeric', year: 'numeric' });
};

const isOverdue = (dateString?: string, status?: any) => {
    if (!dateString) return false;
    const statusValue = (typeof status === 'object' && status !== null) ? status.value : status;
    if (['completed', 'approved', 'client_approved', 'archived', 'rejected', 'client_rejected'].includes(statusValue)) return false;
    
    return isPast(parseISO(dateString));
};
</script>

<template>
    <div class="overflow-hidden rounded-xl border border-[var(--border-subtle)] bg-[var(--surface-primary)] shadow-sm">
        <div class="overflow-x-auto">
            <table class="w-full">
                <thead>
                    <tr class="bg-[var(--surface-secondary)]/50 border-b border-[var(--border-subtle)]">
                        <th class="px-6 py-4 text-left text-xs font-semibold uppercase text-[var(--text-muted)] w-10"></th>
                        <th v-if="showProject" class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Project</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)] w-1/3">Task</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Assigned To</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Status</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Priority</th>
                        <th class="px-6 py-4 text-left text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Due Date</th>
                        <th class="px-6 py-4 text-right text-xs font-semibold uppercase tracking-wider text-[var(--text-muted)]">Actions</th>
                    </tr>
                </thead>
                <TransitionGroup tag="tbody" name="list" class="divide-y divide-[var(--border-subtle)]">
                    <tr 
                        v-for="task in tasks" 
                        :key="task.public_id"
                        class="group hover:bg-[var(--surface-secondary)]/30 transition-all duration-200 cursor-pointer"
                        @click="emit('task-click', task)"
                    >
                        <!-- Status Icon Column -->
                        <td class="px-6 py-4">
                            <div 
                                class="p-2 rounded-lg bg-[var(--surface-secondary)] group-hover:bg-[var(--surface-tertiary)] transition-colors flex items-center justify-center w-8 h-8"
                            >
                                <component 
                                    :is="getStatusIcon(task.status)" 
                                    class="w-4 h-4"
                                    :class="{
                                        'text-emerald-500': ['completed', 'approved', 'client_approved'].includes((task.status as any)?.value || task.status),
                                        'text-blue-500': ['in_progress', 'open'].includes((task.status as any)?.value || task.status),
                                        'text-amber-500': ['review', 'submitted', 'in_qa'].includes((task.status as any)?.value || task.status),
                                        'text-rose-500': ['rejected', 'client_rejected'].includes((task.status as any)?.value || task.status),
                                        'text-[var(--text-muted)]': ['archived'].includes((task.status as any)?.value || task.status)
                                    }"
                                />
                            </div>
                        </td>

                        <!-- Project Column -->
                        <td v-if="showProject" class="px-6 py-4">
                            <div class="flex items-center gap-2">
                                <Folder class="w-3.5 h-3.5 text-[var(--text-muted)]" />
                                <span class="text-sm font-medium text-[var(--text-secondary)]">
                                    {{ (task as any).project?.name || '-' }}
                                </span>
                            </div>
                        </td>

                        <!-- Task Details Column -->
                        <td class="px-6 py-4">
                            <div class="flex flex-col gap-1">
                                <span class="text-sm font-semibold text-[var(--text-primary)] group-hover:text-[var(--interactive-primary)] transition-colors">
                                    {{ task.title }}
                                </span>
                                <p class="text-xs text-[var(--text-muted)] line-clamp-1 max-w-[300px]">
                                    {{ task.description || 'No description provided' }}
                                </p>
                            </div>
                        </td>

                        <!-- Assignee Column -->
                        <td class="px-6 py-4">
                            <div v-if="task.assignee" class="flex items-center gap-2.5">
                                <Avatar 
                                    :name="task.assignee.name" 
                                    :src="task.assignee.avatar_url" 
                                    size="sm"
                                    class="ring-2 ring-[var(--surface-primary)]" 
                                />
                                <span class="text-sm font-medium text-[var(--text-secondary)]">
                                    {{ task.assignee.name }}
                                </span>
                            </div>
                            <div v-else class="flex items-center gap-2 text-[var(--text-muted)] bg-[var(--surface-secondary)]/50 px-2 py-1 rounded-md w-fit">
                                <Users class="w-3.5 h-3.5" />
                                <span class="text-xs">Unassigned</span>
                            </div>
                        </td>

                        <!-- Status Column -->
                        <td class="px-6 py-4">
                            <Badge 
                                :variant="getStatusColor(task.status)" 
                                size="md" 
                                class="capitalize shadow-sm border border-current/10"
                            >
                                {{ getStatusLabel(task.status) }}
                            </Badge>
                        </td>

                        <!-- Priority Column -->
                        <td class="px-6 py-4">
                            <div 
                                class="inline-flex items-center gap-1.5 px-2.5 py-1 rounded-full text-xs font-semibold border"
                                :class="getPriorityColor(task.priority).replace('bg-', 'border-').replace('text-', 'text-').replace('bg-', 'bg-opacity-10 ')"
                            >
                                <component 
                                    :is="getPriorityIcon(task.priority)" 
                                    class="w-3.5 h-3.5"
                                />
                                {{ getPriorityLabel(task.priority) }}
                            </div>
                        </td>

                        <!-- Due Date Column -->
                        <td class="px-6 py-4">
                            <div 
                                class="flex items-center gap-2 text-sm"
                                :class="{
                                    'text-rose-500 font-medium': isOverdue(task.due_date, task.status),
                                    'text-[var(--text-secondary)]': !isOverdue(task.due_date, task.status)
                                }"
                            >
                                <Calendar class="w-4 h-4 opacity-70" />
                                {{ formatDate(task.due_date) }}
                            </div>
                        </td>

                        <!-- Actions Column -->
                        <td class="px-6 py-4 text-right relative" @click.stop>
                            <Dropdown 
                                align="end"
                                :items="[
                                    { label: 'Edit Task', icon: 'Edit2', action: () => emit('edit-task', task) },
                                    { label: 'Delete', variant: 'danger', icon: 'Trash2', action: () => emit('delete-task', task) }
                                ]"
                            >
                                <Button 
                                    variant="ghost" 
                                    size="icon"
                                    class="text-[var(--text-muted)] hover:text-[var(--text-primary)] hover:bg-[var(--surface-secondary)] rounded-full"
                                >
                                    <MoreHorizontal class="w-5 h-5" />
                                </Button>
                            </Dropdown>
                        </td>
                    </tr>
                    
                    <!-- Empty State -->
                    <tr v-if="tasks.length === 0" key="empty-state">
                        <td colspan="8" class="px-6 py-16 text-center">
                            <div class="flex flex-col items-center justify-center gap-3">
                                <div class="w-12 h-12 rounded-full bg-[var(--surface-secondary)] flex items-center justify-center">
                                    <CheckCircle2 class="w-6 h-6 text-[var(--text-muted)]" />
                                </div>
                                <h3 class="text-sm font-medium text-[var(--text-primary)]">No tasks found</h3>
                                <p class="text-xs text-[var(--text-muted)] max-w-xs">
                                    Get started by creating a new task or adjusting your filters.
                                </p>
                            </div>
                        </td>
                    </tr>
                </TransitionGroup>
            </table>
        </div>
    </div>
</template>

<style scoped>
.list-enter-active,
.list-leave-active {
    transition: all 0.3s ease;
}
.list-enter-from,
.list-leave-to {
    opacity: 0;
    transform: translateY(10px);
}
.list-move {
    transition: transform 0.3s ease;
}
.list-leave-active {
    position: absolute;
}
</style>
