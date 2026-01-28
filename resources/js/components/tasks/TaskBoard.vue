<script setup lang="ts">
import { ref } from "vue";
import { Badge, Avatar } from "@/components/ui";
import { Calendar, User } from "lucide-vue-next";

interface Task {
    public_id: string;
    title: string;
    description: string;
    status: {
        value: string;
        label: string;
        color: string;
    } | string;
    priority: string;
    due_date?: string;
    assignee?: {
        name: string;
        avatar_url: string;
    };
    qa_user?: {
        name: string;
        avatar_url: string;
    };
    project?: {
        name: string;
    };
    created_at: string;
    public_id: string; // Ensure this is consistently present
}

interface Props {
    tasks: Task[];
    statuses?: { value: string; label: string; color: string }[];
    showProject?: boolean;
    readOnly?: boolean;
}

const props = withDefaults(defineProps<Props>(), {
    showProject: false,
    readOnly: false,
    statuses: () => [
        { value: "open", label: "Open", color: "blue" },
        { value: "in_progress", label: "In Progress", color: "indigo" },
        { value: "submitted", label: "Submitted", color: "purple" },
        { value: "in_qa", label: "In QA", color: "amber" },
        { value: "approved", label: "Approved", color: "teal" },
        { value: "sent_to_client", label: "Client Review", color: "rose" },
        { value: "completed", label: "Done", color: "emerald" },
    ],
});

const emit = defineEmits<{
    (e: "task-click", task: Task): void;
    (e: "task-moved", taskId: string, newStatus: string): void;
}>();

const draggingTask = ref<Task | null>(null);

const getTasksByStatus = (status: string) => {
    return props.tasks.filter((t) => {
        const tStatus = typeof t.status === 'object' && t.status !== null ? t.status.value : t.status;
        return tStatus === status;
    });
};

const getPriorityColor = (priority: string | number) => {
    const p = String(priority).toLowerCase();
    
    if (p === '4' || p === 'urgent') return "text-red-500 bg-red-50 dark:bg-red-500/10 border-red-200 dark:border-red-500/20";
    if (p === '3' || p === 'high') return "text-orange-500 bg-orange-50 dark:bg-orange-500/10 border-orange-200 dark:border-orange-500/20";
    if (p === '2' || p === 'medium') return "text-blue-500 bg-blue-50 dark:bg-blue-500/10 border-blue-200 dark:border-blue-500/20";
    
    // 1 or low
    return "text-gray-500 bg-gray-50 dark:bg-gray-500/10 border-gray-200 dark:border-gray-500/20";
};

const getPriorityLabel = (priority: string | number) => {
    const p = String(priority).toLowerCase();
    if (p === '4' || p === 'urgent') return 'Urgent';
    if (p === '3' || p === 'high') return 'High';
    if (p === '2' || p === 'medium') return 'Medium';
    return 'Low';
};

const formatDate = (dateString?: string) => {
    if (!dateString) return "";
    return new Date(dateString).toLocaleDateString(undefined, {
        month: "short",
        day: "numeric",
    });
};

// Drag & Drop
const onDragStart = (event: DragEvent, task: Task) => {
    draggingTask.value = task;
    event.dataTransfer!.effectAllowed = "move";
    event.dataTransfer!.dropEffect = "move";
    // Small delay to let the ghost image form before hiding the original element if desired
    // (Optional styles for dragging element can be applied via css classes)
};

const onDrop = (_event: DragEvent, status: string) => {
    if (draggingTask.value) {
        const currentStatus = typeof draggingTask.value.status === 'object' ? draggingTask.value.status.value : draggingTask.value.status;
        if (currentStatus !== status) {
            emit("task-moved", draggingTask.value.public_id, status);
        }
    }
    draggingTask.value = null;
};

const onDragOver = (event: DragEvent) => {
    event.preventDefault();
};
</script>

<template>
    <div class="h-full overflow-x-auto overflow-y-hidden">
        <div class="flex h-full gap-6 pb-4 min-w-full w-max px-1">
            <div
                v-for="status in statuses"
                :key="status.value"
                class="flex flex-col w-[320px] flex-shrink-0 max-h-full rounded-xl bg-[var(--surface-secondary)]/50 border border-[var(--border-subtle)]"
                @dragover="onDragOver"
                @drop="onDrop($event, status.value)"
            >
                <!-- Column Header -->
                <div
                    class="p-4 flex items-center justify-between flex-shrink-0 border-b border-[var(--border-subtle)] bg-[var(--surface-secondary)] rounded-t-xl"
                >
                    <div class="flex items-center gap-2.5">
                        <div
                            class="w-2.5 h-2.5 rounded-full ring-2 ring-offset-2 ring-offset-[var(--surface-secondary)]"
                            :class="`bg-${status.color}-500 ring-${status.color}-500/30`"
                        ></div>
                        <h3 class="font-semibold text-sm text-[var(--text-primary)]">
                            {{ status.label }}
                        </h3>
                        <Badge variant="secondary" size="sm" class="ml-1 px-1.5 min-w-[20px] justify-center">
                            {{ getTasksByStatus(status.value).length }}
                        </Badge>
                    </div>
                </div>

                <!-- Task List -->
                <div
                    class="p-3 flex-1 overflow-y-auto space-y-3 custom-scrollbar scroll-smooth"
                >
                    <div
                        v-for="task in getTasksByStatus(status.value)"
                        :key="task.public_id"
                        :draggable="!readOnly"
                        @dragstart="!readOnly && onDragStart($event, task)"
                        @click="emit('task-click', task)"
                        class="group relative bg-[var(--surface-primary)] p-4 rounded-xl shadow-sm border border-[var(--border-subtle)] hover:shadow-md hover:border-[var(--brand-primary)]/50 transition-all duration-200 cursor-pointer select-none"
                        :class="{
                            'opacity-50 rotate-2 scale-95 ring-2 ring-[var(--brand-primary)]':
                                draggingTask?.public_id === task.public_id,
                        }"
                    >
                        <!-- Card Header -->
                        <div class="flex items-start justify-between mb-3">
                            <div class="flex items-center gap-2">
                                <span class="text-[10px] font-mono text-[var(--text-muted)] group-hover:text-[var(--text-secondary)] transition-colors">
                                    #{{ task.public_id.substring(0, 4) }}
                                </span>
                                <Badge
                                    v-if="showProject && task.project"
                                    variant="outline"
                                    size="sm"
                                    class="text-[10px] h-5 px-1.5 max-w-[100px] truncate"
                                >
                                    {{ task.project.name }}
                                </Badge>
                            </div>
                            
                            <div 
                                class="w-1.5 h-1.5 rounded-full"
                                :class="getPriorityColor(task.priority).replace('bg-', 'bg-').replace('text-', 'bg-')"
                            ></div>
                        </div>

                        <!-- Title -->
                        <h4
                            class="text-sm font-semibold text-[var(--text-primary)] mb-3 leading-snug line-clamp-3 group-hover:text-[var(--brand-primary)] transition-colors"
                        >
                            {{ task.title }}
                        </h4>

                        <!-- Footer -->
                        <div class="flex items-center justify-between mt-auto pt-3 border-t border-[var(--border-subtle)]/50">
                            <!-- People -->
                            <div class="flex items-center -space-x-2">
                                <!-- Operator -->
                                <div
                                    @click.stop="emit('quick-assign', task)"
                                    class="cursor-pointer hover:scale-110 transition-transform relative z-10"
                                    title="Assign Operator"
                                >
                                    <Avatar
                                        v-if="task.assignee"
                                        :name="task.assignee.name"
                                        :src="task.assignee.avatar_url"
                                        size="xs"
                                        class="ring-2 ring-[var(--surface-primary)] w-6 h-6"
                                        title="Operator"
                                    />
                                    <div
                                        v-else
                                        class="w-6 h-6 rounded-full bg-[var(--surface-tertiary)] flex items-center justify-center text-[10px] text-[var(--text-muted)] ring-2 ring-[var(--surface-primary)] hover:bg-[var(--surface-elevated)] hover:text-[var(--text-primary)] transition-colors"
                                        title="Assign Operator"
                                    >
                                        <User class="w-3 h-3" />
                                    </div>
                                </div>

                                <!-- QA -->
                                <Avatar
                                    v-if="task.qa_user"
                                    :name="task.qa_user.name"
                                    :src="task.qa_user.avatar_url"
                                    size="xs"
                                    class="ring-2 ring-[var(--surface-primary)] w-6 h-6"
                                    title="QA"
                                />
                            </div>

                            <!-- Meta -->
                            <div class="flex items-center gap-3">
                                <!-- Priority Label -->
                                <span 
                                    class="text-[10px] font-medium px-1.5 py-0.5 rounded"
                                    :class="getPriorityColor(task.priority)"
                                >
                                    {{ getPriorityLabel(task.priority) }}
                                </span>

                                <!-- Date -->
                                <div
                                    v-if="task.due_date"
                                    class="flex items-center text-[10px] gap-1"
                                    :class="{
                                        'text-red-500 font-medium': new Date(task.due_date) < new Date(),
                                        'text-[var(--text-muted)]': new Date(task.due_date) >= new Date()
                                    }"
                                >
                                    <Calendar class="w-3 h-3" />
                                    {{ formatDate(task.due_date) }}
                                </div>
                            </div>
                        </div>
                    </div>

                    <!-- Empty State -->
                    <div
                        v-if="getTasksByStatus(status.value).length === 0"
                        class="flex flex-col items-center justify-center py-12 px-4 border-2 border-dashed border-[var(--border-subtle)] rounded-xl bg-[var(--surface-primary)]/30"
                    >
                        <div class="w-10 h-10 rounded-full bg-[var(--surface-tertiary)] flex items-center justify-center mb-2">
                            <span class="text-xl opacity-20">📋</span>
                        </div>
                        <span class="text-xs font-medium text-[var(--text-muted)]"
                            >No tasks in {{ status.label }}</span
                        >
                    </div>
                </div>
            </div>
        </div>
    </div>
</template>

<style scoped>
.custom-scrollbar::-webkit-scrollbar {
    width: 4px;
}
.custom-scrollbar::-webkit-scrollbar-track {
    background: transparent;
}
.custom-scrollbar::-webkit-scrollbar-thumb {
    background: var(--border-muted);
    border-radius: 4px;
}
.custom-scrollbar::-webkit-scrollbar-thumb:hover {
    background: var(--text-muted);
}
</style>
