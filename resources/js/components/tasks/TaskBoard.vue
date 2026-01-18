<script setup lang="ts">
import { ref } from "vue";
import { Badge, Avatar } from "@/components/ui";
import { Calendar } from "lucide-vue-next";

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
    created_at: string;
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
        { value: "in_qa", label: "In QA", color: "amber" },
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
    <div class="flex overflow-x-auto pb-4 gap-4 h-full min-h-[500px]">
        <div
            v-for="status in statuses"
            :key="status.value"
            class="flex-shrink-0 w-80 flex flex-col bg-[var(--surface-secondary)] rounded-xl"
            @dragover="onDragOver"
            @drop="onDrop($event, status.value)"
        >
            <!-- Column Header -->
            <div
                class="p-3 font-semibold text-sm flex items-center justify-between sticky top-0 bg-[var(--surface-secondary)] z-10 rounded-t-xl"
            >
                <div class="flex items-center gap-2">
                    <div
                        class="w-2 h-2 rounded-full"
                        :class="`bg-${status.color}-500`"
                    ></div>
                    <span class="text-[var(--text-secondary)]">{{
                        status.label
                    }}</span>
                    <span
                        class="px-2 py-0.5 rounded-full bg-[var(--surface-primary)] text-xs text-[var(--text-muted)]"
                    >
                        {{ getTasksByStatus(status.value).length }}
                    </span>
                </div>
            </div>

            <!-- Task List -->
            <div
                class="p-3 pt-0 flex-1 overflow-y-auto space-y-3 custom-scrollbar"
            >
                <div
                    v-for="task in getTasksByStatus(status.value)"
                    :key="task.public_id"
                    :draggable="!readOnly"
                    @dragstart="!readOnly && onDragStart($event, task)"
                    @click="emit('task-click', task)"
                    class="bg-[var(--surface-primary)] p-4 rounded-lg shadow-sm border border-[var(--border-subtle)] hover:shadow-md hover:border-[var(--border-default)] transition-all cursor-pointer group"
                    :class="{
                        'opacity-50 ring-2 ring-[var(--color-primary-500)]':
                            draggingTask?.public_id === task.public_id,
                    }"
                >
                    <div class="flex items-start justify-between mb-2">
                        <span
                            class="px-2 py-0.5 rounded text-[10px] font-medium border"
                            :class="getPriorityColor(task.priority)"
                        >
                            {{ getPriorityLabel(task.priority) }}
                        </span>

                        <div
                            v-if="task.due_date"
                            class="flex items-center text-[10px] text-[var(--text-muted)] gap-1"
                        >
                            <Calendar class="w-3 h-3" />
                            {{ formatDate(task.due_date) }}
                        </div>
                    </div>

                    <h4
                        class="text-sm font-medium text-[var(--text-primary)] mb-1 line-clamp-2 leading-relaxed"
                    >
                        {{ task.title }}
                    </h4>

                    <div
                        v-if="showProject && (task as any).project"
                        class="mb-2"
                    >
                        <Badge
                            variant="outline"
                            size="sm"
                            class="text-[10px] h-5 px-1.5 max-w-full truncate"
                        >
                            {{ (task as any).project.name }}
                        </Badge>
                    </div>

                    <div class="flex items-center justify-between mt-3">
                        <div class="flex items-center gap-1.5">
                            <Avatar
                                v-if="task.assignee"
                                :name="task.assignee.name"
                                :src="task.assignee.avatar_url"
                                size="xs"
                                class="ring-1 ring-[var(--surface-primary)]"
                            />
                            <div
                                v-else
                                class="w-5 h-5 rounded-full bg-[var(--surface-tertiary)] flex items-center justify-center text-[10px] text-[var(--text-muted)]"
                            >
                                ?
                            </div>
                        </div>
                        <div class="text-[10px] text-[var(--text-muted)]">
                            #{{ task.public_id.substring(0, 4) }}
                        </div>
                    </div>
                </div>

                <!-- Empty State -->
                <div
                    v-if="getTasksByStatus(status.value).length === 0"
                    class="flex items-center justify-center h-24 border-2 border-dashed border-[var(--border-subtle)] rounded-lg"
                >
                    <span class="text-xs text-[var(--text-muted)]"
                        >No tasks</span
                    >
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
